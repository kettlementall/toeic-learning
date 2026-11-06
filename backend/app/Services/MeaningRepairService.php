<?php

namespace App\Services;

use App\Models\Word;
use Illuminate\Support\Collection;

/**
 * Rewrites stored words' meanings around their TOEIC senses (FR-4a). Shared by
 * words:fix-meanings and the flashcard request, so a word that entered the
 * library unchecked — from the curated list, a news article, an AI-generated
 * batch — is repaired the first time it is shown, without anyone running a
 * command.
 */
class MeaningRepairService
{
    /** Words per AI request: meanings carry examples, so batches stay small. */
    public const CHUNK = 10;

    public function __construct(private ClaudeService $claude)
    {
    }

    /**
     * Repair whichever of these words have not been checked. Words the model
     * skips stay unchecked for the next attempt.
     *
     * @param  Collection<int, Word>  $words
     * @return Collection<int, Word>  the words that were repaired
     */
    public function repair(Collection $words, int $chunk = self::CHUNK): Collection
    {
        $repaired = collect();

        foreach ($words->whereNull('senses_checked_at')->chunk(max(1, $chunk)) as $batch) {
            $meanings = $this->claude->toeicMeanings($batch->map(fn ($w) => [
                'word' => $w->word,
                'meanings' => $w->meanings ?: [[
                    'pos' => $w->part_of_speech,
                    'definition_en' => $w->definition_en ?: $w->definition_zh,
                ]],
            ])->values()->all());

            foreach ($batch as $word) {
                $new = $meanings[strtolower($word->word)] ?? null;
                if ($new) {
                    $first = $new[0];
                    $word->update([
                        'meanings' => $new,
                        'part_of_speech' => $first['pos'],
                        'definition_en' => $first['definition_en'],
                        'definition_zh' => $first['definition_zh'],
                        'example' => $first['example'] ?? $word->example,
                        'senses_checked_at' => now(),
                    ]);
                    $repaired->push($word);
                }
            }
        }

        return $repaired;
    }
}
