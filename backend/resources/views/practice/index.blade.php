@extends('layouts.app')
@section('title', 'Real-Test Practice')

@section('content')
@php
    $sectionLabels = ['reading' => 'Reading', 'listening' => 'Listening', 'dictation' => 'Dictation'];
    $partLabels = [1 => 'Part 1 · Photos', 2 => 'Part 2 · Q&A', 3 => 'Part 3 · Conversations', 4 => 'Part 4 · Talks',
                   5 => 'Part 5 · Sentences', 6 => 'Part 6 · Text completion', 7 => 'Part 7 · Reading'];
    $groups = $sets->groupBy(fn ($s) => $s->section . '|' . $s->source);
    $groupTitles = [
        'reading|zhuifen' => ['Mock tests · 追分解題班', 'Part 5 + 6, 46 questions each. Mock mode is timed at 18 minutes.'],
        'reading|lecture' => ['Lecture reading · W1–W5', 'Review exercises from the weekly handouts.'],
        'listening|lecture' => ['Lecture listening · W1–W5', 'Real recordings. Mock mode plays each track once, like the exam.'],
        'dictation|lecture' => ['Dictation', 'Listen, type what you hear, and compare with the transcript.'],
    ];
    $order = ['reading|zhuifen', 'reading|lecture', 'listening|lecture', 'dictation|lecture'];
@endphp

<div class="max-w-3xl mx-auto">
    <h1 class="text-2xl font-bold mb-1">Real-Test Practice</h1>
    <p class="text-sm text-slate-500 mb-5">Fixed questions from your course material. Kept separate from quizzes — nothing here changes your vocabulary review schedule or quiz stats.</p>

    @if ($unfinished->isNotEmpty())
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-5 text-sm">
            <p class="font-semibold text-amber-800 mb-2">Unfinished</p>
            <ul class="space-y-1">
                @foreach ($unfinished as $u)
                    <li class="flex items-center justify-between gap-3">
                        <span>{{ $u->set?->title ?? 'Retry: missed ' . $u->section }} <span class="text-amber-700">· {{ $u->mode }} · started {{ $u->started_at?->diffForHumans() }}</span></span>
                        <a href="{{ route('practice.show', $u) }}" class="shrink-0 text-indigo-700 font-medium hover:underline">Resume</a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($wrong['reading'] || $wrong['listening'])
        <div class="bg-white rounded-xl border p-4 mb-5 flex flex-wrap items-center gap-3">
            <div class="flex-1 min-w-[12rem]">
                <p class="font-semibold">Retry what you missed</p>
                <p class="text-xs text-slate-500">Questions whose latest answer was wrong, up to {{ config('practice.retry_max') }} at a time.</p>
            </div>
            @foreach (['reading', 'listening'] as $sec)
                @if ($wrong[$sec])
                    <form method="post" action="{{ route('practice.retry') }}">
                        @csrf
                        <input type="hidden" name="section" value="{{ $sec }}">
                        <button class="px-3 py-1.5 rounded-lg bg-rose-600 text-white text-sm font-medium hover:bg-rose-700">
                            {{ ucfirst($sec) }} · {{ $wrong[$sec] }}
                        </button>
                    </form>
                @endif
            @endforeach
        </div>
    @endif

    @if ($sets->isEmpty())
        <div class="bg-white rounded-xl border p-6 text-sm text-slate-500">No practice sets imported yet. Run <code>php artisan practice:import</code>.</div>
    @endif

    @foreach ($order as $key)
        @continue(! $groups->has($key))
        @php [$title, $sub] = $groupTitles[$key]; $section = explode('|', $key)[0]; @endphp
        <section class="mb-6">
            <h2 class="font-semibold">{{ $title }}</h2>
            <p class="text-xs text-slate-500 mb-2">{{ $sub }}</p>
            <div class="bg-white rounded-xl border divide-y">
                @foreach ($groups[$key] as $set)
                    @php $p = $progress[$set->id]; @endphp
                    <div class="p-3 sm:px-4 flex flex-wrap items-center gap-3">
                        <div class="flex-1 min-w-[12rem]">
                            <p class="font-medium">{{ $set->title }}</p>
                            <p class="text-xs text-slate-500">
                                {{ $section === 'dictation' ? $set->dictations_count . ' sentences' : $set->questions_count . ' questions' }}
                                @if ($p['count'])
                                    · last {{ $p['last']->score }}/{{ $p['last']->total }}
                                    · best {{ round($p['best'] * 100) }}%
                                    · {{ $p['count'] }} {{ \Illuminate\Support\Str::plural('sitting', $p['count']) }}
                                @endif
                            </p>
                        </div>
                        @if ($section === 'dictation')
                            <a href="{{ route('practice.dictation', $set) }}" class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700">Open</a>
                        @else
                            <form method="post" action="{{ route('practice.start', $set) }}" class="flex gap-2">
                                @csrf
                                <button name="mode" value="mock" class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700">
                                    Mock{{ $section === 'reading' && $set->time_limit_seconds ? ' · ' . intdiv($set->time_limit_seconds, 60) . ' min' : '' }}
                                </button>
                                <button name="mode" value="practice" class="px-3 py-1.5 rounded-lg border text-sm font-medium hover:bg-slate-50">Practice</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    @if ($stats['parts'])
        <section class="mb-6">
            <h2 class="font-semibold mb-2">Your accuracy</h2>
            <div class="grid sm:grid-cols-2 gap-4">
                <div class="bg-white rounded-xl border p-4">
                    <p class="text-xs font-semibold text-slate-500 mb-2">By part</p>
                    @foreach ($stats['parts'] as $part => $s)
                        <div class="flex items-center gap-2 text-sm py-0.5">
                            <span class="flex-1">{{ $partLabels[$part] ?? "Part $part" }}</span>
                            <span class="text-slate-400 text-xs">{{ $s['correct'] }}/{{ $s['total'] }}</span>
                            <span class="w-12 text-right font-semibold {{ $s['rate'] >= 80 ? 'text-emerald-600' : ($s['rate'] >= 60 ? 'text-amber-500' : 'text-rose-600') }}">{{ (int) $s['rate'] }}%</span>
                        </div>
                    @endforeach
                </div>
                <div class="bg-white rounded-xl border p-4">
                    <p class="text-xs font-semibold text-slate-500 mb-2">Weakest skills (3+ answers)</p>
                    @php $weak = collect($stats['skills'])->filter(fn ($s) => $s['total'] >= 3)->take(8); @endphp
                    @forelse ($weak as $s)
                        <div class="flex items-center gap-2 text-sm py-0.5">
                            <span class="flex-1">{{ $s['label'] }}</span>
                            <span class="text-slate-400 text-xs">{{ $s['correct'] }}/{{ $s['total'] }}</span>
                            <span class="w-12 text-right font-semibold {{ $s['rate'] >= 80 ? 'text-emerald-600' : ($s['rate'] >= 60 ? 'text-amber-500' : 'text-rose-600') }}">{{ (int) $s['rate'] }}%</span>
                        </div>
                    @empty
                        <p class="text-sm text-slate-400">Answer a few more questions to see this.</p>
                    @endforelse
                </div>
            </div>
        </section>
    @endif
</div>
@endsection
