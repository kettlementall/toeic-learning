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

        return $this->persist($term, $dict, $cached);
    }

    /**
     * Look up several words at once, sharing a single Claude call for
     * whatever dictionaryapi.dev cannot serve.
     *
     * Looking words up one at a time is fine until the dictionary is down,
     * when each miss costs its own AI round trip: the quiz review page asks
     * for up to ten at once, which is a minute of waiting on one screen.
     *
     * @param  string[]  $terms
     * @return array<string,Word>  lowercased term => Word; words that could
     *                             not be resolved are absent
     */
    public function lookupMany(array $terms): array
    {
        $terms = array_values(array_unique(array_filter(
            array_map(fn ($t) => trim(strtolower((string) $t)), $terms)
        )));

        $found = [];
        $cachedBy = [];
        $resolved = [];   // term => normalized dictionary entry, ready to persist
        $unavailable = []; // the dictionary could not answer for these

        foreach ($terms as $term) {
            $cached = Word::where('word', $term)->first();
            $cachedBy[$term] = $cached;

            if ($cached && $cached->definition_en) {
                $found[$term] = $cached;

                continue;
            }

            $this->upstreamUnavailable = false;
            $dict = $this->fetchDictionary($term);

            if ($dict) {
                $resolved[$term] = $dict;
            } elseif ($this->upstreamUnavailable) {
                $unavailable[] = $term;
            } elseif ($cached) {
                $found[$term] = $cached; // a 404: genuinely not a word
            }
        }

        // one call for every word the dictionary could not serve
        foreach ($this->claude->defineWords($unavailable) as $term => $ai) {
            $entry = $this->claudeEntry($term, $ai);
            if ($entry) {
                $resolved[$term] = $entry;
            }
        }

        foreach ($resolved as $term => $dict) {
            $found[$term] = $this->persist($term, $dict, $cachedBy[$term] ?? null);
        }

        $this->upstreamUnavailable = false;

        return $found;
    }

    /**
     * Enrich a normalized dictionary entry and write it to the cache.
     */
    private function persist(string $term, array $dict, ?Word $cached): Word
    {
        // Claude enrich (Chinese per part of speech + TOEIC usage). The AI
        // fallback already carries the Chinese, so it skips the second call.
        $enrich = ($dict['enriched'] ?? false) ? $dict : $this->claude->enrich($term, $dict);

        // The senses the model chose for TOEIC replace the dictionary's own
        // first-per-part-of-speech picks. Without a usable choice (no key, a
        // failed call) the dictionary meanings are kept, untranslated, and the
        // word stays unchecked for words:fix-meanings to repair later.
        $chosen = ClaudeService::cleanMeanings($enrich['meanings'] ?? []);
        $meanings = $chosen ?: ($dict['meanings'] ?? []);
        $first = $meanings[0] ?? [];
        $primaryZh = $first['definition_zh'] ?? ($cached->definition_zh ?? null);

        $data = [
            'phonetic' => $dict['phonetic'] ?? null,
            'audio_url' => $dict['audio_url'] ?? null,
            'part_of_speech' => $first['pos'] ?? ($dict['part_of_speech'] ?? null),
            'definition_en' => $first['definition_en'] ?? ($dict['definition_en'] ?? null),
            'example' => ($first['example'] ?? null) ?: ($dict['example'] ?? null),
            'meanings' => $meanings,
            'senses_checked_at' => $chosen ? now() : null,
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

        // every sense across every homograph entry, for enrich() to choose from:
        // the first definition of the first entry is often the dialectal one
        $candidates = [];
        foreach ($json as $e) {
            foreach (($e['meanings'] ?? []) as $meaning) {
                foreach (array_slice($meaning['definitions'] ?? [], 0, 4) as $d) {
                    if (! empty($d['definition']) && count($candidates) < 24) {
                        $candidates[] = ['pos' => $meaning['partOfSpeech'] ?? null, 'definition_en' => $d['definition']];
                    }
                }
            }
        }

        return [
            'phonetic' => $phonetic,
            'audio_url' => $audio,
            'part_of_speech' => $first['pos'] ?? null,
            'definition_en' => $first['definition_en'] ?? null,
            'example' => $first['example'] ?? null,
            'meanings' => $meanings,
            'candidates' => $candidates,
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

        return $ai ? $this->claudeEntry($term, $ai) : null;
    }

    /**
     * Normalize one Claude-written entry into the fetchDictionary() shape.
     */
    private function claudeEntry(string $term, array $ai): ?array
    {
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
