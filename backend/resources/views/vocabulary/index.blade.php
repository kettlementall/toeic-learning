@extends('layouts.app')
@section('title', 'My Vocabulary')

@section('content')
@php
    $sourceLabels = [
        'search' => 'Search', 'manual' => 'Manual', 'quiz_weak' => 'Missed',
        'quiz_new' => 'New from Quiz', 'ai_review' => 'AI Suggested',
    ];
@endphp

<div class="flex items-center justify-between mb-4">
    <h1 class="text-2xl font-bold">My Vocabulary <span class="text-base font-normal text-slate-400">({{ $words->total() }})</span></h1>
</div>

<form method="get" class="flex flex-wrap gap-2 mb-5 text-sm">
    <a href="{{ route('vocabulary.index') }}"
       class="px-3 py-1.5 rounded-full border {{ !request('source') ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600' }}">All</a>
    @foreach ($sources as $s)
        <a href="{{ route('vocabulary.index', ['source' => $s]) }}"
           class="px-3 py-1.5 rounded-full border {{ request('source') === $s ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600' }}">
            {{ $sourceLabels[$s] ?? $s }}
        </a>
    @endforeach
</form>

@if ($words->count())
    <div class="space-y-3">
        @foreach ($words as $uw)
            <div x-data="{ edit: false, ex: '', tried: false, loading: false, mn: @js($uw->dictionary?->mnemonic ?? ''), mnTried: false, mnLoading: false, async genExample() { this.loading = true; try { const r = await fetch('{{ route('words.example') }}?q=' + encodeURIComponent(@js($uw->word))); const d = await r.json(); this.ex = d.example || ''; } catch (e) {} this.tried = true; this.loading = false; }, async genMnemonic() { this.mnLoading = true; try { const r = await fetch('{{ route('words.mnemonic') }}?q=' + encodeURIComponent(@js($uw->word))); const d = await r.json(); this.mn = d.mnemonic || ''; } catch (e) {} this.mnTried = true; this.mnLoading = false; } }" class="bg-white rounded-xl border p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-lg font-bold">{{ $uw->word }}</span>
                            @if ($uw->dictionary?->phonetic)
                                <span class="text-sm text-slate-400">{{ $uw->dictionary->phonetic }}</span>
                            @endif
                            @if ($uw->dictionary?->audio_url)
                                <button onclick="new Audio('{{ $uw->dictionary->audio_url }}').play()" class="text-indigo-600">🔊</button>
                            @else
                                <button onclick="speak(@js($uw->word))" class="text-indigo-600" title="Browser pronunciation">🔊</button>
                            @endif
                            <span class="text-xs bg-slate-100 text-slate-500 px-2 py-0.5 rounded">{{ $sourceLabels[$uw->source] ?? $uw->source }}</span>
                            @if ($uw->is_leech)
                                <span class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full" title="Missed again and again">🔴 missed {{ $uw->lapses }}×</span>
                            @endif
                        </div>
                        @if (! empty($uw->dictionary?->meanings))
                            <div class="mt-1 space-y-0.5">
                                @foreach ($uw->dictionary->meanings as $m)
                                    <p class="text-sm text-slate-600">
                                        <span class="text-xs text-slate-400">{{ $m['pos'] ?? '' }}</span>
                                        {{ $m['definition_zh'] ?? '' }}
                                    </p>
                                @endforeach
                            </div>
                        @elseif ($uw->dictionary?->definition_zh)
                            <p class="text-sm text-slate-600 mt-1">{{ $uw->dictionary->definition_zh }}</p>
                        @endif
                        @if ($uw->dictionary?->example)
                            <p class="text-sm italic text-slate-400 mt-0.5">{{ $uw->dictionary->example }}</p>
                        @else
                            <div class="mt-0.5">
                                <p x-show="ex" x-cloak class="text-sm italic text-slate-400" x-text="ex"></p>
                                <button x-show="!ex && !tried" @click="genExample()" :disabled="loading"
                                        class="text-xs text-indigo-500 hover:underline disabled:opacity-50"
                                        x-text="loading ? 'Generating…' : '✨ Generate example'"></button>
                                <p x-show="!ex && tried" x-cloak class="text-xs text-slate-300">No example available</p>
                            </div>
                        @endif
                        <div class="mt-0.5">
                            <p x-show="mn" x-cloak class="text-sm text-amber-700">💡 <span x-text="mn"></span></p>
                            <button x-show="!mn && !mnTried" @click="genMnemonic()" :disabled="mnLoading"
                                    class="text-xs text-indigo-500 hover:underline disabled:opacity-50"
                                    x-text="mnLoading ? 'Generating…' : '✨ 產生記憶小技巧'"></button>
                            <p x-show="!mn && mnTried" x-cloak class="text-xs text-slate-300">No mnemonic available</p>
                        </div>
                        @if ($uw->tags)
                            <div class="mt-1 flex gap-1 flex-wrap">
                                @foreach (explode(',', $uw->tags) as $tag)
                                    @if (trim($tag) !== '')
                                        <span class="text-xs bg-indigo-50 text-indigo-600 px-2 py-0.5 rounded-full">#{{ trim($tag) }}</span>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                        @if ($uw->notes)
                            <p class="text-sm text-slate-500 mt-1">📝 {{ $uw->notes }}</p>
                        @endif
                        <p class="text-xs text-slate-400 mt-1">Next review: {{ $uw->next_review_at?->format('Y-m-d') ?? '—' }}</p>
                    </div>
                    <div class="flex flex-col gap-1 text-sm">
                        <button @click="edit = !edit" class="text-indigo-600 hover:underline">Edit</button>
                        <form method="post" action="{{ route('vocabulary.destroy', $uw) }}" onsubmit="return confirm('Remove “{{ $uw->word }}”?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-red-500 hover:underline">Remove</button>
                        </form>
                    </div>
                </div>

                <div x-show="edit" x-cloak class="mt-3 pt-3 border-t">
                    <form method="post" action="{{ route('vocabulary.update', $uw) }}" class="space-y-2">
                        @csrf @method('PUT')
                        <input type="text" name="tags" value="{{ $uw->tags }}" placeholder="Tags (comma-separated)"
                               class="w-full rounded-lg border px-3 py-1.5 text-sm">
                        <textarea name="notes" rows="2" placeholder="Personal notes"
                                  class="w-full rounded-lg border px-3 py-1.5 text-sm">{{ $uw->notes }}</textarea>
                        <button type="submit" class="px-3 py-1.5 bg-indigo-600 text-white rounded-lg text-sm">Save</button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-5">{{ $words->links() }}</div>
@else
    <div class="bg-white rounded-xl border p-10 text-center text-slate-400">
        Your vocabulary is empty. <a href="{{ route('words.index') }}" class="text-indigo-600 hover:underline">Look up a few words</a> and they'll be added automatically.
    </div>
@endif

<style>[x-cloak]{display:none!important;}</style>
@endsection
