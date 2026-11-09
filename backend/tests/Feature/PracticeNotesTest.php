<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PracticeImporter;
use Illuminate\Support\Facades\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PracticeFixtures;
use Tests\TestCase;

/**
 * Handout notes: read from the media root, rendered without raw HTML, shown
 * only to signed-in users and linked from the sets they belong to.
 */
class PracticeNotesTest extends TestCase
{
    use PracticeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPracticeRoot();
        File::ensureDirectoryExists($this->practiceRoot . '/notes');
        file_put_contents($this->practiceRoot . '/notes/w1-part5.md',
            "# W1 Part 5 · Word forms (詞性)\n\n## 重點\n\n| 位置 | 詞性 |\n|---|---|\n| the ___ of | 名詞 |\n\n<script>alert(1)</script>\n");
        file_put_contents($this->practiceRoot . '/notes/zhuifen-grammar.md', "# 追分解題班 · Grammar\n\n- discuss the plan\n");
        file_put_contents($this->practiceRoot . '/notes/w2-part2.txt', 'not a note');
    }

    protected function tearDown(): void
    {
        $this->tearDownPracticeRoot();
        parent::tearDown();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/practice/notes/w1-part5')->assertRedirect('/login');
    }

    public function test_a_note_renders_its_markdown_and_strips_html(): void
    {
        $this->actingAs(User::factory()->create())->get('/practice/notes/w1-part5')
            ->assertOk()
            ->assertSee('W1 Part 5 · Word forms (詞性)')
            ->assertSee('<table>', false)
            ->assertSee('<h2>重點</h2>', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('追分解題班 · Grammar'); // the other notes are linked
    }

    public function test_unknown_or_malformed_notes_are_404(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/practice/notes/w9-part9')->assertNotFound();
        $this->actingAs($user)->get('/practice/notes/w2-part2')->assertNotFound(); // .txt is not a note
        $this->actingAs($user)->get('/practice/notes/..%2Fnotes%2Fw1-part5')->assertNotFound();
    }

    public function test_index_lists_notes_and_links_them_from_matching_sets(): void
    {
        $importer = new PracticeImporter($this->practiceRoot);
        $importer->import($this->readingSet(['code' => 'w1-part5-review', 'title' => 'W1 Part 5 review']));
        $importer->import($this->readingSet(['code' => 'zhuifen-1', 'title' => 'Mock 1', 'source' => 'zhuifen']));
        $importer->import($this->readingSet(['code' => 'w3-part7', 'title' => 'W3 Part 7']));

        $res = $this->actingAs(User::factory()->create())->get('/practice')->assertOk()
            ->assertSee('Lecture notes')
            ->assertSee(route('practice.notes', 'w1-part5'), false)
            ->assertSee(route('practice.notes', 'zhuifen-grammar'), false);

        $noteFor = $res->viewData('noteFor');
        $byCode = $res->viewData('sets')->mapWithKeys(fn ($s) => [$s->code => $noteFor[$s->id]]);
        $this->assertSame('w1-part5', $byCode['w1-part5-review']);
        $this->assertSame('zhuifen-grammar', $byCode['zhuifen-1']);
        $this->assertNull($byCode['w3-part7'], 'no note for that week and part');
    }
}
