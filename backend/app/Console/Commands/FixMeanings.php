<?php

namespace App\Console\Commands;

use App\Models\UserWord;
use App\Models\Word;
use App\Services\ClaudeService;
use Illuminate\Console\Command;

class FixMeanings extends Command
{
    protected $signature = 'words:fix-meanings
                            {--limit=1000 : Max words to repair}
                            {--chunk=10 : How many words per AI request}
                            {--user-id= : Only consider one user\'s library (default: all users)}';

    protected $description = 'Rewrite library words\' meanings around their TOEIC senses';

    public function __construct(private ClaudeService $claude)
    {
        parent::__construct();
    }

    /**
     * Entries imported before enrich() chose senses carry the dictionary's
     * first definition per part of speech — often archaic or dialectal, and
     * shown on every flashcard. Each repaired word is stamped, so a rerun only
     * picks up what is left.
     */
    public function handle(): int
    {
        if (! $this->claude->hasKey()) {
            $this->warn('ANTHROPIC_API_KEY is not set — skipping meaning repair.');
            return self::SUCCESS;
        }

        $userId = $this->option('user-id') ? (int) $this->option('user-id') : null;
        $limit = max(1, (int) $this->option('limit'));
        $chunkSize = max(1, (int) $this->option('chunk'));

        $libraryWordIds = UserWord::whereNotNull('word_id')
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->pluck('word_id')->unique();

        $unchecked = Word::whereIn('id', $libraryWordIds)
            ->whereNull('senses_checked_at')
            ->limit($limit)
            ->get();

        if ($unchecked->isEmpty()) {
            $this->info('Every library word has been checked. 🎉');
            return self::SUCCESS;
        }

        $this->info("Repairing meanings for {$unchecked->count()} word(s)...");
        $bar = $this->output->createProgressBar($unchecked->count());
        $bar->start();

        $fixed = 0;
        foreach ($unchecked->chunk($chunkSize) as $chunk) {
            $meanings = $this->claude->toeicMeanings($chunk->map(fn ($w) => [
                'word' => $w->word,
                'meanings' => $w->meanings ?: [['pos' => $w->part_of_speech, 'definition_en' => $w->definition_en]],
            ])->values()->all());

            foreach ($chunk as $word) {
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
                    $fixed++;
                }
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Repaired {$fixed} of {$unchecked->count()} word(s).");

        return self::SUCCESS;
    }
}
