<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SpacedRepetitionService;
use Illuminate\Console\Command;

class SrsRebalance extends Command
{
    protected $signature = 'srs:rebalance
                            {--user= : Only rebalance this user id}
                            {--dry-run : Report what would move without writing}';

    protected $description = 'Spread overdue review cards beyond the daily capacity onto the next days that have room';

    public function handle(SpacedRepetitionService $srs): int
    {
        $capacity = (int) config('srs.daily_capacity');

        $users = User::when($this->option('user'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($users as $user) {
            $backlog = $srs->backlogCount($user->id);

            if ($backlog <= $capacity) {
                $this->line("{$user->name}: {$backlog} due, within capacity ({$capacity}) — nothing to do");

                continue;
            }

            if ($this->option('dry-run')) {
                $overflow = $backlog - $capacity;
                $days = (int) ceil($backlog / $capacity);
                $this->warn("{$user->name}: {$backlog} due — would move {$overflow} cards, draining over ~{$days} days");

                continue;
            }

            $moved = $srs->rebalance($user->id);
            $this->info("{$user->name}: {$backlog} due — moved {$moved} cards forward");
        }

        return self::SUCCESS;
    }
}
