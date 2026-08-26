<?php

namespace App\Http\Controllers;

use App\Models\Quiz;
use App\Models\ReviewLog;
use App\Models\UserWord;
use App\Services\QuizBuilderService;
use App\Services\SpacedRepetitionService;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(private SpacedRepetitionService $srs)
    {
    }

    public function index()
    {
        $userId = auth()->id();

        $totalWords = UserWord::forUser($userId)->count();
        $dueCount = $this->srs->dueCount();
        $backlogCount = $this->srs->backlogCount($userId);
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

        // grammar weakness ranking (Part 5)
        $grammarStats = ReviewLog::where('user_id', $userId)
            ->whereNotNull('grammar_point')
            ->select('grammar_point',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN quality < 3 THEN 1 ELSE 0 END) as wrong'))
            ->groupBy('grammar_point')
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

        return view('dashboard', compact(
            'totalWords', 'dueCount', 'backlogCount', 'suspendedCount',
            'totalQuizzes', 'avgAccuracy',
            'recentQuizzes', 'grammarStats', 'posStats', 'leeches', 'aiGrammarFocus'
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
