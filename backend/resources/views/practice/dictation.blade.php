@extends('layouts.app')
@section('title', $set->title)

@section('content')
<div class="max-w-2xl mx-auto">
    <a href="{{ route('practice.index') }}" class="text-sm text-indigo-700 hover:underline">← All sets</a>
    <h1 class="text-2xl font-bold mt-1 mb-1">{{ $set->title }}</h1>
    <p class="text-sm text-slate-500 mb-5">Play each sentence as often as you like, type what you hear, then check. Capitals and punctuation are ignored. Nothing here is scored.</p>

    <div class="space-y-4">
        @foreach ($set->dictations as $d)
            <div class="bg-white rounded-xl border p-4" x-data="dictation(@js($d->transcript))">
                <div class="flex items-center gap-3 mb-2">
                    <span class="font-semibold text-indigo-600">{{ $d->number }}.</span>
                    <audio controls preload="none" class="flex-1 min-w-0" src="{{ route('practice.media', ['path' => $d->audio]) }}"></audio>
                </div>
                <textarea x-model="typed" rows="2" placeholder="Type what you hear…" autocapitalize="off" autocomplete="off" spellcheck="false"
                          class="w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300"></textarea>
                <div class="flex items-center gap-3 mt-2">
                    <button type="button" @click="check()" class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700">Check</button>
                    <button type="button" @click="reveal = !reveal" class="text-sm text-slate-500 hover:underline" x-text="reveal ? 'Hide answer' : 'Show answer'"></button>
                    <span x-show="score !== null" x-cloak class="text-sm" :class="score === 100 ? 'text-emerald-600 font-semibold' : 'text-slate-600'" x-text="score + '% of words'"></span>
                </div>
                <p x-show="diff.length" x-cloak class="mt-2 text-sm leading-7">
                    <template x-for="(w, i) in diff" :key="i">
                        <span>
                            <span :class="w.t === 'ok' ? 'text-emerald-700' : (w.t === 'miss' ? 'bg-amber-100 text-amber-800 px-0.5 rounded' : 'line-through text-rose-500')" x-text="w.w"></span>
                            <span> </span>
                        </span>
                    </template>
                </p>
                <p x-show="diff.length" x-cloak class="text-xs text-slate-400">green = correct · <span class="bg-amber-100 text-amber-800 px-0.5 rounded">highlighted</span> = missed · <span class="line-through text-rose-500">struck</span> = not in the sentence</p>
                <p x-show="reveal" x-cloak class="mt-2 text-sm text-slate-700 bg-slate-50 rounded-lg p-2">{{ $d->transcript }}</p>
            </div>
        @endforeach
    </div>
</div>

<script>
    function dictation(answer) {
        const words = s => s.toLowerCase().replace(/[’']/g, "'").replace(/[^a-z0-9'$%.\s-]/g, ' ').replace(/(?<!\d)\.|\.(?!\d)/g, ' ').split(/\s+/).filter(Boolean);
        return {
            typed: '', diff: [], score: null, reveal: false,
            check() {
                const a = words(answer), b = words(this.typed);
                // word-level LCS: keep the transcript's order, mark missed and extra words
                const dp = Array.from({ length: a.length + 1 }, () => new Array(b.length + 1).fill(0));
                for (let i = a.length - 1; i >= 0; i--)
                    for (let j = b.length - 1; j >= 0; j--)
                        dp[i][j] = a[i] === b[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
                const out = []; let i = 0, j = 0;
                while (i < a.length || j < b.length) {
                    if (i < a.length && j < b.length && a[i] === b[j]) { out.push({ w: a[i], t: 'ok' }); i++; j++; }
                    else if (j < b.length && (i >= a.length || dp[i][j + 1] >= dp[i + 1][j])) { out.push({ w: b[j], t: 'extra' }); j++; }
                    else { out.push({ w: a[i], t: 'miss' }); i++; }
                }
                this.diff = out;
                this.score = a.length ? Math.round(dp[0][0] / a.length * 100) : 0;
            },
        };
    }
</script>
@endsection
