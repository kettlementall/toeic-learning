@extends('layouts.app')
@section('title', 'Create Quiz')

@section('content')
@php
    $grammarLabels = [
        'word_form' => 'Word Form', 'tense_voice' => 'Tense & Voice', 'preposition' => 'Preposition',
        'conjunction' => 'Conjunction', 'pronoun' => 'Pronoun', 'relative' => 'Relative Clause',
        'comparison' => 'Comparison', 'agreement' => 'Subject-Verb Agreement', 'collocation' => 'Collocation',
        'vocab_in_context' => 'Vocabulary in Context',
    ];
@endphp

<div class="max-w-xl mx-auto" x-data="{ type: 'vocab_mc', mode: 'smart', topic: 'top', submitting: false }">
    <h1 class="text-2xl font-bold mb-1">Create Quiz</h1>
    <p class="text-sm text-slate-500 mb-5">The AI generates questions targeting your weak points and can mix in new words.</p>

    @unless ($hasKey)
        <div class="mb-4 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 px-4 py-2 text-sm">
            ANTHROPIC_API_KEY is not set, so AI quiz generation is unavailable. Add your key to <code>backend/.env</code> and restart the container.
        </div>
    @endunless

    <form method="post" action="{{ route('quiz.store') }}" @submit="submitting = true" class="bg-white rounded-xl border p-6 space-y-5">
        @csrf

        <div>
            <label class="block text-sm font-semibold mb-2">Question Type</label>
            <div class="grid grid-cols-2 gap-2">
                <label class="cursor-pointer">
                    <input type="radio" name="type" value="vocab_mc" x-model="type" class="peer hidden">
                    <div class="text-center text-sm py-2 rounded-lg border peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:border-indigo-600">Vocabulary MC</div>
                </label>
                <label class="cursor-pointer">
                    <input type="radio" name="type" value="part5_grammar" x-model="type" class="peer hidden">
                    <div class="text-center text-sm py-2 rounded-lg border peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:border-indigo-600">Part 5 Grammar</div>
                </label>
                <label class="cursor-pointer">
                    <input type="radio" name="type" value="fill_blank" x-model="type" class="peer hidden">
                    <div class="text-center text-sm py-2 rounded-lg border peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:border-indigo-600">Fill in the Blank</div>
                </label>
                <label class="cursor-pointer">
                    <input type="radio" name="type" value="news_reading" x-model="type" class="peer hidden">
                    <div class="text-center text-sm py-2 rounded-lg border peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:border-indigo-600">News Reading (BBC)</div>
                </label>
            </div>
        </div>

        {{-- vocab / fill_blank mode --}}
        <div x-show="type === 'vocab_mc' || type === 'fill_blank'" x-cloak>
            <label class="block text-sm font-semibold mb-2">Question Strategy</label>
            <div class="space-y-1.5 text-sm">
                <label class="flex items-center gap-2"><input type="radio" name="mode" value="smart" x-model="mode"> Smart mix (70% weak points + 30% new words)</label>
                <label class="flex items-center gap-2"><input type="radio" name="mode" value="weak" x-model="mode"> Weak points first (only words you struggle with)</label>
                <label class="flex items-center gap-2"><input type="radio" name="mode" value="custom" x-model="mode"> Custom range</label>
            </div>
        </div>

        {{-- part5 mode --}}
        <div x-show="type === 'part5_grammar'" x-cloak>
            <label class="block text-sm font-semibold mb-2">Part 5 Mode</label>
            <div class="space-y-1.5 text-sm">
                <label class="flex items-center gap-2"><input type="radio" name="mode" value="adaptive" @click="mode='adaptive'" :checked="type==='part5_grammar'"> Adaptive (weighted toward your weakest points)</label>
                <label class="flex items-center gap-2"><input type="radio" name="mode" value="specific" @click="mode='specific'"> Specific points</label>
            </div>
            <div x-show="mode === 'specific'" x-cloak class="mt-3 grid grid-cols-2 gap-1.5 text-sm">
                @foreach ($grammarPoints as $gp)
                    <label class="flex items-center gap-2"><input type="checkbox" name="points[]" value="{{ $gp }}"> {{ $grammarLabels[$gp] ?? $gp }}</label>
                @endforeach
            </div>
        </div>

        {{-- news reading topic --}}
        <div x-show="type === 'news_reading'" x-cloak>
            <label class="block text-sm font-semibold mb-2">News Topic</label>
            <p class="text-xs text-slate-500 mb-2">The AI picks a recent BBC article, judges whether its difficulty fits you, writes ~4 reading questions, and adds useful words to your library.</p>
            <div class="grid grid-cols-2 gap-1.5 text-sm">
                @foreach (['top' => 'Top Stories', 'world' => 'World', 'business' => 'Business', 'technology' => 'Technology'] as $val => $label)
                    <label class="flex items-center gap-2"><input type="radio" name="topic" value="{{ $val }}" x-model="topic"> {{ $label }}</label>
                @endforeach
            </div>
        </div>

        <div x-show="type !== 'news_reading'" x-cloak>
            <label class="block text-sm font-semibold mb-2">Number of Questions</label>
            <select name="count" class="rounded-lg border px-3 py-2 text-sm">
                @foreach ([5, 8, 10, 15, 20] as $c)
                    <option value="{{ $c }}" @selected($c === 10)>{{ $c }} questions</option>
                @endforeach
            </select>
        </div>

        <button type="submit" :disabled="submitting || !{{ $hasKey ? 'true' : 'false' }}"
                class="w-full py-2.5 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 disabled:opacity-50">
            <span x-show="!submitting">Generate Quiz</span>
            <span x-show="submitting">AI is generating questions, please wait…</span>
        </button>
    </form>
</div>
<style>[x-cloak]{display:none!important;}</style>
@endsection
