<?php

namespace Tests\Feature;

use App\Models\PracticeAttempt;
use App\Models\PracticeQuestion;
use App\Models\PracticeSet;
use App\Models\User;
use App\Services\PracticeImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PracticeFixtures;
use Tests\TestCase;

/**
 * Sitting a practice set over HTTP: list → start → take → submit → result,
 * plus ownership, the mock timer, retry and dictation.
 */
class PracticeFlowTest extends TestCase
{
    use PracticeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPracticeRoot();
        $importer = new PracticeImporter($this->practiceRoot);
        $importer->import($this->readingSet());
        $importer->import($this->listeningSet());
        $importer->import([
            'code' => 'demo-dictation', 'title' => 'Demo Dictation', 'source' => 'lecture', 'section' => 'dictation',
            'dictations' => [['number' => 1, 'audio' => 'audio/demo/dict1.mp3', 'transcript' => 'Lamps have been hung from the ceiling.']],
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPracticeRoot();
        parent::tearDown();
    }

    private function qid(int $number): int
    {
        return PracticeQuestion::where('number', $number)->value('id');
    }

    public function test_full_mock_sitting_over_http(): void
    {
        $user = User::factory()->create();
        $set = PracticeSet::where('code', 'demo-reading')->first();

        $this->actingAs($user)->get('/practice')->assertOk()
            ->assertSee('Demo Reading')->assertSee('Mock · 18 min')->assertSee('Demo Dictation');

        $this->actingAs($user)->post("/practice/sets/{$set->id}/start", ['mode' => 'mock'])->assertRedirect();
        $attempt = PracticeAttempt::firstOrFail();

        $this->actingAs($user)->get("/practice/attempts/{$attempt->id}")->assertOk()
            ->assertSee('Staying ------ helps.')
            ->assertSee('Questions 131–132')
            ->assertSee('remaining: 1080', false);

        $this->actingAs($user)->post("/practice/attempts/{$attempt->id}/submit", ['answers' => [
            $this->qid(101) => 'A', $this->qid(102) => 'C', $this->qid(131) => 'A', $this->qid(132) => 'A',
        ]])->assertRedirect("/practice/attempts/{$attempt->id}/result");

        $this->actingAs($user)->get("/practice/attempts/{$attempt->id}/result")->assertOk()
            ->assertSee('75%')
            ->assertSee('3 / 4')
            ->assertSee('主詞補語用形容詞')
            ->assertSee('Part 6 · 2/2');

        // a finished attempt can't be taken again; the take page sends you to the result
        $this->actingAs($user)->get("/practice/attempts/{$attempt->id}")->assertRedirect("/practice/attempts/{$attempt->id}/result");
        $this->actingAs($user)->get('/practice')->assertSee('last 3/4');
    }

    public function test_timer_counts_from_the_start_not_from_each_page_load(): void
    {
        $user = User::factory()->create();
        $set = PracticeSet::where('code', 'demo-reading')->first();
        $this->actingAs($user)->post("/practice/sets/{$set->id}/start", ['mode' => 'mock']);
        $attempt = PracticeAttempt::firstOrFail();
        $attempt->update(['started_at' => now()->subSeconds(300)]);

        $this->actingAs($user)->get("/practice/attempts/{$attempt->id}")->assertSee('remaining: 780', false);
    }

    public function test_practice_mode_has_no_timer_and_listening_hides_part2_options_only_in_mock(): void
    {
        $user = User::factory()->create();
        $listening = PracticeSet::where('code', 'demo-listening')->first();

        $this->actingAs($user)->post("/practice/sets/{$listening->id}/start", ['mode' => 'practice']);
        $practice = PracticeAttempt::latest('id')->first();
        $this->actingAs($user)->get("/practice/attempts/{$practice->id}")->assertOk()
            ->assertSee('remaining: null', false)
            ->assertSee('Next to the post office.')
            ->assertSee('audio/demo/q1.mp3');

        $this->actingAs($user)->post("/practice/sets/{$listening->id}/start", ['mode' => 'mock']);
        $mock = PracticeAttempt::latest('id')->first();
        $this->actingAs($user)->get("/practice/attempts/{$mock->id}")->assertOk()
            ->assertDontSee('Next to the post office.')
            ->assertSee('At a hotel'); // Part 3 options are printed on the real paper
    }

    public function test_attempts_belong_to_their_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $set = PracticeSet::where('code', 'demo-reading')->first();
        $this->actingAs($owner)->post("/practice/sets/{$set->id}/start", ['mode' => 'practice']);
        $attempt = PracticeAttempt::firstOrFail();

        $this->actingAs($other)->get("/practice/attempts/{$attempt->id}")->assertForbidden();
        $this->actingAs($other)->post("/practice/attempts/{$attempt->id}/submit", ['answers' => []])->assertForbidden();
        $this->actingAs($other)->get("/practice/attempts/{$attempt->id}/result")->assertForbidden();
        $this->assertSame('in_progress', $attempt->fresh()->status);
    }

    public function test_retry_builds_a_sitting_from_missed_questions(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/practice/retry', ['section' => 'reading'])
            ->assertSessionHas('status');
        $this->assertSame(0, PracticeAttempt::count());

        $set = PracticeSet::where('code', 'demo-reading')->first();
        $this->actingAs($user)->post("/practice/sets/{$set->id}/start", ['mode' => 'practice']);
        $first = PracticeAttempt::firstOrFail();
        $this->actingAs($user)->post("/practice/attempts/{$first->id}/submit", ['answers' => [$this->qid(101) => 'A']]);

        $this->actingAs($user)->get('/practice')->assertSee('Reading · 3');
        $this->actingAs($user)->post('/practice/retry', ['section' => 'reading'])->assertRedirect();
        $retry = PracticeAttempt::latest('id')->first();
        $this->assertSame('retry', $retry->mode);
        $this->assertCount(3, $retry->question_ids);
        $this->actingAs($user)->get("/practice/attempts/{$retry->id}")->assertOk()->assertSee('Retry · missed reading questions');
    }

    public function test_invalid_mode_and_dictation_sets_cannot_be_started(): void
    {
        $user = User::factory()->create();
        $reading = PracticeSet::where('code', 'demo-reading')->first();
        $dictation = PracticeSet::where('code', 'demo-dictation')->first();

        $this->actingAs($user)->post("/practice/sets/{$reading->id}/start", ['mode' => 'cheat'])->assertSessionHasErrors('mode');
        $this->actingAs($user)->post("/practice/sets/{$dictation->id}/start", ['mode' => 'practice'])->assertNotFound();
        $this->actingAs($user)->get("/practice/sets/{$dictation->id}/dictation")->assertOk()
            ->assertSee('audio/demo/dict1.mp3')->assertSee('Lamps have been hung', false);
        $this->actingAs($user)->get("/practice/sets/{$reading->id}/dictation")->assertNotFound();
    }
}
