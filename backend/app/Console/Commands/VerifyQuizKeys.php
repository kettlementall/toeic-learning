<?php

namespace App\Console\Commands;

use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Services\ClaudeService;
use Illuminate\Console\Command;

class VerifyQuizKeys extends Command
{
    protected $signature = 'quiz:verify-keys
                            {--type=* : Quiz types to check (default: all generated types)}
                            {--user-id= : Only one user\'s quizzes}
                            {--chunk=15 : Questions per AI request}
                            {--dry-run : Report without changing anything}';

    protected $description = 'Repair stored answer keys that contradict their own explanations';

    public function __construct(private ClaudeService $claude)
    {
        parent::__construct();
    }

    /**
     * Keys were once saved from a letter the model copied off the prompt
     * example while its explanation argued for another option. For each stored
     * question this asks only which option the explanation supports, and moves
     * the key there when it differs, then re-grades the learner's answer and
     * the quiz score. Schedules and logs written at submit are left as they
     * are: they cannot be traced back to a single question.
     */
    public function handle(): int
    {
        if (! $this->claude->hasKey()) {
            $this->warn('ANTHROPIC_API_KEY is not set — nothing checked.');
            return self::SUCCESS;
        }

        $types = $this->option('type') ?: ['vocab_mc', 'part5_grammar', 'fill_blank', 'news_reading'];
        $userId = $this->option('user-id') ? (int) $this->option('user-id') : null;
        $dry = (bool) $this->option('dry-run');

        $questions = QuizQuestion::whereHas('quiz', fn ($q) => $q
                ->whereIn('type', $types)
                ->when($userId, fn ($u) => $u->where('user_id', $userId)))
            ->whereNotNull('explanation')
            ->get();

        $this->info("Checking {$questions->count()} question(s)...");
        $bar = $this->output->createProgressBar($questions->count());
        $bar->start();

        $changed = collect();
        foreach ($questions->chunk(max(1, (int) $this->option('chunk'))) as $chunk) {
            $answers = $this->claude->explainedAnswers($chunk->map(fn ($q) => [
                'id' => $q->id,
                'question' => $q->question,
                'options' => $q->options,
                'explanation' => $q->explanation,
            ])->values()->all());

            foreach ($chunk as $q) {
                $said = $answers[$q->id] ?? null;
                $idx = $said === null ? false : array_search(
                    strtolower(trim($said)),
                    array_map(fn ($o) => strtolower(trim($o)), $q->options),
                    true,
                );
                $key = $idx === false ? null : chr(65 + $idx);

                if ($key !== null && $key !== $q->correct_answer) {
                    $changed->push([$q, $key]);
                }
                $bar->advance();
            }
        }
        $bar->finish();
        $this->newLine(2);

        foreach ($changed as [$q, $key]) {
            $was = $q->options[ord($q->correct_answer) - 65] ?? '?';
            $now = $q->options[ord($key) - 65];
            $this->line("#{$q->id} [{$q->quiz->type}] {$was} → {$now}" . ($q->user_answer ? " (answered {$q->user_answer})" : ''));

            if (! $dry) {
                $q->update([
                    'correct_answer' => $key,
                    'is_correct' => $q->user_answer ? $q->user_answer === $key : $q->is_correct,
                ]);
            }
        }

        if (! $dry) {
            Quiz::whereIn('id', $changed->map(fn ($c) => $c[0]->quiz_id)->unique())
                ->where('status', 'completed')
                ->get()
                ->each(fn ($quiz) => $quiz->update(['score' => $quiz->questions()->where('is_correct', true)->count()]));
        }

        $this->info(($dry ? 'Would repair' : 'Repaired') . " {$changed->count()} key(s).");

        return self::SUCCESS;
    }
}
