<?php

namespace App\Services;

use App\Models\UserWord;
use App\Models\Word;
use App\Support\Lemma;

/**
 * How far into the curated TOEIC list (`toeic_core`) a learner has got: which
 * of its words are in their library, and which of those are well learned,
 * overall and in bands of frequency rank. Matching is lemma-aware, so a
 * library "offers" covers the list's "offer".
 */
class CoreCoverageService
{
    /** Frequency-rank band width on the dashboard. */
    public const BAND = 200;

    /** Interval at which a card counts as well learned (SM-2 "mature"). */
    public const MATURE_DAYS = 21;

    /** Share of a milestone's words in the library before the next one is set. */
    public const MILESTONE_DONE = 0.9;

    /**
     * @return array{milestone: ?array, total: int, learning: int, mature: int, library: int, in_core: int,
     *               bands: array<array{from: int, to: int, total: int, learning: int, mature: int}>}
     */
    public function forUser(int $userId): array
    {
        $library = UserWord::forUser($userId)->get(['word', 'interval_days', 'suspended_at']);
        $libraryIdx = Lemma::index($library->pluck('word'));
        $matureIdx = Lemma::index($library
            ->filter(fn ($uw) => $uw->suspended_at === null && $uw->interval_days >= self::MATURE_DAYS)
            ->pluck('word'));

        $core = Word::toeicCore()->get(['word', 'frequency_rank']);
        $coreIdx = Lemma::index($core->pluck('word'));

        $bands = [];
        $learning = $mature = 0;
        foreach ($core as $w) {
            $band = intdiv(max(1, $w->frequency_rank) - 1, self::BAND);
            $bands[$band] ??= ['from' => $band * self::BAND + 1, 'to' => 0, 'total' => 0, 'learning' => 0, 'mature' => 0];
            $bands[$band]['to'] = max($bands[$band]['to'], (int) $w->frequency_rank);
            $bands[$band]['total']++;

            // mature is a subset of in-library; "learning" is the rest
            if (Lemma::isKnown($w->word, $matureIdx)) {
                $bands[$band]['mature']++;
                $mature++;
            } elseif (Lemma::isKnown($w->word, $libraryIdx)) {
                $bands[$band]['learning']++;
                $learning++;
            }
        }
        ksort($bands);
        $bands = array_values($bands);

        return [
            'milestone' => $this->milestone($bands),
            'total' => $core->count(),
            'learning' => $learning,
            'mature' => $mature,
            'library' => $library->count(),
            'in_core' => $library->filter(fn ($uw) => Lemma::isKnown($uw->word, $coreIdx))->count(),
            'bands' => $bands,
        ];
    }

    /**
     * The goal to show instead of the whole list, which is far too long to
     * read as a to-do: the top N ranks, N a multiple of BAND, for the first N
     * not yet MILESTONE_DONE in the library. Past the end of the list it stays
     * on the whole list.
     *
     * @return array{upto: int, total: int, known: int, mature: int}|null
     */
    private function milestone(array $bands): ?array
    {
        $goal = null;
        $total = $known = $mature = 0;
        $last = array_key_last($bands);

        foreach ($bands as $i => $b) {
            $total += $b['total'];
            $known += $b['learning'] + $b['mature'];
            $mature += $b['mature'];
            $goal = [
                // a round number, except for the list's own last rank
                'upto' => $i === $last ? $b['to'] : $b['from'] + self::BAND - 1,
                'total' => $total,
                'known' => $known,
                'mature' => $mature,
            ];

            if ($known < $total * self::MILESTONE_DONE) {
                break;
            }
        }

        return $goal;
    }
}
