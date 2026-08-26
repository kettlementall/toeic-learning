<?php

namespace App\Console\Commands;

use App\Models\Word;
use App\Services\ClaudeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AuditToeicCore extends Command
{
    protected $signature = 'words:audit-toeic
                            {--batch=80 : Words verified per API call}
                            {--from-rank=1 : Only verify entries at or beyond this rank}
                            {--renumber-only : Skip verification, just close gaps in the ranking}
                            {--dry-run : Report what would be dropped without writing}';

    protected $description = 'Verify the TOEIC core list, drop entries that are not real high-frequency exam vocabulary, and re-rank what survives';

    public function handle(ClaudeService $claude): int
    {
        if (! $claude->hasKey()) {
            $this->error('ANTHROPIC_API_KEY is not configured.');

            return self::FAILURE;
        }

        $lock = Cache::lock('words:audit-toeic', 3600);
        if (! $lock->get()) {
            $this->error('Another words:audit-toeic run is already in progress.');

            return self::FAILURE;
        }

        try {
            return $this->audit($claude);
        } finally {
            $lock->release();
        }
    }

    private function audit(ClaudeService $claude): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('renumber-only')) {
            $n = $this->renumber();
            $this->info("Ranks rewritten as a contiguous 1-{$n}.");

            return self::SUCCESS;
        }

        // 1. cheap pass: entries that are not even well-formed words
        $malformed = Word::toeicCore()->get(['id', 'word'])
            ->reject(fn ($w) => SeedToeicCore::looksLikeAWord($w->word))
            ->values();

        $this->line("Malformed / fabricated entries: {$malformed->count()}");
        if ($malformed->isNotEmpty()) {
            $this->line('  ' . $malformed->pluck('word')->take(20)->implode(', ') . ($malformed->count() > 20 ? ' …' : ''));
            if (! $dryRun) {
                $this->demote($malformed->pluck('id')->all());
            }
        }

        // 2. model pass: is each survivor genuinely TOEIC vocabulary?
        $fromRank = (int) $this->option('from-rank');
        $candidates = Word::toeicCore()
            ->where('frequency_rank', '>=', $fromRank)
            ->get(['id', 'word', 'frequency_rank']);

        $scope = $fromRank > 1 ? " (rank {$fromRank}+)" : '';
        $this->line("Verifying {$candidates->count()} remaining entries{$scope}…");

        $drop = [];
        $chunks = $candidates->chunk((int) $this->option('batch'));
        $bar = $this->output->createProgressBar($chunks->count());
        $bar->start();

        foreach ($chunks as $chunk) {
            $verdicts = $claude->verifyToeicWords($chunk->pluck('word')->all());

            foreach ($chunk as $word) {
                $verdict = $verdicts[strtolower($word->word)] ?? null;
                // absent verdict = keep; never drop a word on a parsing failure
                if ($verdict === false) {
                    $drop[] = $word->id;
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $dropped = Word::whereIn('id', $drop)->pluck('word');
        $this->line("Not TOEIC high-frequency vocabulary: {$dropped->count()}");
        if ($dropped->isNotEmpty()) {
            $this->line('  ' . $dropped->take(30)->implode(', ') . ($dropped->count() > 30 ? ' …' : ''));
        }

        if ($dryRun) {
            $this->warn('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        $this->demote($drop);

        // 3. close the gaps left by everything that was dropped
        $renumbered = $this->renumber();
        $this->info("TOEIC core list now holds {$renumbered} verified words, ranked 1-{$renumbered}.");

        return self::SUCCESS;
    }

    /** @param  int[]  $ids */
    private function demote(array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            Word::whereIn('id', $chunk)->update([
                'source' => 'api',
                'frequency_rank' => null,
            ]);
        }
    }

    /**
     * Rewrite ranks as a contiguous 1..n run, preserving the existing order.
     *
     * The id list is materialised up front on purpose: chunking a query that is
     * ordered by the very column being rewritten makes rows shift between pages,
     * so they get visited twice or skipped entirely.
     */
    private function renumber(): int
    {
        $ids = Word::toeicCore()
            ->orderBy('frequency_rank')
            ->orderBy('id')
            ->pluck('id');

        $rank = 0;

        DB::transaction(function () use ($ids, &$rank) {
            foreach ($ids as $id) {
                Word::whereKey($id)->update(['frequency_rank' => ++$rank]);
            }
        });

        return $rank;
    }
}
