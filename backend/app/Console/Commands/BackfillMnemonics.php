<?php

namespace App\Console\Commands;

use App\Models\UserWord;
use App\Models\Word;
use App\Services\ClaudeService;
use Illuminate\Console\Command;

class BackfillMnemonics extends Command
{
    protected $signature = 'words:backfill-mnemonics
                            {--limit=200 : Max words to generate mnemonics for}
                            {--chunk=20 : How many words per AI request}
                            {--user-id= : Only consider one user\'s library (default: all users)}';

    protected $description = 'Generate AI memory aids (mnemonics) for library words that have none';

    public function __construct(private ClaudeService $claude)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->claude->hasKey()) {
            $this->warn('ANTHROPIC_API_KEY is not set — skipping AI mnemonic generation.');
            return self::SUCCESS;
        }

        $userId = $this->option('user-id') ? (int) $this->option('user-id') : null;
        $limit = max(1, (int) $this->option('limit'));
        $chunkSize = max(1, (int) $this->option('chunk'));

        $libraryWordIds = UserWord::whereNotNull('word_id')
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->pluck('word_id')->unique();

        $missing = Word::whereIn('id', $libraryWordIds)
            ->where(fn ($q) => $q->whereNull('mnemonic')->orWhere('mnemonic', ''))
            ->limit($limit)
            ->get();

        if ($missing->isEmpty()) {
            $this->info('No library words are missing a mnemonic. 🎉');
            return self::SUCCESS;
        }

        $this->info("Generating mnemonics for {$missing->count()} word(s)...");
        $bar = $this->output->createProgressBar($missing->count());
        $bar->start();

        $filled = 0;
        foreach ($missing->chunk($chunkSize) as $chunk) {
            $items = $chunk->map(fn ($w) => [
                'word' => $w->word,
                'definition_zh' => $w->definition_zh,
            ])->all();
            $mnemonics = $this->claude->generateMnemonics($items);
            foreach ($chunk as $word) {
                $mnemonic = $mnemonics[strtolower($word->word)] ?? null;
                if ($mnemonic) {
                    $word->update(['mnemonic' => $mnemonic]);
                    $filled++;
                }
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Filled {$filled} mnemonic(s).");

        return self::SUCCESS;
    }
}
