<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index()
    {
        $users = User::query()
            ->withCount([
                'userWords',
                'quizzes as completed_quizzes_count' => fn ($q) => $q->where('status', 'completed'),
            ])
            ->withMax('reviewLogs as last_activity_at', 'reviewed_at')
            ->orderByDesc('is_admin')
            ->orderBy('name')
            ->get();

        return view('admin.users.index', compact('users'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:users,name', // login id
            'email' => 'nullable|string|email|max:255|unique:users,email',
            'password' => ['required', Password::min(8)],
            'is_admin' => 'nullable|boolean',
        ]);

        User::create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'password' => Hash::make($data['password']),
            'is_admin' => $request->boolean('is_admin'),
            'is_active' => true,
        ]);

        return back()->with('status', "Created “{$data['name']}”");
    }

    public function update(Request $request, User $user)
    {
        $action = $request->input('action');

        if ($action === 'toggle_active') {
            // Don't let an admin disable their own account.
            abort_if($user->id === $request->user()->id, 403, 'You cannot disable your own account.');
            $user->update(['is_active' => ! $user->is_active]);

            return back()->with('status', ($user->is_active ? 'Enabled' : 'Disabled') . " “{$user->name}”");
        }

        if ($action === 'reset_password') {
            $data = $request->validate([
                'password' => ['required', Password::min(8)],
            ]);
            $user->update(['password' => Hash::make($data['password'])]);

            return back()->with('status', "Reset password for “{$user->name}”");
        }

        return back();
    }

    public function destroy(Request $request, User $user)
    {
        // Don't let an admin delete their own account.
        abort_if($user->id === $request->user()->id, 403, 'You cannot delete your own account.');

        $name = $user->name;
        $user->delete(); // cascades user_words / quizzes / review_logs via FK

        return back()->with('status', "Deleted “{$name}” and all their data");
    }
}
