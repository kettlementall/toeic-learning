<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account · TOEIC Learning Platform</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-sm">
        <div class="text-center mb-6">
            <a href="{{ route('login') }}" class="font-bold text-indigo-600 text-2xl">📚 TOEIC</a>
            <p class="text-slate-500 text-sm mt-1">Create your own training library</p>
        </div>

        <div class="bg-white rounded-xl shadow-sm border p-6">
            @if ($errors->any())
                <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-800 px-3 py-2 text-sm">
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('register') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium mb-1" for="name">Account ID</label>
                    <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus
                           class="w-full rounded-lg border-slate-300 border px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none">
                    <p class="text-xs text-slate-400 mt-1">This is what you'll sign in with.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="email">Email <span class="text-slate-400 font-normal">(optional)</span></label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}"
                           class="w-full rounded-lg border-slate-300 border px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="password">Password</label>
                    <input id="password" name="password" type="password" required
                           class="w-full rounded-lg border-slate-300 border px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none">
                    <p class="text-xs text-slate-400 mt-1">At least 8 characters.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="password_confirmation">Confirm password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required
                           class="w-full rounded-lg border-slate-300 border px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none">
                </div>
                <button type="submit"
                        class="w-full rounded-lg bg-indigo-600 text-white font-medium py-2 text-sm hover:bg-indigo-700">
                    Create account
                </button>
            </form>
        </div>

        <p class="text-center text-sm text-slate-500 mt-4">
            Already have an account?
            <a href="{{ route('login') }}" class="text-indigo-600 font-medium hover:underline">Sign in</a>
        </p>
    </div>
</body>
</html>
