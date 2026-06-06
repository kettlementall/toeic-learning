<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Ensure the wells admin exists and owns all pre-existing data.
        $wells = User::firstOrCreate(
            ['email' => 'weiwei900118@gmail.com'],
            [
                'name' => 'wells',
                'password' => Hash::make('password'), // change after first login
                'is_admin' => true,
                'is_active' => true,
            ]
        );

        // Make sure an already-present wells account is flagged admin/active.
        $wells->forceFill(['is_admin' => true, 'is_active' => true])->save();

        // 2. Backfill ownership for every legacy (single-user) row.
        DB::table('user_words')->whereNull('user_id')->update(['user_id' => $wells->id]);
        DB::table('quizzes')->whereNull('user_id')->update(['user_id' => $wells->id]);
        DB::table('review_logs')->whereNull('user_id')->update(['user_id' => $wells->id]);

        // 3. Now that user_id is populated, make `word` unique PER USER instead
        //    of globally (two users may both have "acquire" in their libraries).
        Schema::table('user_words', function (Blueprint $table) {
            $table->dropUnique(['word']);
            $table->unique(['user_id', 'word']);
        });
    }

    public function down(): void
    {
        Schema::table('user_words', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'word']);
            $table->unique(['word']);
        });

        // Ownership backfill is intentionally not reverted (data, not schema).
    }
};
