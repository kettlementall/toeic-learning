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
        <div class="text-sm text-slate-500 mt-1">Ready to Review</div>
        <div class="text-xs text-slate-400 mt-0.5">
            Your quizzes work through these, most urgent first.
        </div>
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

{{-- rough TOEIC Reading estimate --}}
@php
    $est = $scoreEstimate;
    $c = $est['components'];
    $pct = fn ($p) => $p === null ? '—' : round($p * 100).'%';
    $hintLabel = [
        'news_reading' => 'a News Reading (Part 7) quiz',
        'part5_grammar' => 'a Part 5 Grammar quiz',
        'vocab_mc' => 'a Vocabulary quiz',
    ][$est['hint']] ?? null;
    $confStyle = [
        'high' => 'bg-emerald-100 text-emerald-700',
        'medium' => 'bg-amber-100 text-amber-700',
        'low' => 'bg-slate-100 text-slate-600',
    ][$est['confidence'] ?? 'low'];
@endphp
<div class="bg-white rounded-xl border p-5 mb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="font-semibold">📈 Estimated TOEIC Reading Score</h2>
        @if ($est['status'] === 'ok')
            <span class="text-xs px-2 py-0.5 rounded-full {{ $confStyle }}">{{ ucfirst($est['confidence']) }} confidence</span>
        @endif
    </div>

    @if ($est['status'] === 'ok')
        <div class="mt-2">
            <span class="text-4xl font-bold text-indigo-600">{{ $est['score'] }}</span>
            <span class="text-slate-400">/ 495</span>
            <span class="text-sm text-slate-500 ml-2">likely {{ $est['low'] }}–{{ $est['high'] }}</span>
        </div>
    @else
        <p class="text-sm text-slate-500 mt-2">Not enough answers yet to estimate a score.</p>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-4 text-sm">
        <div class="bg-slate-50 rounded-lg px-3 py-2">
            <div class="text-slate-500">Part 5 grammar</div>
            <div class="font-medium">{{ $pct($c['p5']) }} <span class="text-xs text-slate-400">({{ $c['n5'] }} questions)</span></div>
        </div>
        <div class="bg-slate-50 rounded-lg px-3 py-2">
            <div class="text-slate-500">Part 7 reading</div>
            <div class="font-medium">{{ $pct($c['p7']) }} <span class="text-xs text-slate-400">({{ $c['n7'] }} questions)</span></div>
        </div>
        <div class="bg-slate-50 rounded-lg px-3 py-2">
            <div class="text-slate-500">New words already known</div>
            <div class="font-medium">{{ $pct($c['pv']) }} <span class="text-xs text-slate-400">({{ $c['nv'] }} words)</span></div>
        </div>
    </div>

    @if ($hintLabel)
        <p class="text-sm text-slate-500 mt-3">
            To sharpen the estimate, take <a href="{{ route('quiz.create') }}" class="text-indigo-600 hover:underline">{{ $hintLabel }}</a>.
        </p>
    @endif
    <p class="text-xs text-slate-400 mt-2">
        A rough guess from your practice answers over the last {{ config('toeic_estimate.window_days') }} days, not an official score.
        Listening is not included — nothing here tests it.
    </p>
</div>

<div class="flex flex-wrap gap-3 mb-8">
    <a href="{{ route('words.index') }}" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">🔍 Look Up Words</a>
    <a href="{{ route('quiz.create') }}" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700">📝 Take a Quiz</a>
    <a href="{{ route('review.session') }}" class="px-4 py-2 text-sm text-slate-500 hover:text-slate-700">Flashcard review (optional)</a>
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
        <p class="text-xs text-slate-400 mt-3">Your quizzes already prioritise these — no extra drilling needed.</p>
    </div>
@endif

@if ($suspendedCount > 0)
    <div class="bg-white rounded-xl border p-5 mt-6">
        <h2 class="font-semibold mb-1">⏸ Paused Words
            <span class="text-sm font-normal text-slate-400">({{ $suspendedCount }} words taken out of the rotation after failing too often)</span>
        </h2>
        <p class="text-sm text-slate-500 mt-2">
            Drilling these with flashcards has stopped working. Learn them deliberately —
            look up the word origin, write your own sentence, add a mnemonic — then put them back one at a time.
        </p>
        <a href="{{ route('vocabulary.index') }}" class="inline-block mt-3 text-sm text-indigo-600 hover:underline">→ Open the paused list</a>
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
