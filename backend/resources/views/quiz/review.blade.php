@extends('layouts.app')
@section('title', 'AI Review')

@section('content')
@php
    $review = $quiz->ai_review ?? [];
    $pct = $quiz->total ? round($quiz->score / $quiz->total * 100) : 0;
@endphp

<div class="max-w-2xl mx-auto">
    <h1 class="text-2xl font-bold mb-1">{{ $quiz->title }} — Review</h1>

    <div class="bg-white rounded-xl border p-5 mb-5 flex items-center gap-5">
        <div class="text-center">
            <div class="text-4xl font-bold {{ $pct >= 70 ? 'text-emerald-600' : ($pct >= 50 ? 'text-amber-500' : 'text-red-500') }}">{{ $pct }}%</div>
            <div class="text-sm text-slate-500">{{ $quiz->score }} / {{ $quiz->total }}</div>
        </div>
        <div class="flex-1 text-sm">
            @if (!empty($review['summary']))
                <p class="text-slate-700">{{ $review['summary'] }}</p>
            @endif
            @if (!empty($review['weaknesses']))
                <p class="text-red-600 mt-1">Weaknesses: {{ $review['weaknesses'] }}</p>
            @endif
        </div>
    </div>

    @if (!empty($review['review_words']) || !empty($review['review_grammar']))
        <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-4 mb-5 text-sm">
            @if (!empty($review['review_words']))
                <p class="mb-1"><span class="font-semibold text-indigo-700">Words to review:</span>
                    {{ implode(', ', (array) $review['review_words']) }}</p>
                <p class="text-xs text-indigo-500">↑ Automatically added to today's review queue.</p>
            @endif
            @if (!empty($review['review_grammar']))
                <p class="mt-2"><span class="font-semibold text-indigo-700">Grammar to strengthen:</span>
                    {{ implode(', ', (array) $review['review_grammar']) }}</p>
            @endif
        </div>
    @endif

    <h2 class="font-semibold mb-3">Question-by-Question Breakdown</h2>
    <div class="space-y-4">
        @foreach ($quiz->questions as $i => $q)
            <div class="bg-white rounded-xl border p-5">
                <div class="flex items-start gap-2">
                    <span class="{{ $q->is_correct ? 'text-emerald-600' : 'text-red-500' }} font-bold">
                        {{ $q->is_correct ? '✓' : '✗' }}
                    </span>
                    <div class="flex-1">
                        <p class="font-medium">{{ $i + 1 }}. {{ $q->question }}</p>
                        <div class="mt-2 space-y-1 text-sm">
                            @foreach ($q->options as $idx => $opt)
                                @php $letter = chr(65 + $idx); @endphp
                                <div class="flex items-center gap-2
                                    @if ($letter === $q->correct_answer) text-emerald-700 font-medium
                                    @elseif ($letter === $q->user_answer) text-red-600 line-through
                                    @else text-slate-500 @endif">
                                    <span class="w-5">{{ $letter }}</span><span>{{ $opt }}</span>
                                    @if ($letter === $q->correct_answer) <span class="text-xs">✓ Correct</span> @endif
                                    @if ($letter === $q->user_answer && $letter !== $q->correct_answer) <span class="text-xs">Your answer</span> @endif
                                </div>
                            @endforeach
                        </div>
                        @if ($q->explanation)
                            <div class="mt-2 text-sm text-slate-600 bg-slate-50 rounded-lg p-3">💡 {{ $q->explanation }}</div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="flex gap-3 mt-6">
        <a href="{{ route('quiz.create') }}" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm">Take Another</a>
        <a href="{{ route('review.session') }}" class="px-4 py-2 bg-amber-500 text-white rounded-lg text-sm">Review Weak Words</a>
        <a href="{{ route('dashboard') }}" class="px-4 py-2 bg-slate-200 rounded-lg text-sm">Back to Dashboard</a>
    </div>
</div>
@endsection
