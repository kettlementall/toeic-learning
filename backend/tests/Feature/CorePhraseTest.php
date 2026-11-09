<?php

namespace Tests\Feature;

use App\Console\Commands\SeedToeicCore;
use App\Models\Word;
use App\Services\ClaudeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Collocations and patterns entered by hand from the handouts live in the core
 * list beside single words, and the audit leaves them alone.
 */
class CorePhraseTest extends TestCase
{
    use RefreshDatabase;

    public function test_phrases_from_the_handouts_are_recognised(): void
    {
        foreach (['comply with', 'be exposed to', 'warn sb to do', 'so ... that', "on one's own", 'in conjunction with'] as $phrase) {
            $this->assertTrue(SeedToeicCore::looksLikeAPhrase($phrase), $phrase);
        }
        foreach (['invoice', 'comply  ', 'a b c d e f g', 'pay $5', 'Comply With', ''] as $junk) {
            $this->assertFalse(SeedToeicCore::looksLikeAPhrase($junk), $junk);
        }
    }

    public function test_audit_keeps_phrases_and_never_sends_them_to_the_model(): void
    {
        Word::create(['word' => 'invoice', 'source' => Word::SOURCE_TOEIC_CORE, 'frequency_rank' => 1]);
        Word::create(['word' => 'comply with', 'source' => Word::SOURCE_TOEIC_CORE, 'frequency_rank' => 2]);
        Word::create(['word' => 'outbound-based', 'source' => Word::SOURCE_TOEIC_CORE, 'frequency_rank' => 3]);

        $this->mock(ClaudeService::class, function ($mock) {
            $mock->shouldReceive('hasKey')->andReturn(true);
            $mock->shouldReceive('verifyToeicWords')
                ->withArgs(fn ($words) => $words === ['invoice'])
                ->andReturn(['invoice' => true]);
        });

        $this->artisan('words:audit-toeic')->assertSuccessful();

        $this->assertSame(['invoice', 'comply with'], Word::toeicCore()->pluck('word')->all());
    }
}
