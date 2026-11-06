<?php

namespace App\Console\Commands;

use App\Models\UserWord;
use App\Models\Word;
use App\Services\ClaudeService;
use App\Services\MeaningRepairService;
use Illuminate\Console\Command;

class FixMeanings extends Command
{
    protected $signature = 'words:fix-meanings
                            {--limit=2000 : Max words to repair}
                            {--chunk=10 : How many words per AI request}
                            {--user-id= : Only consider one user\'s library (default: all users)}
                            {--catalog : Every cached word, not only library words}';

    protected $description = 'Rewrite words\' meanings around their TOEIC senses';

    public function __construct(
        private ClaudeService $claude,
        private MeaningRepairService $repair,
    ) {
        parent::__construct();
    }

    /**
     * Entries imported before enrich() chose senses carry the dictionary's
     * first definition per part of speech — often archaic or dialectal, and
     * shown on every flashcard. `--catalog` also covers words not yet in any
     * library, chiefly the curated list new words are drawn from. Each repaired
     * word is stamped, so a rerun only picks up what is left.
     */
    public function handle(): int
    {
        if (! $this->claude->hasKey()) {
            $this->warn('ANTHROPIC_API_KEY is not set — skipping meaning repair.');
            return self::SUCCESS;
        }

        $userId = $this->option('user-id') ? (int) $this->option('user-id') : null;

        $unchecked = Word::whereNull('senses_checked_at')
            ->unless($this->option('catalog'), fn ($q) => $q->whereIn('id', UserWord::whereNotNull('word_id')
                ->when($userId, fn ($u) => $u->where('user_id', $userId))
                ->select('word_id')))
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($unchecked->isEmpty()) {
            $this->info('Every word in scope has been checked. 🎉');
            return self::SUCCESS;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $this->info("Repairing meanings for {$unchecked->count()} word(s)...");
        $bar = $this->output->createProgressBar($unchecked->count());
        $bar->start();

        $fixed = 0;
        foreach ($unchecked->chunk($chunk) as $batch) {
            $fixed += $this->repair->repair($batch, $chunk)->count();
            $bar->advance($batch->count());
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Repaired {$fixed} of {$unchecked->count()} word(s).");

        return self::SUCCESS;
    }
}
