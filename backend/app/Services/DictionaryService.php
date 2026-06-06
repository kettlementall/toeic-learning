<?php

namespace App\Services;

use App\Models\Word;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DictionaryService
{
    public function __construct(private ClaudeService $claude)
    {
    }

    /**
     * Look up a word: cache (words table) -> Free Dictionary API -> Claude enrich.
     * Returns the Word model (persisted).
     */
    public function lookup(string $term, bool $force = false): ?Word
    {
        $term = trim(strtolower($term));
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
        if (! $dict) {
            return $cached; // may be null
        }

        // 3. Claude enrich (Chinese per part of speech + TOEIC usage)
        $enrich = $this->claude->enrich($term, $dict);

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
            'source' => $cached?->source === 'seed' ? 'seed' : 'api',
            'raw_json' => $dict['raw'] ?? null,
        ];

        return Word::updateOrCreate(['word' => $term], $data);
    }

    /**
     * Call dictionaryapi.dev and normalize the response.
     */
    private function fetchDictionary(string $term): ?array
    {
        $base = config('services.dictionary.url');

        try {
            $resp = Http::timeout(15)->acceptJson()->get("{$base}/" . urlencode($term));
        } catch (\Throwable $e) {
            Log::warning("Dictionary API error for {$term}: " . $e->getMessage());
            return null;
        }

        if (! $resp->successful()) {
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
}
