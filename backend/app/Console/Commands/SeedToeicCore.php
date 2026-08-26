<?php

namespace App\Console\Commands;

use App\Models\Word;
use App\Services\ClaudeService;
use App\Support\Lemma;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SeedToeicCore extends Command
{
    protected $signature = 'words:seed-toeic
                            {--count=1200 : How many ranked words to collect}
                            {--batch=60 : Words requested per API call}
                            {--fresh : Clear the existing toeic_core list first}';

    protected $description = 'Build the curated TOEIC high-frequency word list that new words are drawn from';

    /**
     * Reject the compound words the model starts inventing once it runs out of
     * genuine vocabulary — "outbound-based", "restock-able", "remittance-based".
     * Real hyphenated TOEIC words ("round-trip", "co-worker") still pass.
     */
    public static function looksLikeAWord(string $word): bool
    {
        if (! preg_match('/^[a-z]+(-[a-z]+)?$/', $word)) {
            return false;
        }
        if (strlen($word) < 3 || strlen($word) > 18) {
            return false;
        }

        // fabricated-compound signatures
        return ! preg_match('/-(based|able|driven|rate|time|slip|fee|bill|desk|planning)$/', $word);
    }

    public function handle(ClaudeService $claude): int
    {
        $lock = Cache::lock('words:seed-toeic', 3600);

        if (! $lock->get()) {
            $this->error('Another words:seed-toeic run is already in progress.');

            return self::FAILURE;
        }

        try {
            return $this->seed($claude);
        } finally {
            $lock->release();
        }
    }

    private function seed(ClaudeService $claude): int
    {
        if (! $claude->hasKey()) {
            $this->error('ANTHROPIC_API_KEY is not configured.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $cleared = Word::where('source', Word::SOURCE_TOEIC_CORE)->update([
                'source' => 'api',
                'frequency_rank' => null,
            ]);
            $this->warn("Cleared {$cleared} existing toeic_core entries.");
        }

        $target = (int) $this->option('count');
        $batchSize = (int) $this->option('batch');

        // Resume where a previous run stopped. Rank comes from the highest rank
        // on record rather than a row count: rows and ranks drift apart if a run
        // is interrupted, and reusing a rank silently creates duplicates.
        $collected = Word::toeicCore()->pluck('word')->all();
        $rank = (int) Word::where('source', Word::SOURCE_TOEIC_CORE)->max('frequency_rank');

        if ($rank >= $target) {
            $this->info("Already have {$rank} ranked words — nothing to do.");

            return self::SUCCESS;
        }

        // never rank a word whose base form is already in the list
        $stems = Lemma::index($collected);

        $bar = $this->output->createProgressBar($target - $rank);
        $bar->start();

        $emptyBatches = 0;

        while ($rank < $target && $emptyBatches < 3) {
            $batch = $claude->generateToeicCoreBatch($rank + 1, $batchSize, $collected);

            $added = 0;
            foreach ($batch as $entry) {
                if ($rank >= $target) {
                    break;
                }

                $word = trim(strtolower((string) ($entry['word'] ?? '')));
                if (! $this->looksLikeAWord($word) || Lemma::isKnown($word, $stems)) {
                    continue;
                }

                $rank++;

                // A word already in the cache may carry richer dictionary data
                // than a bulk list entry, so only fill blanks — ranking it is
                // the point here, not re-describing it.
                $model = Word::firstOrNew(['word' => $word]);
                foreach (['part_of_speech', 'definition_zh', 'category'] as $field) {
                    if (blank($model->$field)) {
                        $model->$field = $entry[$field] ?? null;
                    }
                }
                $model->source = Word::SOURCE_TOEIC_CORE;
                $model->frequency_rank = $rank;
                $model->save();

                $collected[] = $word;
                $stems += Lemma::index([$word]);
                $added++;
                $bar->advance();
            }

            $emptyBatches = $added === 0 ? $emptyBatches + 1 : 0;
        }

        $bar->finish();
        $this->newLine(2);

        if ($emptyBatches >= 3) {
            $this->warn('Stopped early: three consecutive batches returned nothing new.');
        }

        $this->info("TOEIC core list now holds {$rank} ranked words.");
        $this->line('Definitions are in place; run words:backfill-examples and words:backfill-mnemonics to fill the rest.');

        return self::SUCCESS;
    }
}
