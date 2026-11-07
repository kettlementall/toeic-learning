@extends('layouts.app')
@section('title', 'Result')

@section('content')
@php
    $title = $attempt->set?->title ?? 'Retry · missed ' . $attempt->section . ' questions';
    $pct = $attempt->total ? round($attempt->score / $attempt->total * 100) : 0;
    $partLabels = [1 => 'Part 1', 2 => 'Part 2', 3 => 'Part 3', 4 => 'Part 4', 5 => 'Part 5', 6 => 'Part 6', 7 => 'Part 7'];
    $skills = config('practice.skills');
    $sourceNote = ['solved' => 'Answer set by independent solving (the handout gives no key)', 'supplement' => 'Answer as stated in the handout', 'handwriting' => 'Answer from the class notes'];
@endphp

<div class="max-w-3xl mx-auto" x-data="{ onlyWrong: false }">
    <h1 class="text-2xl font-bold mb-1">{{ $title }} — Result</h1>
    <p class="text-sm text-slate-500 mb-4">{{ ucfirst($attempt->mode) }} · {{ $attempt->completed_at?->format('m/d H:i') }}
        @if ($elapsed !== null) · {{ intdiv($elapsed, 60) }}m {{ $elapsed % 60 }}s @endif</p>

    <div class="bg-white rounded-xl border p-5 mb-5 flex flex-wrap items-center gap-5">
        <div class="text-center">
            <div class="text-4xl font-bold {{ $pct >= 80 ? 'text-emerald-600' : ($pct >= 60 ? 'text-amber-500' : 'text-rose-600') }}">{{ $pct }}%</div>
            <div class="text-sm text-slate-500">{{ $attempt->score }} / {{ $attempt->total }}</div>
        </div>
        <div class="flex-1 flex flex-wrap gap-2 text-sm">
            @foreach ($byPart as $part => $s)
                <span class="px-2.5 py-1 rounded-full bg-slate-100">{{ $partLabels[$part] }} · {{ $s['correct'] }}/{{ $s['total'] }}</span>
            @endforeach
        </div>
        <div class="flex gap-2">
            @if ($attempt->set)
                <form method="post" action="{{ route('practice.start', $attempt->set) }}">
                    @csrf
                    <button name="mode" value="{{ $attempt->mode === 'retry' ? 'practice' : $attempt->mode }}" class="px-3 py-1.5 rounded-lg border text-sm font-medium hover:bg-slate-50">Sit again</button>
                </form>
            @endif
            <a href="{{ route('practice.index') }}" class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700">All sets</a>
        </div>
    </div>

    <label class="inline-flex items-center gap-2 text-sm mb-3 cursor-pointer">
        <input type="checkbox" x-model="onlyWrong" class="accent-indigo-600"> Show only mistakes
    </label>

    <div class="space-y-5">
        @foreach ($blocks as $block)
            @php
                $g = $block['group'];
                $blockWrong = $block['questions']->contains(fn ($q) => ! ($answers->get($q->id)?->is_correct));
            @endphp
            <div class="bg-white rounded-xl border p-4 sm:p-5" x-show="!onlyWrong || {{ $blockWrong ? 'true' : 'false' }}">
                @if ($g && $g->body)
                    <details class="text-sm leading-relaxed text-slate-700 mb-4 pb-4 border-b" {{ $blockWrong ? 'open' : '' }}>
                        <summary class="text-xs font-semibold text-slate-400 cursor-pointer">Passage {{ $g->label ? '· ' . $g->label : '' }}</summary>
                        <div class="space-y-1 mt-2">{!! \App\Support\PracticeText::passage($g->body) !!}</div>
                    </details>
                @endif
                @include('practice.partials.audio', ['group' => $g, 'mode' => 'review'])
                @if ($g && $g->transcript)
                    <details class="text-sm mb-4">
                        <summary class="text-xs font-semibold text-slate-400 cursor-pointer">Transcript</summary>
                        <div class="mt-2 whitespace-pre-line text-slate-700 leading-relaxed">{{ $g->transcript }}</div>
                    </details>
                @endif

                <div class="space-y-5">
                    @foreach ($block['questions'] as $q)
                        @php $a = $answers->get($q->id); $ok = $a?->is_correct; @endphp
                        <div x-show="!onlyWrong || {{ $ok ? 'false' : 'true' }}" x-data="explainer({{ $q->id }}, @js($q->ai_explanation))">
                            <p class="font-medium mb-2 flex gap-2">
                                <span class="{{ $ok ? 'text-emerald-600' : 'text-rose-600' }}">{{ $ok ? '✓' : '✗' }}</span>
                                <span><span class="text-indigo-600">{{ $q->number }}.</span> {{ $q->stem }}</span>
                            </p>
                            <div class="grid gap-1.5 {{ $q->part === 6 || $q->part <= 2 ? 'sm:grid-cols-2' : '' }}">
                                @foreach ($q->options as $idx => $opt)
                                    @php
                                        $letter = chr(65 + $idx);
                                        $isAnswer = $letter === $q->answer;
                                        $isChoice = $a && $letter === $a->choice;
                                    @endphp
                                    <div class="flex items-start gap-2 text-sm rounded-lg border px-3 py-2
                                        {{ $isAnswer ? 'border-emerald-400 bg-emerald-50' : ($isChoice ? 'border-rose-300 bg-rose-50' : '') }}">
                                        <span class="font-semibold w-4 shrink-0 {{ $isAnswer ? 'text-emerald-700' : 'text-slate-500' }}">{{ $letter }}</span>
                                        <span class="flex-1">{{ $opt }}</span>
                                        @if ($isChoice)<span class="text-xs {{ $ok ? 'text-emerald-700' : 'text-rose-600' }}">your answer</span>@endif
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                @if (! $a?->choice)<span class="text-rose-600">Not answered.</span>@endif
                                @if ($q->skill)<span class="px-2 py-0.5 rounded-full bg-slate-100">{{ $skills[$q->skill] ?? $q->skill }}</span>@endif
                                @if (isset($sourceNote[$q->answer_source]))<span title="{{ $sourceNote[$q->answer_source] }}">ⓘ {{ $sourceNote[$q->answer_source] }}</span>@endif
                            </div>

                            @if ($q->explanation)
                                <details class="mt-2 text-sm" {{ $ok ? '' : 'open' }}>
                                    <summary class="text-xs font-semibold text-indigo-700 cursor-pointer">講義解說</summary>
                                    <div class="mt-1 whitespace-pre-line text-slate-700 bg-slate-50 rounded-lg p-3 leading-relaxed">{{ $q->explanation }}</div>
                                </details>
                            @endif

                            @if ($hasKey || $q->ai_explanation)
                                <div class="mt-2">
                                    <button type="button" x-show="!text" @click="load()" :disabled="loading"
                                            class="text-xs px-2.5 py-1 rounded-md border border-indigo-200 text-indigo-700 hover:bg-indigo-50 disabled:opacity-50">
                                        <span x-show="!loading">✨ AI 解說</span><span x-show="loading" x-cloak>產生中…</span>
                                    </button>
                                    <p x-show="error" x-cloak x-text="error" class="text-xs text-rose-600 mt-1"></p>
                                    <div x-show="text" x-cloak class="mt-1 text-sm whitespace-pre-line text-slate-700 bg-indigo-50/60 rounded-lg p-3 leading-relaxed">
                                        <p class="text-xs font-semibold text-indigo-700 mb-1">AI 解說</p><span x-text="text"></span>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>

<script>
    function explainer(id, cached) {
        return {
            text: cached, loading: false, error: null,
            async load() {
                this.loading = true; this.error = null;
                try {
                    const res = await fetch(`{{ url('/practice/questions') }}/${id}/explain`, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                    });
                    const data = await res.json();
                    if (!res.ok) throw new Error(data.error || 'Request failed');
                    this.text = data.explanation;
                } catch (e) {
                    this.error = e.message;
                } finally {
                    this.loading = false;
                }
            },
        };
    }
</script>
@endsection
