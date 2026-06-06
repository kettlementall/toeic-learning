<?php

namespace Tests\Feature;

use App\Models\Quiz;
use App\Models\User;
use App\Models\UserWord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_vocabulary_is_scoped_to_the_owner(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserWord::create(['user_id' => $alice->id, 'word' => 'acquire', 'source' => 'manual']);

        $this->actingAs($alice)->get('/vocabulary')->assertOk()->assertSee('acquire');
        $this->actingAs($bob)->get('/vocabulary')->assertOk()->assertDontSee('acquire');
    }

    public function test_same_word_can_belong_to_two_users(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserWord::create(['user_id' => $alice->id, 'word' => 'acquire', 'source' => 'manual']);
        $bobWord = UserWord::create(['user_id' => $bob->id, 'word' => 'acquire', 'source' => 'manual']);

        $this->assertDatabaseCount('user_words', 2);
        $this->assertTrue($bobWord->exists);
    }

    public function test_user_cannot_open_another_users_quiz(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $quiz = Quiz::create([
            'user_id' => $alice->id,
            'title' => 'Alice quiz',
            'type' => 'vocab_mc',
            'scope' => 'mixed',
            'status' => 'pending',
            'total' => 0,
        ]);

        $this->actingAs($bob)->get("/quiz/{$quiz->id}")->assertForbidden();
        $this->actingAs($alice)->get("/quiz/{$quiz->id}")->assertOk();
    }

    public function test_only_admins_reach_the_admin_area(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create(['is_admin' => false]);

        $this->actingAs($member)->get('/admin/users')->assertForbidden();
        $this->actingAs($admin)->get('/admin/users')->assertOk();
    }

    public function test_users_log_in_with_their_account_id(): void
    {
        User::factory()->create([
            'name' => 'study_buddy',
            'password' => bcrypt('secret123'),
            'is_active' => true,
        ]);

        $this->post('/login', [
            'name' => 'study_buddy',
            'password' => 'secret123',
        ])->assertRedirect('/');

        $this->assertAuthenticated();
    }

    public function test_disabled_users_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'name' => 'disabled_user',
            'password' => bcrypt('secret123'),
            'is_active' => false,
        ]);

        $this->post('/login', [
            'name' => 'disabled_user',
            'password' => 'secret123',
        ])->assertSessionHasErrors('name');

        $this->assertGuest();
    }
}
