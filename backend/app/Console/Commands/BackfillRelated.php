<?php

namespace App\Console\Commands;

use App\Models\UserWord;
use App\Models\Word;
use App\Services\ClaudeService;
use App\Services\RelatedWordsService;
use Illuminate\Console\Command;

class BackfillRelated extends Command
{
    protected $signature = 'words:backfill-related
                            {--limit=1000 : Max words to generate related words for}
                            {--user-id= : Only consider one user\'s library (default: all users)}';

    protected $description = 'Generate AI near-synonyms for library words that have never been asked';

    public function __construct(
        private ClaudeService $claude,
        private RelatedWordsService $related,
    ) {
        parent::__construct();
    }

    /**
     * Cards fill their own lists as they come up, but a word met only in
     * quizzes never does — and a missing list hides the pair from the other
     * side too, since the model is not symmetric. This covers every way a word
     * can enter the library.
     */
    public function handle(): int
    {
        if (! $this->claude->hasKey()) {
            $this->warn('ANTHROPIC_API_KEY is not set — skipping related-word generation.');
            return self::SUCCESS;
        }

        $userId = $this->option('user-id') ? (int) $this->option('user-id') : null;
        $limit = max(1, (int) $this->option('limit'));

        $libraryWordIds = UserWord::whereNotNull('word_id')
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->pluck('word_id')->unique();

        $missing = Word::whereIn('id', $libraryWordIds)
            ->whereNull('related')
            ->limit($limit)
            ->get();

        if ($missing->isEmpty()) {
            $this->info('No library words are missing related words. 🎉');
            return self::SUCCESS;
        }

        $this->info("Generating related words for {$missing->count()} word(s)...");
        $bar = $this->output->createProgressBar($missing->count());
        $bar->start();

        $filled = 0;
        foreach ($missing->chunk(RelatedWordsService::CHUNK) as $chunk) {
            $filled += $this->related->ensure($chunk);
            $bar->advance($chunk->count());
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Filled {$filled} of {$missing->count()} word(s).");

        return self::SUCCESS;
    }
}
