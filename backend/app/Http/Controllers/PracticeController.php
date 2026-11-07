<?php

namespace App\Http\Controllers;

use App\Models\PracticeAnswer;
use App\Models\PracticeAttempt;
use App\Models\PracticeQuestion;
use App\Models\PracticeSet;
use App\Services\ClaudeService;
use App\Services\PracticeService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The real-test practice bank: list sets, sit them (timed mock or untimed
 * practice), grade, review with the handout's explanation or an on-demand AI
 * one, and retry what was missed. Touches only the practice_* tables.
 */
class PracticeController extends Controller
{
    public function __construct(
        private PracticeService $practice,
        private ClaudeService $claude,
    ) {
    }

    public function index()
    {
        $userId = auth()->id();
        $sets = PracticeSet::withCount(['questions', 'dictations'])->orderBy('section')->orderBy('sort')->get();

        $attempts = PracticeAttempt::where('user_id', $userId)
            ->where('status', 'completed')
            ->whereNotNull('set_id')
            ->orderByDesc('completed_at')
            ->get()
            ->groupBy('set_id');

        $progress = $sets->mapWithKeys(fn ($s) => [$s->id => [
            'last' => $attempts->get($s->id)?->first(),
            'best' => $attempts->get($s->id)?->max(fn ($a) => $a->total ? $a->score / $a->total : 0),
            'count' => $attempts->get($s->id)?->count() ?? 0,
        ]]);

        $unfinished = PracticeAttempt::where('user_id', $userId)->where('status', 'in_progress')
            ->latest()->limit(5)->with('set')->get();

        return view('practice.index', [
            'sets' => $sets,
            'progress' => $progress,
            'unfinished' => $unfinished,
            'wrong' => [
                'reading' => count($this->practice->wrongQuestionIds($userId, 'reading')),
                'listening' => count($this->practice->wrongQuestionIds($userId, 'listening')),
            ],
            'stats' => $this->practice->stats($userId),
        ]);
    }

    public function start(Request $request, PracticeSet $set)
    {
        $data = $request->validate(['mode' => 'required|in:mock,practice']);
        abort_if($set->section === 'dictation', 404);

        $attempt = $this->practice->startAttempt(auth()->id(), $set, $data['mode']);

        return redirect()->route('practice.show', $attempt);
    }

    public function retry(Request $request)
    {
        $data = $request->validate(['section' => 'required|in:reading,listening']);
        $attempt = $this->practice->startRetry(auth()->id(), $data['section']);

        if (! $attempt) {
            return back()->with('status', 'Nothing to retry — every question you have missed was answered correctly since.');
        }

        return redirect()->route('practice.show', $attempt);
    }

    public function show(PracticeAttempt $attempt)
    {
        $this->authorizeOwner($attempt);
        if ($attempt->isCompleted()) {
            return redirect()->route('practice.result', $attempt);
        }

        $questions = $attempt->orderedQuestions();
        $remaining = null;
        if ($attempt->time_limit_seconds) {
            $remaining = max(0, $attempt->time_limit_seconds - (int) $attempt->started_at->diffInSeconds(now()));
        }

        $blocks = $this->blocks($questions);

        return view('practice.take', [
            'attempt' => $attempt->load('set'),
            'blocks' => $blocks,
            'total' => $questions->count(),
            'remaining' => $remaining,
            'playlist' => $attempt->mode === 'mock' && $attempt->section === 'listening' ? $this->playlist($blocks) : null,
        ]);
    }

    public function submit(Request $request, PracticeAttempt $attempt)
    {
        $this->authorizeOwner($attempt);
        $this->practice->grade($attempt, (array) $request->input('answers', []));

        return redirect()->route('practice.result', $attempt);
    }

    public function result(PracticeAttempt $attempt)
    {
        $this->authorizeOwner($attempt);
        if (! $attempt->isCompleted()) {
            return redirect()->route('practice.show', $attempt);
        }

        $questions = $attempt->orderedQuestions();
        $answers = PracticeAnswer::where('attempt_id', $attempt->id)->get()->keyBy('question_id');

        $byPart = $questions->groupBy('part')->map(fn ($qs) => [
            'total' => $qs->count(),
            'correct' => $qs->filter(fn ($q) => $answers->get($q->id)?->is_correct)->count(),
        ])->sortKeys();

        return view('practice.result', [
            'attempt' => $attempt->load('set'),
            'blocks' => $this->blocks($questions),
            'answers' => $answers,
            'byPart' => $byPart,
            'hasKey' => $this->claude->hasKey(),
            'elapsed' => $attempt->started_at && $attempt->completed_at
                ? (int) $attempt->started_at->diffInSeconds($attempt->completed_at) : null,
        ]);
    }

    /** Dictation: listen, type, compare with the transcript (checked in the browser, not stored). */
    public function dictation(PracticeSet $set)
    {
        abort_if($set->section !== 'dictation', 404);

        return view('practice.dictation', ['set' => $set->load('dictations')]);
    }

    /** Generate (once) and return a Chinese explanation for a question. */
    public function explain(PracticeQuestion $question)
    {
        if ($question->ai_explanation) {
            return response()->json(['explanation' => $question->ai_explanation]);
        }
        if (! $this->claude->hasKey()) {
            return response()->json(['error' => 'AI explanations are unavailable (no API key).'], 503);
        }

        $text = $this->claude->explainPracticeQuestion($question->load('group'));
        if (! $text) {
            return response()->json(['error' => 'Could not generate an explanation. Please try again.'], 502);
        }

        $question->update(['ai_explanation' => $text, 'ai_model' => config('services.anthropic.model')]);

        return response()->json(['explanation' => $text]);
    }

    /**
     * Consecutive questions sharing a group (passage / conversation) render
     * together under that group; ungrouped ones stand alone.
     *
     * @return Collection<int, array{group: ?\App\Models\PracticeGroup, questions: Collection}>
     */
    private function blocks(Collection $questions): Collection
    {
        $blocks = collect();
        foreach ($questions as $q) {
            $last = $blocks->last();
            if ($q->group_id && $last && $last['group']?->id === $q->group_id) {
                $last['questions']->push($q);
                $blocks->put($blocks->count() - 1, $last);
                continue;
            }
            $blocks->push(['group' => $q->group, 'questions' => collect([$q])]);
        }

        return $blocks;
    }

    /**
     * The listening mock's running order: each block's tracks, then an answer
     * window sized like the exam's (per question, by part).
     */
    private function playlist(Collection $blocks): array
    {
        $gaps = config('practice.answer_gap_seconds');

        return $blocks->map(fn ($b) => [
            'tracks' => collect($b['group']?->audio ?? [])->map(fn ($t) => route('practice.media', ['path' => $t]))->all(),
            'gap' => $b['questions']->sum(fn ($q) => $gaps[$q->part] ?? 8),
            'label' => $b['questions']->count() > 1
                ? $b['questions']->first()->number . '–' . $b['questions']->last()->number
                : (string) $b['questions']->first()->number,
        ])->values()->all();
    }

    private function authorizeOwner(PracticeAttempt $attempt): void
    {
        abort_if($attempt->user_id !== auth()->id(), 403);
    }
}
