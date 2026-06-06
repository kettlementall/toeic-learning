@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
<h1 class="text-2xl font-bold mb-6">Learning Dashboard</h1>

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
    <div class="bg-white rounded-xl border p-4">
        <div class="text-3xl font-bold text-indigo-600">{{ $totalWords }}</div>
        <div class="text-sm text-slate-500 mt-1">Total Words</div>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <div class="text-3xl font-bold text-amber-500">{{ $dueCount }}</div>
        <div class="text-sm text-slate-500 mt-1">Due Today</div>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <div class="text-3xl font-bold text-emerald-600">{{ $totalQuizzes }}</div>
        <div class="text-sm text-slate-500 mt-1">Quizzes Completed</div>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <div class="text-3xl font-bold text-sky-600">{{ $avgAccuracy }}%</div>
        <div class="text-sm text-slate-500 mt-1">Avg. Accuracy</div>
    </div>
</div>

<div class="flex flex-wrap gap-3 mb-8">
    <a href="{{ route('words.index') }}" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">🔍 Look Up Words</a>
    @if ($dueCount > 0)
        <a href="{{ route('review.session') }}" class="px-4 py-2 bg-amber-500 text-white rounded-lg text-sm font-medium hover:bg-amber-600">📖 Start Review ({{ $dueCount }})</a>
    @endif
    <a href="{{ route('quiz.create') }}" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700">📝 Take a Quiz</a>
</div>

<div class="grid md:grid-cols-2 gap-6">
    {{-- Part 5 grammar weakness --}}
    <div class="bg-white rounded-xl border p-5">
        <h2 class="font-semibold mb-3">Part 5 Grammar Weakness Ranking</h2>
        @forelse ($grammarStats as $g)
            <div class="mb-2">
                <div class="flex justify-between text-sm mb-0.5">
                    <span>{{ $g['label'] }} <span class="text-slate-400">({{ $g['total'] }} questions)</span></span>
                    <span class="font-medium {{ $g['error_rate'] >= 50 ? 'text-red-600' : 'text-slate-600' }}">Error rate {{ $g['error_rate'] }}%</span>
                </div>
                <div class="h-2 bg-slate-100 rounded-full overflow-hidden">
                    <div class="h-full {{ $g['error_rate'] >= 50 ? 'bg-red-500' : 'bg-amber-400' }}" style="width: {{ $g['error_rate'] }}%"></div>
                </div>
            </div>
        @empty
            <p class="text-sm text-slate-400">No Part 5 records yet — take a grammar quiz to get started.</p>
        @endforelse
    </div>

    {{-- Vocab weakness by POS + recent trend --}}
    <div class="bg-white rounded-xl border p-5">
        <h2 class="font-semibold mb-3">Recent Quiz Scores</h2>
        @if ($recentQuizzes->count())
            <div class="flex items-end gap-1 h-32 mb-4">
                @foreach ($recentQuizzes as $q)
                    @php $pct = $q->total ? round($q->score / $q->total * 100) : 0; @endphp
                    <div class="flex-1 flex flex-col items-center justify-end h-full" title="{{ $q->title }}：{{ $q->score }}/{{ $q->total }}">
                        <div class="w-full bg-indigo-400 rounded-t" style="height: {{ max($pct, 3) }}%"></div>
                        <span class="text-[10px] text-slate-400 mt-1">{{ $pct }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-slate-400 mb-4">No quiz records yet.</p>
        @endif

        <h3 class="font-semibold text-sm mb-2 mt-4">Weakness by Part of Speech</h3>
        @forelse ($posStats as $p)
            <div class="flex justify-between text-sm py-0.5">
                <span class="text-slate-600">{{ $p['pos'] }}</span>
                <span class="{{ $p['error_rate'] >= 50 ? 'text-red-600' : 'text-slate-500' }}">{{ $p['error_rate'] }}%（{{ $p['total'] }}）</span>
            </div>
        @empty
            <p class="text-sm text-slate-400">No data yet.</p>
        @endforelse
    </div>
</div>

@if ($leeches->count())
    <div class="bg-white rounded-xl border p-5 mt-6">
        <h2 class="font-semibold mb-1">🔴 Stubborn Words <span class="text-sm font-normal text-slate-400">(missed again and again — need extra attention)</span></h2>
        <div class="flex flex-wrap gap-2 mt-3">
            @foreach ($leeches as $uw)
                <div class="flex items-center gap-2 bg-red-50 border border-red-200 rounded-lg px-3 py-1.5">
                    <span class="font-medium text-red-700">{{ $uw->word }}</span>
                    @if ($uw->dictionary?->definition_zh)
                        <span class="text-xs text-slate-500">{{ $uw->dictionary->definition_zh }}</span>
                    @endif
                    <span class="text-xs bg-red-200 text-red-800 px-1.5 rounded-full">missed {{ $uw->lapses }}×</span>
                </div>
            @endforeach
        </div>
        <a href="{{ route('review.session') }}" class="inline-block mt-3 text-sm text-indigo-600 hover:underline">→ Review these words</a>
    </div>
@endif

@if ($aiGrammarFocus->count())
    <div class="bg-white rounded-xl border p-5 mt-6">
        <h2 class="font-semibold mb-1">🤖 AI-Suggested Focus <span class="text-sm font-normal text-slate-400">(grammar points flagged in your latest review — already queued for your next adaptive quiz)</span></h2>
        <div class="flex flex-wrap gap-2 mt-3">
            @foreach ($aiGrammarFocus as $label)
                <span class="text-sm bg-indigo-50 border border-indigo-200 text-indigo-700 rounded-full px-3 py-1">{{ $label }}</span>
            @endforeach
        </div>
        <a href="{{ route('quiz.create') }}" class="inline-block mt-3 text-sm text-indigo-600 hover:underline">→ Create an adaptive Part 5 quiz</a>
    </div>
@endif
@endsection
