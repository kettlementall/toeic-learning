@extends('layouts.app')
@section('title', $attempt->set?->title ?? 'Retry')

@section('content')
@php
    $title = $attempt->set?->title ?? 'Retry · missed ' . $attempt->section . ' questions';
    $listening = $attempt->section === 'listening';
@endphp

<div class="max-w-3xl mx-auto"
     x-data="practiceTake({ remaining: @js($remaining), total: {{ $total }} })"
     x-init="init()">
    <div class="sticky top-14 z-10 -mx-4 px-4 py-2 bg-slate-50/95 backdrop-blur border-b mb-4 flex items-center gap-3"
         style="top: calc(3.5rem + env(safe-area-inset-top, 0px))">
        <div class="flex-1 min-w-0">
            <p class="font-semibold truncate">{{ $title }}</p>
            <p class="text-xs text-slate-500">{{ ucfirst($attempt->mode) }} · <span x-text="answered"></span>/{{ $total }} answered</p>
        </div>
        <template x-if="remaining !== null">
            <div class="text-lg font-mono font-semibold tabular-nums"
                 :class="remaining <= 60 ? 'text-rose-600' : 'text-slate-700'"
                 x-text="clock()"></div>
        </template>
    </div>

    <form method="post" action="{{ route('practice.submit', $attempt) }}" x-ref="form" @change="count()">
        @csrf
        <div class="space-y-5">
            @foreach ($blocks as $block)
                @php $g = $block['group']; @endphp
                <div class="bg-white rounded-xl border p-4 sm:p-5">
                    @if ($g && $g->body)
                        <div class="text-sm leading-relaxed text-slate-700 space-y-1 mb-4 pb-4 border-b">
                            @if ($g->label)<p class="text-xs font-semibold text-slate-400 mb-1">Questions {{ $g->label }}</p>@endif
                            {!! \App\Support\PracticeText::passage($g->body) !!}
                        </div>
                    @endif
                    @include('practice.partials.audio', ['group' => $g, 'mode' => $attempt->mode])

                    <div class="space-y-4">
                        @foreach ($block['questions'] as $q)
                            <div>
                                <p class="font-medium mb-2">
                                    <span class="text-indigo-600">{{ $q->number }}.</span>
                                    @if ($q->stem) {{ $q->stem }} @elseif ($q->part <= 2) <span class="text-slate-400 text-sm">Listen and choose.</span> @endif
                                </p>
                                <div class="grid gap-1.5 {{ $q->part === 6 || $q->part <= 2 ? 'sm:grid-cols-2' : '' }}">
                                    @foreach ($q->options as $idx => $opt)
                                        @php $letter = chr(65 + $idx); @endphp
                                        <label class="flex items-start gap-2 text-sm cursor-pointer rounded-lg border px-3 py-2 hover:bg-slate-50 has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50">
                                            <input type="radio" name="answers[{{ $q->id }}]" value="{{ $letter }}" class="mt-0.5 accent-indigo-600">
                                            <span class="font-semibold text-slate-500 w-4 shrink-0">{{ $letter }}</span>
                                            @if (! ($attempt->mode === 'mock' && $q->part <= 2))
                                                <span>{{ $opt }}</span>
                                            @endif
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            <p x-show="confirming" x-cloak class="mb-2 text-sm text-amber-700">
                <span x-text="total - answered"></span> question(s) unanswered — they will count as wrong. Press Submit again to finish.
            </p>
            <button type="button" @click="submit()" :disabled="submitting"
                    class="w-full py-3 bg-indigo-600 text-white rounded-lg font-medium hover:bg-indigo-700 disabled:opacity-50">
                <span x-show="!submitting">Submit</span>
                <span x-show="submitting" x-cloak>Grading…</span>
            </button>
        </div>
    </form>
</div>

<script>
    function practiceTake({ remaining, total }) {
        return {
            remaining, total, answered: 0, confirming: false, submitting: false, deadline: null,
            init() {
                this.count();
                if (this.remaining !== null) {
                    // count down against a fixed deadline so a backgrounded tab stays accurate
                    this.deadline = Date.now() + this.remaining * 1000;
                    const tick = () => {
                        this.remaining = Math.max(0, Math.round((this.deadline - Date.now()) / 1000));
                        if (this.remaining === 0) return this.finish();
                        setTimeout(tick, 500);
                    };
                    tick();
                }
            },
            count() {
                this.answered = this.$refs.form.querySelectorAll('input[type=radio]:checked').length;
                if (this.answered === this.total) this.confirming = false;
            },
            clock() {
                const m = Math.floor(this.remaining / 60), s = this.remaining % 60;
                return `${m}:${String(s).padStart(2, '0')}`;
            },
            submit() {
                if (this.answered < this.total && !this.confirming) { this.confirming = true; return; }
                this.finish();
            },
            finish() {
                if (this.submitting) return;
                this.submitting = true;
                this.$refs.form.submit();
            },
        };
    }
</script>
@endsection
