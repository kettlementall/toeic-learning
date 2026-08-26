<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserWord;
use App\Models\Word;
use App\Services\ClaudeService;
use App\Services\QuizBuilderService;
use App\Support\Lemma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewWordDedupTest extends TestCase
{
    use RefreshDatabase;

    private function sameLemma(string $a, string $b): bool
    {
        return Lemma::sameWord($a, $b);
    }

    public function test_inflected_forms_are_treated_as_the_same_word(): void
    {
        $pairs = [
            ['offer', 'offers'],
            ['intern', 'interns'],
            ['obtain', 'obtained'],
            ['unveil', 'unveiled'],
            ['ship', 'shipping'],
            ['arrange', 'arranged'],
            ['company', 'companies'],
            ['operation', 'operations'],
            ['box', 'boxes'],
        ];

        foreach ($pairs as [$a, $b]) {
            $this->assertTrue($this->sameLemma($a, $b), "{$a} / {$b} should be one lemma");
        }
    }

    public function test_derived_forms_stay_separate_words(): void
    {
        $pairs = [
            ['invest', 'investment'],
            ['audit', 'auditor'],
            ['compete', 'competitor'],
            ['efficient', 'efficiently'],
            ['establish', 'establishment'],
            ['business', 'busy'],
            ['analysis', 'analyst'],
            ['process', 'proceed'],
            ['success', 'succeed'],
        ];

        foreach ($pairs as [$a, $b]) {
            $this->assertFalse($this->sameLemma($a, $b), "{$a} / {$b} are different words worth learning");
        }
    }

    public function test_generated_inflections_of_owned_words_are_discarded(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        UserWord::create([
            'user_id' => $user->id, 'word' => 'offer', 'source' => 'manual',
            'next_review_at' => now()->addDay(),
        ]);

        $this->mock(ClaudeService::class, function ($mock) {
            $mock->shouldReceive('generateNewWords')->andReturn([
                ['word' => 'Offers'],      // inflection of an owned word
                ['word' => 'negotiate'],   // genuinely new
            ]);
        });

        $qb = app(QuizBuilderService::class);
        $m = new \ReflectionMethod($qb, 'newWords');
        $m->setAccessible(true);

        $this->assertSame(['negotiate'], $m->invoke($qb, 1, []));
        $this->assertNull(Word::where('word', 'offers')->first(), 'the duplicate was not cached either');
    }
}
