<?php

namespace App\Http\Controllers;

use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\ReviewLog;
use App\Models\UserWord;
use App\Services\CoreCoverageService;
use App\Services\QuizBuilderService;
use App\Services\ScoreEstimateService;
use App\Services\SpacedRepetitionService;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(
        private SpacedRepetitionService $srs,
        private ScoreEstimateService $estimator,
        private CoreCoverageService $coverage,
    ) {
    }

    public function index()
    {
        $userId = auth()->id();

        $totalWords = UserWord::forUser($userId)->count();
        $dueCount = $this->srs->backlogCount();
        $suspendedCount = UserWord::forUser($userId)->suspended()->count();
        $totalQuizzes = Quiz::where('user_id', $userId)->where('status', 'completed')->count();

        $completed = Quiz::where('user_id', $userId)->where('status', 'completed')->where('total', '>', 0)->get();
        $avgAccuracy = $completed->count()
            ? round($completed->avg(fn ($q) => $q->total ? $q->score / $q->total * 100 : 0), 1)
            : 0;

        // recent score trend (last 10)
        $recentQuizzes = Quiz::where('user_id', $userId)
            ->where('status', 'completed')
            ->latest('completed_at')
            ->limit(10)
            ->get()
            ->reverse()
            ->values();

        // grammar weakness ranking (Part 5), from the answers actually given.
        // Not review_logs: AI reviews once wrote a quality-1 row for every
        // point they flagged, indistinguishable from a wrong answer, which
        // turned a 31% error rate into 97%. Those rows are still in the table.
        $grammarStats = QuizQuestion::query()
            ->join('quizzes', 'quizzes.id', '=', 'quiz_questions.quiz_id')
            ->where('quizzes.user_id', $userId)
            ->where('quizzes.status', 'completed')
            ->whereNotNull('quiz_questions.grammar_point')
            ->whereNotNull('quiz_questions.is_correct')
            ->select('quiz_questions.grammar_point',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN quiz_questions.is_correct THEN 0 ELSE 1 END) as wrong'))
            ->groupBy('quiz_questions.grammar_point')
            ->get()
            ->map(fn ($r) => [
                'point' => $r->grammar_point,
                'label' => $this->grammarLabel($r->grammar_point),
                'total' => $r->total,
                'error_rate' => $r->total ? round($r->wrong / $r->total * 100) : 0,
            ])
            ->sortByDesc('error_rate')
            ->values();

        // vocab weakness by part of speech
        $posStats = ReviewLog::where('user_id', $userId)
            ->whereNotNull('part_of_speech')
            ->select('part_of_speech',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN quality < 3 THEN 1 ELSE 0 END) as wrong'))
            ->groupBy('part_of_speech')
            ->get()
            ->map(fn ($r) => [
                'pos' => $r->part_of_speech,
                'total' => $r->total,
                'error_rate' => $r->total ? round($r->wrong / $r->total * 100) : 0,
            ])
            ->sortByDesc('error_rate')
            ->values();

        // leeches: chronically-failed words
        $leeches = UserWord::forUser($userId)
            ->leeches()
            ->active()
            ->with('dictionary')
            ->orderByDesc('lapses')
            ->limit(10)
            ->get();

        // latest AI-recommended grammar focus (most recent quiz that has one)
        $aiGrammarFocus = Quiz::where('user_id', $userId)
            ->where('status', 'completed')
            ->whereNotNull('ai_review')
            ->latest('completed_at')
            ->limit(8)
            ->get()
            ->map(fn ($q) => $q->ai_review['review_grammar'] ?? [])
            ->first(fn ($g) => ! empty($g)) ?? [];
        $aiGrammarFocus = collect($aiGrammarFocus)
            ->map(fn ($p) => $this->grammarLabel($p))
            ->unique()
            ->values();

        // rough Reading score from real quiz answers
        $scoreEstimate = $this->estimator->estimate($userId);

        // progress through the curated TOEIC list
        $coreCoverage = $this->coverage->forUser($userId);

        return view('dashboard', compact(
            'totalWords', 'dueCount', 'suspendedCount',
            'totalQuizzes', 'avgAccuracy',
            'recentQuizzes', 'grammarStats', 'posStats', 'leeches', 'aiGrammarFocus',
            'scoreEstimate', 'coreCoverage'
        ));
    }

    private function grammarLabel(string $point): string
    {
        return [
            'word_form' => 'Word Form',
            'tense_voice' => 'Tense & Voice',
            'preposition' => 'Preposition',
            'conjunction' => 'Conjunction',
            'pronoun' => 'Pronoun',
            'relative' => 'Relative Clause',
            'comparison' => 'Comparison',
            'agreement' => 'Subject-Verb Agreement',
            'collocation' => 'Collocation',
            'vocab_in_context' => 'Vocabulary in Context',
        ][$point] ?? $point;
    }
}
