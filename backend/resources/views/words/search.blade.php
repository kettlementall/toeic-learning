@extends('layouts.app')
@section('title', 'Word Search')

@section('content')
<div x-data="wordSearch()" class="max-w-2xl mx-auto">
    <h1 class="text-2xl font-bold mb-1">Word Search</h1>
    <p class="text-sm text-slate-500 mb-5">Looks up a real dictionary (phonetics / pronunciation / examples) plus AI Chinese translations and TOEIC usage notes. Words you look up are automatically added to your vocabulary.</p>

    @unless ($hasKey)
        <div class="mb-4 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 px-4 py-2 text-sm">
            ANTHROPIC_API_KEY is not set. You can still look up English definitions, but there will be no Chinese translations or TOEIC usage notes.
        </div>
    @endunless

    <form @submit.prevent="search()" class="flex gap-2 mb-6">
        <div class="relative flex-1">
            <input x-model="term" type="text" placeholder="Enter an English word, e.g. recommend"
                   class="w-full rounded-lg border px-4 py-2.5 pr-10 focus:ring-2 focus:ring-indigo-400 focus:outline-none" autofocus>
            <button type="button" x-show="term.length" @click="clear()"
                    class="absolute right-2 top-1/2 -translate-y-1/2 w-6 h-6 flex items-center justify-center rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                    title="Clear" aria-label="Clear">&times;</button>
        </div>
        <button type="submit" :disabled="loading"
                class="px-5 py-2.5 bg-indigo-600 text-white rounded-lg font-medium hover:bg-indigo-700 disabled:opacity-50">
            <span x-show="!loading">Search</span>
            <span x-show="loading">Searching…</span>
        </button>
    </form>

    <template x-if="error">
        <div class="rounded-lg bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-sm" x-text="error"></div>
    </template>

    <template x-if="result">
        <div class="bg-white rounded-xl border p-6">
            <div class="flex items-center gap-3 flex-wrap">
                <h2 class="text-2xl font-bold" x-text="result.word"></h2>
                <span class="text-slate-500" x-text="result.phonetic"></span>
                <button @click="playAudio()" class="text-indigo-600 hover:text-indigo-800 text-xl" title="Play pronunciation">🔊</button>
            </div>

            <template x-if="added">
                <div class="mt-2 text-xs text-emerald-600">✓ Added to your vocabulary and queued for today's review</div>
            </template>

            <dl class="mt-4 space-y-3 text-sm">
                <!-- multiple meanings, one block per part of speech -->
                <template x-if="result.meanings && result.meanings.length">
                    <div class="space-y-3">
                        <template x-for="(m, i) in result.meanings" :key="i">
                            <div class="border-l-2 border-indigo-100 pl-3">
                                <div class="flex items-baseline gap-2 flex-wrap">
                                    <span class="text-xs bg-slate-100 text-slate-600 px-2 py-0.5 rounded" x-text="m.pos"></span>
                                    <span class="font-medium text-slate-800" x-text="m.definition_zh"></span>
                                </div>
                                <div class="mt-0.5 text-slate-600" x-text="m.definition_en"></div>
                                <template x-if="m.example">
                                    <div class="mt-0.5 italic text-slate-500" x-text="m.example"></div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>

                <!-- fallback: old single-meaning data not yet backfilled -->
                <template x-if="!(result.meanings && result.meanings.length)">
                    <div class="space-y-3">
                        <template x-if="result.definition_zh">
                            <div><dt class="font-semibold text-slate-500">Chinese Translation</dt><dd class="mt-0.5" x-text="result.definition_zh"></dd></div>
                        </template>
                        <template x-if="result.definition_en">
                            <div><dt class="font-semibold text-slate-500">English Definition</dt><dd class="mt-0.5 text-slate-700" x-text="result.definition_en"></dd></div>
                        </template>
                        <template x-if="result.example">
                            <div><dt class="font-semibold text-slate-500">Example</dt><dd class="mt-0.5 italic text-slate-600" x-text="result.example"></dd></div>
                        </template>
                    </div>
                </template>

                <template x-if="result.toeic_note">
                    <div><dt class="font-semibold text-slate-500">TOEIC Usage</dt><dd class="mt-0.5 text-indigo-700" x-text="result.toeic_note"></dd></div>
                </template>
                <div>
                    <dt class="font-semibold text-slate-500">記憶小技巧</dt>
                    <dd class="mt-0.5">
                        <span x-show="result.mnemonic" x-cloak class="text-amber-700">💡 <span x-text="result.mnemonic"></span></span>
                        <button x-show="!result.mnemonic && !mnemonicTried" @click="genMnemonic()" :disabled="mnemonicLoading"
                                class="text-xs text-indigo-500 hover:underline disabled:opacity-50"
                                x-text="mnemonicLoading ? 'Generating…' : '✨ 產生記憶小技巧'"></button>
                        <span x-show="!result.mnemonic && mnemonicTried" x-cloak class="text-xs text-slate-300">No mnemonic available</span>
                    </dd>
                </div>
                <template x-if="result.synonyms">
                    <div><dt class="font-semibold text-slate-500">Synonyms</dt><dd class="mt-0.5 text-slate-600" x-text="result.synonyms"></dd></div>
                </template>
            </dl>
            <audio x-ref="audio" :src="result.audio_url"></audio>
        </div>
    </template>
</div>

<script>
function wordSearch() {
    return {
        term: '', loading: false, error: '', result: null, added: false,
        mnemonicLoading: false, mnemonicTried: false,
        async search() {
            if (!this.term.trim()) return;
            this.loading = true; this.error = ''; this.result = null;
            this.mnemonicLoading = false; this.mnemonicTried = false;
            try {
                const res = await fetch(`{{ route('words.lookup') }}?q=` + encodeURIComponent(this.term.trim()));
                const data = await res.json();
                if (!res.ok) { this.error = data.error || 'Search failed'; }
                else { this.result = data.word; this.added = data.added; }
            } catch (e) { this.error = 'Network error. Please try again later.'; }
            this.loading = false;
        },
        async genMnemonic() {
            if (!this.result) return;
            this.mnemonicLoading = true;
            try {
                const r = await fetch(`{{ route('words.mnemonic') }}?q=` + encodeURIComponent(this.result.word));
                const d = await r.json();
                if (d.mnemonic) this.result.mnemonic = d.mnemonic;
            } catch (e) {}
            this.mnemonicTried = true; this.mnemonicLoading = false;
        },
        playAudio() {
            // real recording if the dictionary had one, else browser text-to-speech
            if (this.result?.audio_url && this.$refs.audio) {
                this.$refs.audio.play();
            } else {
                speak(this.result?.word);
            }
        },
        clear() {
            this.term = ''; this.error = ''; this.result = null; this.added = false;
            this.mnemonicLoading = false; this.mnemonicTried = false;
            this.$nextTick(() => this.$root.querySelector('input[type=text]')?.focus());
        }
    }
}
</script>
@endsection
