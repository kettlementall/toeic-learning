@extends('layouts.app')
@section('title', 'Daily Review')

@section('content')
@php
    $cards = $words->map(fn ($uw) => [
        'id' => $uw->id,
        'word' => $uw->word,
        'phonetic' => $uw->dictionary?->phonetic,
        'audio' => $uw->dictionary?->audio_url,
        'zh' => $uw->dictionary?->definition_zh,
        'pos' => $uw->dictionary?->part_of_speech,
        'meanings' => $uw->dictionary?->meanings ?? [],
        'example' => $uw->dictionary?->example,
        'mnemonic' => $uw->dictionary?->mnemonic,
        'grade_url' => route('review.grade', $uw),
    ])->values();
@endphp

<div x-data="reviewSession(@js($cards))" class="max-w-xl mx-auto">
    <h1 class="text-2xl font-bold mb-1">Daily Review</h1>

    <template x-if="!done">
        <div>
            <p class="text-sm text-slate-500 mb-4">Card <span x-text="index + 1"></span> / <span x-text="cards.length"></span></p>

            <div class="bg-white rounded-2xl border shadow-sm p-8 text-center min-h-[240px] flex flex-col items-center justify-center">
                <div class="flex items-center gap-2">
                    <span class="text-3xl font-bold" x-text="current.word"></span>
                    <button @click="play()" class="text-indigo-600 text-2xl">🔊</button>
                </div>
                <span class="text-slate-400 mt-1" x-text="current.phonetic"></span>

                <template x-if="!revealed">
                    <button @click="reveal()" class="mt-6 px-5 py-2 bg-slate-100 rounded-lg text-sm hover:bg-slate-200">Show Answer</button>
                </template>

                <template x-if="revealed">
                    <div class="mt-4 text-sm">
                        <!-- all parts of speech -->
                        <template x-if="current.meanings && current.meanings.length">
                            <div class="space-y-1.5">
                                <template x-for="(m, i) in current.meanings" :key="i">
                                    <div>
                                        <span class="text-xs bg-slate-100 px-2 py-0.5 rounded" x-text="m.pos"></span>
                                        <span class="text-lg text-slate-700 ml-1" x-text="m.definition_zh"></span>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <!-- fallback: single-meaning data -->
                        <template x-if="!(current.meanings && current.meanings.length)">
                            <div>
                                <span class="text-xs bg-slate-100 px-2 py-0.5 rounded" x-text="current.pos"></span>
                                <p class="text-lg text-slate-700 mt-2" x-text="current.zh"></p>
                            </div>
                        </template>
                        <p class="italic text-slate-400 mt-2" x-text="current.example || (loadingExample ? 'Generating an example…' : '')"></p>
                        <p class="text-sm text-amber-700 mt-2" x-show="current.mnemonic || loadingMnemonic"
                           x-text="current.mnemonic ? ('💡 ' + current.mnemonic) : (loadingMnemonic ? '💡 產生記憶小技巧中…' : '')"></p>
                    </div>
                </template>
                <audio x-ref="audio" :src="current.audio"></audio>
            </div>

            <template x-if="revealed">
                <div class="grid grid-cols-4 gap-2 mt-5">
                    <button @click="grade(1)" class="py-2 rounded-lg bg-red-100 text-red-700 text-sm font-medium hover:bg-red-200">Forgot</button>
                    <button @click="grade(3)" class="py-2 rounded-lg bg-amber-100 text-amber-700 text-sm font-medium hover:bg-amber-200">Hard</button>
                    <button @click="grade(4)" class="py-2 rounded-lg bg-sky-100 text-sky-700 text-sm font-medium hover:bg-sky-200">Good</button>
                    <button @click="grade(5)" class="py-2 rounded-lg bg-emerald-100 text-emerald-700 text-sm font-medium hover:bg-emerald-200">Easy</button>
                </div>
            </template>
        </div>
    </template>

    <template x-if="done">
        <div class="bg-white rounded-2xl border p-10 text-center">
            <div class="text-4xl mb-3">🎉</div>
            <h2 class="text-xl font-bold mb-2" x-text="cards.length ? 'Review complete!' : 'No words are due right now'"></h2>
            <p class="text-slate-500 text-sm mb-5" x-text="cards.length ? 'Your next review times have been scheduled along the memory curve.' : 'Look up words or take a quiz to build up your review queue.'"></p>
            <a href="{{ route('dashboard') }}" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm">Back to Dashboard</a>
        </div>
    </template>
</div>

<script>
function reviewSession(cards) {
    return {
        cards, index: 0, revealed: false, loadingExample: false, loadingMnemonic: false,
        get current() { return this.cards[this.index] || {}; },
        get done() { return this.cards.length === 0 || this.index >= this.cards.length; },
        // reveal the answer; if the card has no example, generate one on the fly
        async reveal() {
            this.revealed = true;
            const c = this.cards[this.index];
            if (!c) return;
            // generate an example on the fly if the card has none
            if (!c.example && !c._exampleTried) {
                c._exampleTried = true;
                this.loadingExample = true;
                try {
                    const r = await fetch('{{ route('words.example') }}?q=' + encodeURIComponent(c.word));
                    const data = await r.json();
                    if (data.example) c.example = data.example;
                } catch (e) {}
                this.loadingExample = false;
            }
            // generate a mnemonic on the fly if the card has none
            if (!c.mnemonic && !c._mnemonicTried) {
                c._mnemonicTried = true;
                this.loadingMnemonic = true;
                try {
                    const r = await fetch('{{ route('words.mnemonic') }}?q=' + encodeURIComponent(c.word));
                    const data = await r.json();
                    if (data.mnemonic) c.mnemonic = data.mnemonic;
                } catch (e) {}
                this.loadingMnemonic = false;
            }
        },
        play() {
            if (this.current.audio && this.$refs.audio) {
                this.$refs.audio.play();
            } else {
                speak(this.current.word);
            }
        },
        async grade(q) {
            try {
                await fetch(this.current.grade_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ quality: q }),
                });
            } catch (e) {}
            this.revealed = false;
            this.index++;
        }
    }
}
</script>
@endsection
