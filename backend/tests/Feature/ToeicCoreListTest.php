<?php

namespace Tests\Feature;

use App\Console\Commands\SeedToeicCore;
use App\Models\Word;
use App\Services\ClaudeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToeicCoreListTest extends TestCase
{
    use RefreshDatabase;

    public function test_fabricated_compounds_are_rejected(): void
    {
        $junk = [
            'outbound-based', 'restock-able', 'remittance-based', 'deadline-driven',
            'vacancy-rate', 'checkout-time', 'reservation-desk', 'utility-bill',
            'itinerary-planning', 'maintenance-fee', 'remittance-slip',
        ];

        foreach ($junk as $word) {
            $this->assertFalse(SeedToeicCore::looksLikeAWord($word), "{$word} should be rejected");
        }
    }

    public function test_real_words_including_hyphenated_ones_are_kept(): void
    {
        $real = ['invoice', 'round-trip', 'co-worker', 'co-sign', 'cost-effective', 'walk-in', 'notwithstanding'];

        foreach ($real as $word) {
            $this->assertTrue(SeedToeicCore::looksLikeAWord($word), "{$word} should be kept");
        }
    }

    public function test_malformed_entries_are_rejected(): void
    {
        foreach (['', 'a', 'ab', 'Word', 'two words', 'a-b-c', 'x1', str_repeat('a', 19)] as $word) {
            $this->assertFalse(SeedToeicCore::looksLikeAWord($word), "'{$word}' should be rejected");
        }
    }

    public function test_audit_keeps_words_the_model_fails_to_judge(): void
    {
        Word::create(['word' => 'invoice', 'source' => Word::SOURCE_TOEIC_CORE, 'frequency_rank' => 1]);
        Word::create(['word' => 'warranty', 'source' => Word::SOURCE_TOEIC_CORE, 'frequency_rank' => 2]);

        // a parse failure returns nothing — that must never be read as "drop it"
        $this->mock(ClaudeService::class, function ($mock) {
            $mock->shouldReceive('hasKey')->andReturn(true);
            $mock->shouldReceive('verifyToeicWords')->andReturn([]);
        });

        $this->artisan('words:audit-toeic')->assertSuccessful();

        $this->assertSame(2, Word::toeicCore()->count());
    }

    public function test_renumbering_preserves_order_without_skipping_or_repeating_rows(): void
    {
        // duplicate and gapped ranks, as an interrupted seeding run leaves behind
        $words = ['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf', 'hotel', 'india', 'juliet'];
        foreach ($words as $i => $word) {
            Word::create([
                'word' => $word,
                'source' => Word::SOURCE_TOEIC_CORE,
                'frequency_rank' => intdiv($i, 2) * 5 + 1, // 1,1,6,6,11,11,16,16,21,21
            ]);
        }

        $this->mock(ClaudeService::class, function ($mock) {
            $mock->shouldReceive('hasKey')->andReturn(true);
        });

        $this->artisan('words:audit-toeic', ['--renumber-only' => true])->assertSuccessful();

        $ranks = Word::toeicCore()->pluck('frequency_rank')->all();

        $this->assertSame(range(1, 10), $ranks, 'every row gets exactly one contiguous rank');
        $this->assertSame($words, Word::toeicCore()->pluck('word')->all(), 'original order is preserved');
    }

    public function test_audit_drops_rejected_words_and_closes_the_rank_gaps(): void
    {
        Word::create(['word' => 'invoice', 'source' => Word::SOURCE_TOEIC_CORE, 'frequency_rank' => 1]);
        Word::create(['word' => 'expropriate', 'source' => Word::SOURCE_TOEIC_CORE, 'frequency_rank' => 2]);
        Word::create(['word' => 'warranty', 'source' => Word::SOURCE_TOEIC_CORE, 'frequency_rank' => 3]);

        $this->mock(ClaudeService::class, function ($mock) {
            $mock->shouldReceive('hasKey')->andReturn(true);
            $mock->shouldReceive('verifyToeicWords')->andReturn([
                'invoice' => true,
                'expropriate' => false,
                'warranty' => true,
            ]);
        });

        $this->artisan('words:audit-toeic')->assertSuccessful();

        $this->assertSame(['invoice', 'warranty'], Word::toeicCore()->pluck('word')->all());
        $this->assertSame([1, 2], Word::toeicCore()->pluck('frequency_rank')->all(), 'ranks are contiguous again');

        // dropped words are demoted, never deleted
        $expropriate = Word::where('word', 'expropriate')->first();
        $this->assertSame('api', $expropriate->source);
        $this->assertNull($expropriate->frequency_rank);
    }
}
