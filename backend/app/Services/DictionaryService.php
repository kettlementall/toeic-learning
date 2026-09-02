<?php

namespace App\Services;

use App\Models\Word;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DictionaryService
{
    /**
     * True when the last lookup() came up empty because the dictionary API was
     * unreachable, rather than because the word doesn't exist. Lets callers tell
     * "no such word" apart from "service is down" instead of blaming spelling.
     */
    public bool $upstreamUnavailable = false;

    public function __construct(private ClaudeService $claude)
    {
    }

    /**
     * Look up a word: cache (words table) -> Free Dictionary API -> Claude enrich.
     * When the dictionary API is unreachable, falls back to a Claude-generated
     * entry so search keeps working. Returns the Word model (persisted).
     */
    public function lookup(string $term, bool $force = false): ?Word
    {
        $term = trim(strtolower($term));
        $this->upstreamUnavailable = false;

        if ($term === '') {
            return null;
        }

        // 1. cache hit (skip when forcing a refresh, e.g. backfilling meanings)
        $cached = Word::where('word', $term)->first();
        if (! $force && $cached && $cached->definition_en) {
            return $cached;
        }

        // 2. Free Dictionary API
        $dict = $this->fetchDictionary($term);

        // 2b. dictionary is down (not a 404) -> Claude writes the entry instead
        if (! $dict && $this->upstreamUnavailable) {
            $dict = $this->defineWithClaude($term);
            if ($dict) {
                $this->upstreamUnavailable = false;
            }
        }

        if (! $dict) {
            return $cached; // may be null
        }

        // 3. Claude enrich (Chinese per part of speech + TOEIC usage). The AI
        //    fallback already carries the Chinese, so it skips the second call.
        $enrich = ($dict['enriched'] ?? false) ? $dict : $this->claude->enrich($term, $dict);

        // zip the Chinese translations back onto each meaning (same order)
        $meanings = $dict['meanings'] ?? [];
        $zh = $enrich['meanings'] ?? [];
        foreach ($meanings as $i => $m) {
            $meanings[$i]['definition_zh'] = $zh[$i]['definition_zh'] ?? null;
        }
        $primaryZh = $meanings[0]['definition_zh'] ?? ($cached->definition_zh ?? null);

        $data = [
            'phonetic' => $dict['phonetic'] ?? null,
            'audio_url' => $dict['audio_url'] ?? null,
            'part_of_speech' => $dict['part_of_speech'] ?? null,
            'definition_en' => $dict['definition_en'] ?? null,
            'example' => $dict['example'] ?? null,
            'meanings' => $meanings,
            'synonyms' => $dict['synonyms'] ?? null,
            'definition_zh' => $primaryZh,
            'toeic_note' => $enrich['toeic_note'] ?? null,
            'mnemonic' => $enrich['mnemonic'] ?? ($cached->mnemonic ?? null),
            // keep whatever the word was first filed under (seed / toeic_core /
            // ai) so a search can't demote a curated word to a plain api entry
            'source' => $cached?->source ?: ($dict['source'] ?? 'api'),
            'raw_json' => $dict['raw'] ?? null,
        ];

        return Word::updateOrCreate(['word' => $term], $data);
    }

    /**
     * Call dictionaryapi.dev and normalize the response.
     *
     * A 404 means the word genuinely isn't in the dictionary; anything else
     * (timeout, connection refused, 5xx) means the service is unavailable and
     * sets $upstreamUnavailable so the caller can fall back or say so.
     */
    private function fetchDictionary(string $term): ?array
    {
        $base = config('services.dictionary.url');

        try {
            $resp = Http::connectTimeout(5)->timeout(10)->acceptJson()->get("{$base}/" . urlencode($term));
        } catch (\Throwable $e) {
            Log::warning("Dictionary API error for {$term}: " . $e->getMessage());
            $this->upstreamUnavailable = true;
            return null;
        }

        if ($resp->status() === 404) {
            return null; // no such word
        }

        if (! $resp->successful()) {
            Log::warning("Dictionary API error for {$term}: HTTP " . $resp->status());
            $this->upstreamUnavailable = true;
            return null;
        }

        $json = $resp->json();
        if (! is_array($json) || empty($json[0])) {
            return null;
        }

        $entry = $json[0];

        // phonetic + audio
        $phonetic = $entry['phonetic'] ?? null;
        $audio = null;
        foreach (($entry['phonetics'] ?? []) as $p) {
            if (! $phonetic && ! empty($p['text'])) {
                $phonetic = $p['text'];
            }
            if (! $audio && ! empty($p['audio'])) {
                $audio = $p['audio'];
            }
        }

        // one entry per part of speech (first definition + example of each)
        $meanings = [];
        $seenPos = [];
        $synonyms = [];
        foreach (($entry['meanings'] ?? []) as $meaning) {
            $mPos = $meaning['partOfSpeech'] ?? null;
            if ($mPos && in_array($mPos, $seenPos, true)) {
                continue; // already captured this part of speech
            }

            foreach (($meaning['synonyms'] ?? []) as $s) {
                $synonyms[] = $s;
            }

            $mDef = null;
            $mEx = null;
            foreach (($meaning['definitions'] ?? []) as $d) {
                if (! $mDef && ! empty($d['definition'])) {
                    $mDef = $d['definition'];
                }
                if (! $mEx && ! empty($d['example'])) {
                    $mEx = $d['example'];
                }
                if ($mDef && $mEx) {
                    break;
                }
            }

            if ($mDef) {
                $meanings[] = ['pos' => $mPos, 'definition_en' => $mDef, 'example' => $mEx];
                $seenPos[] = $mPos;
            }
        }

        // primary (scalar) fields mirror the first meaning for backward compatibility
        $first = $meanings[0] ?? [];

        return [
            'phonetic' => $phonetic,
            'audio_url' => $audio,
            'part_of_speech' => $first['pos'] ?? null,
            'definition_en' => $first['definition_en'] ?? null,
            'example' => $first['example'] ?? null,
            'meanings' => $meanings,
            'synonyms' => $synonyms ? implode(', ', array_slice(array_unique($synonyms), 0, 6)) : null,
            'raw' => $entry,
        ];
    }

    /**
     * Fallback entry written by Claude, used only when dictionaryapi.dev is
     * unreachable. Same normalized shape as fetchDictionary(), except the
     * Chinese is already filled in ('enriched') and there is no audio
     * recording — the UI falls back to browser text-to-speech for those.
     */
    private function defineWithClaude(string $term): ?array
    {
        $ai = $this->claude->defineWord($term);
        if (! $ai) {
            return null;
        }

        $meanings = [];
        foreach (($ai['meanings'] ?? []) as $m) {
            if (empty($m['definition_en'])) {
                continue;
            }
            $meanings[] = [
                'pos' => $m['pos'] ?? null,
                'definition_en' => $m['definition_en'],
                'definition_zh' => $m['definition_zh'] ?? null,
                'example' => $m['example'] ?? null,
            ];
        }

        if (! $meanings) {
            return null;
        }

        Log::info("Dictionary API unavailable, served {$term} from Claude");

        $first = $meanings[0];
        $synonyms = $ai['synonyms'] ?? null;
        if (is_array($synonyms)) {
            $synonyms = implode(', ', array_slice($synonyms, 0, 6));
        }

        return [
            'phonetic' => $ai['phonetic'] ?? null,
            'audio_url' => null,
            'part_of_speech' => $first['pos'],
            'definition_en' => $first['definition_en'],
            'example' => $first['example'],
            'meanings' => $meanings,
            'synonyms' => $synonyms ? mb_substr($synonyms, 0, 255) : null,
            'toeic_note' => $ai['toeic_note'] ?? null,
            'mnemonic' => $ai['mnemonic'] ?? null,
            'enriched' => true,
            'source' => 'ai',
            'raw' => null,
        ];
    }
}
