@extends('layouts.app')
@section('title', $note['title'])

@section('content')
<style>
    /* no typography plugin on the Tailwind CDN: just enough for handout notes */
    .note h2 { font-weight: 600; font-size: 1.125rem; margin: 1.5rem 0 .5rem; }
    .note h2:first-child, .note p:first-child { margin-top: 0; }
    .note h3 { font-weight: 600; margin: 1.25rem 0 .4rem; }
    .note p { margin: .5rem 0; }
    .note ul, .note ol { margin: .4rem 0 .4rem 1.25rem; }
    .note ul { list-style: disc; }
    .note ol { list-style: decimal; }
    .note li { margin: .15rem 0; }
    .note table { border-collapse: collapse; margin: .75rem 0; font-size: .875rem; }
    .note th, .note td { border: 1px solid #e2e8f0; padding: .35rem .6rem; vertical-align: top; text-align: left; }
    .note th { background: #f8fafc; font-weight: 600; }
    .note code { background: #f1f5f9; padding: 0 .25rem; border-radius: .25rem; }
    .note blockquote { border-left: 3px solid #cbd5e1; padding-left: .75rem; color: #475569; }
</style>

<div class="max-w-3xl mx-auto">
    <a href="{{ route('practice.index') }}" class="text-sm text-indigo-600 hover:underline">← Practice</a>
    <h1 class="text-2xl font-bold mt-2 mb-1">{{ $note['title'] }}</h1>
    <p class="text-xs text-slate-400 mb-4">From your course handouts.</p>

    <div class="bg-white rounded-xl border p-5 sm:p-6 note text-slate-800">
        <div class="overflow-x-auto">{{ $note['html'] }}</div>
    </div>

    @if ($all->count() > 1)
        <div class="mt-6 text-sm">
            <p class="font-semibold mb-2">Other notes</p>
            <div class="flex flex-wrap gap-2">
                @foreach ($all as $n)
                    @continue($n['key'] === $note['key'])
                    <a href="{{ route('practice.notes', $n['key']) }}" class="px-2.5 py-1 rounded-full border bg-white hover:bg-slate-50">{{ $n['title'] }}</a>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
