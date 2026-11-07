<?php

namespace Tests\Feature;

use App\Models\PracticeAnswer;
use App\Models\PracticeAttempt;
use App\Models\PracticeQuestion;
use App\Models\PracticeSet;
use App\Models\User;
use App\Services\PracticeImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PracticeFixtures;
use Tests\TestCase;

/**
 * Set files load into the practice bank, re-import in place, and refuse to
 * load anything malformed.
 */
class PracticeImportTest extends TestCase
{
    use PracticeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPracticeRoot();
    }

    protected function tearDown(): void
    {
        $this->tearDownPracticeRoot();
        parent::tearDown();
    }

    private function writeSet(array $set): void
    {
        file_put_contents($this->practiceRoot . "/{$set['code']}.json", json_encode($set, JSON_UNESCAPED_UNICODE));
    }

    public function test_command_imports_sets_and_reimport_updates_in_place(): void
    {
        $this->writeSet($this->readingSet());
        $this->writeSet($this->listeningSet());

        $this->artisan('practice:import')->assertSuccessful();
        $this->assertSame(2, PracticeSet::count());
        $this->assertSame(6, PracticeQuestion::count());
        $q101 = PracticeQuestion::where('number', 101)->first();
        $this->assertSame(['fit', 'fitting', 'fitted', 'fitness'], $q101->options, 'options keep source order');
        $this->assertSame(1080, PracticeSet::where('code', 'demo-reading')->first()->time_limit_seconds);

        $set = $this->readingSet();
        $set['questions'][0]['explanation'] = 'updated';
        $this->writeSet($set);
        $this->artisan('practice:import')->assertSuccessful();

        $this->assertSame(6, PracticeQuestion::count(), 'no duplicates on re-import');
        $this->assertSame($q101->id, PracticeQuestion::where('number', 101)->first()->id);
        $this->assertSame('updated', PracticeQuestion::find($q101->id)->explanation);
    }

    public function test_a_dropped_question_survives_only_if_someone_answered_it(): void
    {
        $importer = new PracticeImporter($this->practiceRoot);
        $importer->import($this->readingSet());
        $q101 = PracticeQuestion::where('number', 101)->first();
        $q102 = PracticeQuestion::where('number', 102)->first();

        $user = User::factory()->create();
        $attempt = PracticeAttempt::create(['user_id' => $user->id, 'mode' => 'practice', 'section' => 'reading', 'question_ids' => [$q101->id]]);
        PracticeAnswer::create(['attempt_id' => $attempt->id, 'user_id' => $user->id, 'question_id' => $q101->id, 'choice' => 'A', 'is_correct' => true]);

        $set = $this->readingSet();
        $set['questions'] = array_slice($set['questions'], 2); // drop 101 and 102
        $result = $importer->import($set);

        $this->assertSame(1, $result['removed']);
        $this->assertNotNull(PracticeQuestion::find($q101->id), 'answered question is kept');
        $this->assertNull(PracticeQuestion::find($q102->id));
    }

    public function test_malformed_sets_are_rejected_and_nothing_is_written(): void
    {
        $bad = $this->readingSet();
        $bad['questions'][0]['options'] = ['only', 'three', 'options'];
        $bad['questions'][1]['answer'] = 'E';
        $bad['questions'][2]['group'] = 'missing';
        $bad['questions'][3]['skill'] = 'astrology';
        $this->writeSet($bad);

        $listening = $this->listeningSet();
        $listening['groups'][0]['audio'] = ['audio/demo/nope.mp3'];
        $this->writeSet($listening);

        $errors = (new PracticeImporter($this->practiceRoot))->validate($bad);
        $this->assertCount(4, $errors);

        $this->artisan('practice:import')->assertFailed();
        $this->assertSame(0, PracticeSet::count());
    }

    public function test_part2_needs_three_options_and_paths_cannot_escape_the_root(): void
    {
        $set = $this->listeningSet();
        $set['questions'][0]['options'][] = 'A fourth option.';
        $set['groups'][1]['audio'] = ['../../etc/passwd'];

        $errors = implode("\n", (new PracticeImporter($this->practiceRoot))->validate($set));
        $this->assertStringContainsString('Part 2 needs 3', $errors);
        $this->assertStringContainsString('invalid path', $errors);
    }
}
