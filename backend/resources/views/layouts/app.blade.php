<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'TOEIC Learning Platform')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen">
    @php
        $nav = [
            'dashboard' => ['Dashboard', route('dashboard')],
            'words.index' => ['Word Search', route('words.index')],
            'vocabulary.index' => ['My Vocabulary', route('vocabulary.index')],
            'review.session' => ['Daily Review', route('review.session')],
            'quiz.create' => ['Quiz', route('quiz.create')],
        ];
    @endphp
    <nav class="bg-white border-b shadow-sm sticky top-0 z-10" x-data="{ open: false }">
        <div class="max-w-5xl mx-auto px-4 flex items-center gap-1 h-14">
            <a href="{{ route('dashboard') }}" class="font-bold text-indigo-600 mr-4 text-lg">📚 TOEIC</a>

            {{-- Desktop / tablet: inline links --}}
            <div class="hidden md:flex items-center gap-1">
                @foreach ($nav as $name => [$label, $url])
                    <a href="{{ $url }}"
                       class="px-3 py-1.5 rounded-md text-sm font-medium {{ request()->routeIs($name) ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-100' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            {{-- Mobile: hamburger toggle --}}
            <button type="button" @click="open = !open"
                    class="md:hidden ml-auto p-2 rounded-md text-slate-600 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                    :aria-expanded="open" aria-label="Toggle navigation menu">
                <svg x-show="!open" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
                <svg x-show="open" x-cloak class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        {{-- Mobile: collapsible menu --}}
        <div x-show="open" x-cloak x-transition class="md:hidden border-t bg-white">
            <div class="max-w-5xl mx-auto px-4 py-2 flex flex-col gap-1">
                @foreach ($nav as $name => [$label, $url])
                    <a href="{{ $url }}"
                       class="px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs($name) ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-100' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>
    </nav>

    <main class="max-w-5xl mx-auto px-4 py-6">
        @if (session('status'))
            <div class="mb-4 rounded-lg bg-green-50 border border-green-200 text-green-800 px-4 py-2 text-sm">
                {{ session('status') }}
            </div>
        @endif
        @if (session('error'))
            <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-800 px-4 py-2 text-sm">
                {{ session('error') }}
            </div>
        @endif

        @yield('content')
    </main>

    <script>
        // Speak a word with the browser's built-in TTS — fallback for words the
        // dictionary API has no recorded audio for. Works offline, any word.
        function speak(text) {
            if (!text || !('speechSynthesis' in window)) return;
            window.speechSynthesis.cancel();
            const u = new SpeechSynthesisUtterance(text);
            u.lang = 'en-US';
            u.rate = 0.9;
            window.speechSynthesis.speak(u);
        }
    </script>
</body>
</html>
