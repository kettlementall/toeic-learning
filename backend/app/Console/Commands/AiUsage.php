<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AiUsage extends Command
{
    protected $signature = 'ai:usage {--days=7 : How many days back to report}';

    protected $description = 'Claude API calls, tokens and estimated cost by purpose and model';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $prices = config('services.anthropic.prices', []);

        $rows = DB::table('ai_usages')
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('purpose', 'model')
            ->selectRaw('purpose, model, count(*) as calls, sum(input_tokens) as input, sum(output_tokens) as output')
            ->get();

        if ($rows->isEmpty()) {
            $this->info("No Claude calls recorded in the last {$days} day(s).");
            return self::SUCCESS;
        }

        $total = 0.0;
        $table = $rows->map(function ($r) use ($prices, &$total) {
            $cost = null;
            if (isset($prices[$r->model])) {
                [$in, $out] = $prices[$r->model];
                $cost = ($r->input * $in + $r->output * $out) / 1_000_000;
                $total += $cost;
            }

            return [
                'purpose' => $r->purpose,
                'model' => $r->model,
                'calls' => (int) $r->calls,
                'input' => number_format($r->input),
                'output' => number_format($r->output),
                'cost' => $cost === null ? '?' : '$' . number_format($cost, 3),
                '_sort' => $cost ?? 0,
            ];
        })->sortByDesc('_sort')->map(fn ($r) => array_diff_key($r, ['_sort' => 1]))->values();

        $this->info("Last {$days} day(s):");
        $this->table(['Purpose', 'Model', 'Calls', 'Input tok', 'Output tok', 'Est. cost'], $table->all());
        $this->line('Estimated total: $' . number_format($total, 3) . ' (list prices; models without a price are excluded)');

        return self::SUCCESS;
    }
}
