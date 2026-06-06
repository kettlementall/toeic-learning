@extends('layouts.app')

@section('title', 'User Management · TOEIC')

@section('content')
<div x-data="{ showCreate: false }">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold">User Management</h1>
            <p class="text-slate-500 text-sm">{{ $users->count() }} account(s)</p>
        </div>
        <button type="button" @click="showCreate = !showCreate"
                class="rounded-lg bg-indigo-600 text-white text-sm font-medium px-4 py-2 hover:bg-indigo-700">
            + New user
        </button>
    </div>

    {{-- Create form --}}
    <div x-show="showCreate" x-cloak x-transition class="bg-white rounded-xl border shadow-sm p-5 mb-6">
        <h2 class="font-semibold mb-3">Create a user</h2>
        <form method="POST" action="{{ route('admin.users.store') }}"
              class="grid sm:grid-cols-2 gap-3">
            @csrf
            <input name="name" type="text" placeholder="Account ID" value="{{ old('name') }}" required
                   class="rounded-lg border-slate-300 border px-3 py-2 text-sm">
            <input name="email" type="email" placeholder="Email (optional)" value="{{ old('email') }}"
                   class="rounded-lg border-slate-300 border px-3 py-2 text-sm">
            <input name="password" type="password" placeholder="Password (min 8)" required
                   class="rounded-lg border-slate-300 border px-3 py-2 text-sm">
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="is_admin" value="1" class="rounded border-slate-300">
                Make administrator
            </label>
            <div class="sm:col-span-2">
                <button type="submit"
                        class="rounded-lg bg-indigo-600 text-white text-sm font-medium px-4 py-2 hover:bg-indigo-700">
                    Create
                </button>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-xl border shadow-sm overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-left">
                <tr>
                    <th class="px-4 py-3 font-medium">User</th>
                    <th class="px-4 py-3 font-medium">Role</th>
                    <th class="px-4 py-3 font-medium text-right">Words</th>
                    <th class="px-4 py-3 font-medium text-right">Quizzes</th>
                    <th class="px-4 py-3 font-medium">Last activity</th>
                    <th class="px-4 py-3 font-medium text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @foreach ($users as $user)
                    <tr class="{{ $user->is_active ? '' : 'bg-slate-50/60 text-slate-400' }}"
                        x-data="{ showReset: false }">
                        <td class="px-4 py-3">
                            <div class="font-medium {{ $user->is_active ? 'text-slate-800' : '' }}">
                                {{ $user->name }}
                                @unless ($user->is_active)
                                    <span class="ml-1 text-xs rounded bg-slate-200 text-slate-500 px-1.5 py-0.5">disabled</span>
                                @endunless
                            </div>
                            <div class="text-slate-400 text-xs">{{ $user->email }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @if ($user->is_admin)
                                <span class="text-xs rounded bg-indigo-50 text-indigo-700 px-2 py-0.5 font-medium">Admin</span>
                            @else
                                <span class="text-xs text-slate-500">Member</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $user->user_words_count }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $user->completed_quizzes_count }}</td>
                        <td class="px-4 py-3 text-slate-500">
                            {{ $user->last_activity_at ? \Illuminate\Support\Carbon::parse($user->last_activity_at)->diffForHumans() : '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2 justify-end">
                                @if ($user->id !== auth()->id())
                                    {{-- Enable / disable --}}
                                    <form method="POST" action="{{ route('admin.users.update', $user) }}">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="action" value="toggle_active">
                                        <button type="submit"
                                                class="text-xs rounded-md border px-2.5 py-1 font-medium hover:bg-slate-50">
                                            {{ $user->is_active ? 'Disable' : 'Enable' }}
                                        </button>
                                    </form>

                                    {{-- Delete --}}
                                    <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                                          onsubmit="return confirm('Delete {{ $user->name }} and ALL their data? This cannot be undone.');">
                                        @csrf @method('DELETE')
                                        <button type="submit"
                                                class="text-xs rounded-md border border-red-200 text-red-600 px-2.5 py-1 font-medium hover:bg-red-50">
                                            Delete
                                        </button>
                                    </form>
                                @else
                                    <span class="text-xs text-slate-400">(you)</span>
                                @endif

                                {{-- Reset password (allowed for anyone, incl. self) --}}
                                <button type="button" @click="showReset = !showReset"
                                        class="text-xs rounded-md border px-2.5 py-1 font-medium hover:bg-slate-50">
                                    Reset password
                                </button>
                            </div>

                            <div x-show="showReset" x-cloak x-transition class="mt-2 flex justify-end">
                                <form method="POST" action="{{ route('admin.users.update', $user) }}"
                                      class="flex items-center gap-2">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="action" value="reset_password">
                                    <input name="password" type="password" placeholder="New password" required
                                           class="rounded-md border-slate-300 border px-2 py-1 text-xs">
                                    <button type="submit"
                                            class="text-xs rounded-md bg-indigo-600 text-white px-2.5 py-1 font-medium hover:bg-indigo-700">
                                        Save
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
