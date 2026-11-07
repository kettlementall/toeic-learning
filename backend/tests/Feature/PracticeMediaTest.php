<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PracticeFixtures;
use Tests\TestCase;

/**
 * Practice audio is served only to signed-in users, only from the media root,
 * and seekable (Range → 206).
 */
class PracticeMediaTest extends TestCase
{
    use PracticeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPracticeRoot();
        file_put_contents(sys_get_temp_dir() . '/outside-practice.mp3', 'secret');
    }

    protected function tearDown(): void
    {
        $this->tearDownPracticeRoot();
        @unlink(sys_get_temp_dir() . '/outside-practice.mp3');
        parent::tearDown();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/practice/media/audio/demo/q1.mp3')->assertRedirect('/login');
    }

    public function test_signed_in_user_gets_the_file_and_range_requests_work(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/practice/media/audio/demo/q1.mp3')
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg');

        $this->actingAs($user)->get('/practice/media/audio/demo/q1.mp3', ['Range' => 'bytes=0-99'])
            ->assertStatus(206)
            ->assertHeader('Content-Length', '100');
    }

    public function test_traversal_and_missing_files_are_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/practice/media/../outside-practice.mp3')->assertNotFound();
        $this->actingAs($user)->get('/practice/media/audio/%2e%2e/%2e%2e/outside-practice.mp3')->assertNotFound();
        $this->actingAs($user)->get('/practice/media/audio/demo/none.mp3')->assertNotFound();
    }
}
