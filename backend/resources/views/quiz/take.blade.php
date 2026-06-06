@extends('layouts.app')
@section('title', 'Take Quiz')

@section('content')
<div class="max-w-2xl mx-auto" x-data="{ answers: {}, submitting: false }">
    <h1 class="text-2xl font-bold mb-1">{{ $quiz->title }}</h1>
    <p class="text-sm text-slate-500 mb-5">{{ $quiz->total }} questions in total. Submit your answers and the AI will review them for you.</p>

    <form method="post" action="{{ route('quiz.submit', $quiz) }}" @submit="submitting = true">
        @csrf
        <div class="space-y-5">
            @foreach ($quiz->questions as $i => $q)
                <div class="bg-white rounded-xl border p-5">
                    <p class="font-medium mb-3"><span class="text-indigo-600">{{ $i + 1 }}.</span> {{ $q->question }}</p>
                    <div class="space-y-2">
                        @foreach ($q->options as $idx => $opt)
                            @php $letter = chr(65 + $idx); @endphp
                            <label class="flex items-center gap-2 text-sm cursor-pointer rounded-lg border px-3 py-2 hover:bg-slate-50"
                                   :class="answers[{{ $q->id }}] === '{{ $letter }}' ? 'border-indigo-500 bg-indigo-50' : ''">
                                <input type="radio" name="answers[{{ $q->id }}]" value="{{ $letter }}" x-model="answers[{{ $q->id }}]" class="hidden">
                                <span class="font-semibold text-slate-500 w-5">{{ $letter }}</span>
                                <span>{{ $opt }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <button type="submit" :disabled="submitting"
                class="w-full mt-6 py-3 bg-indigo-600 text-white rounded-lg font-medium hover:bg-indigo-700 disabled:opacity-50">
            <span x-show="!submitting">Submit Answers</span>
            <span x-show="submitting">Grading… AI review in progress…</span>
        </button>
    </form>
</div>
@endsection
