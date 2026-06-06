<?php

namespace App\Console\Commands;

use App\Models\UserWord;
use App\Models\Word;
use App\Services\ClaudeService;
use App\Services\DictionaryService;
use Illuminate\Console\Command;

class BackfillExamples extends Command
{
    protected $signature = 'words:backfill-examples
                            {--limit=200 : Max words to generate examples for}
                            {--chunk=20 : How many words per AI request}';

    protected $description = 'Fill in missing example sentences for words in the vocabulary library';

    public function __construct(
        private DictionaryService $dictionary,
        private ClaudeService $claude,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // 1. Link orphaned vocabulary (no dictionary row) so their card has data.
        //    A dictionary lookup also pulls in an example when the API has one.
        $orphans = UserWord::whereNull('word_id')->get();
        if ($orphans->isNotEmpty()) {
            $this->info("Linking {$orphans->count()} orphaned word(s) to dictionary entries...");
            $linked = 0;
            foreach ($orphans as $uw) {
                $word = $this->dictionary->lookup($uw->word);
                if ($word) {
                    $uw->update(['word_id' => $word->id]);
                    $linked++;
                }
            }
            $this->line("  linked {$linked}");
        }

        // 2. Generate examples for library words still missing one.
        if (! $this->claude->hasKey()) {
            $this->warn('ANTHROPIC_API_KEY is not set — skipping AI example generation.');
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $chunkSize = max(1, (int) $this->option('chunk'));

        $libraryWordIds = UserWord::whereNotNull('word_id')->pluck('word_id')->unique();

        $missing = Word::whereIn('id', $libraryWordIds)
            ->where(fn ($q) => $q->whereNull('example')->orWhere('example', ''))
            ->limit($limit)
            ->get();

        if ($missing->isEmpty()) {
            $this->info('No library words are missing an example. 🎉');
            return self::SUCCESS;
        }

        $this->info("Generating examples for {$missing->count()} word(s)...");
        $bar = $this->output->createProgressBar($missing->count());
        $bar->start();

        $filled = 0;
        foreach ($missing->chunk($chunkSize) as $chunk) {
            $examples = $this->claude->generateExamples($chunk->pluck('word')->all());
            foreach ($chunk as $word) {
                $example = $examples[strtolower($word->word)] ?? null;
                if ($example) {
                    $word->update(['example' => $example]);
                    $filled++;
                }
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Filled {$filled} example(s).");

        return self::SUCCESS;
    }
}
