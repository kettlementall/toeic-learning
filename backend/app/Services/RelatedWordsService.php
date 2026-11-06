<?php

namespace App\Services;

use App\Models\UserWord;
use App\Models\Word;
use App\Support\Lemma;
use Illuminate\Support\Collection;

/**
 * Near-synonyms a learner already knows, for the back of a flashcard.
 *
 * Which words are near-synonyms is the AI's call (`words.related`, generated
 * once per word and shared); which of those the learner knows is decided here,
 * at request time, so a word joins other cards the moment it is first
 * answered correctly — nothing is regenerated.
 */
class RelatedWordsService
{
    /** Words per generation call; one flashcard sitting's worth. */
    public const CHUNK = 20;

    public function __construct(private ClaudeService $claude)
    {
    }

    /**
     * Generate `related` for any of these words that has never been asked.
     * A word the model skipped stays null and is asked again next time; one it
     * answered with an empty list is stored as such and never asked again.
     *
     * @param  Collection<int, Word>  $words
     * @return int  how many words were filled
     */
    public function ensure(Collection $words): int
    {
        $filled = 0;

        foreach ($words->filter(fn ($w) => $w->related === null)->chunk(self::CHUNK) as $chunk) {
            $related = $this->claude->generateRelated($chunk->map(fn ($w) => [
                'word' => $w->word,
                'definition_zh' => $w->definition_zh,
            ])->values()->all());

            foreach ($chunk as $word) {
                $list = $related[strtolower($word->word)] ?? null;
                if ($list !== null) {
                    $word->update(['related' => $list]);
                    $filled++;
                }
            }
        }

        return $filled;
    }

    /**
     * For each term, the related words this learner knows: in the term's own
     * list, or listing the term in theirs — the model is not symmetric, and
     * either direction is the same pair. "Known" means answered correctly at
     * least once and not suspended: a word just added, or being relearned,
     * would sit next to the card as a second thing not yet known.
     *
     * @param  string[]  $terms
     * @return array<string, array<array{word: string, note: string, zh: ?string}>>
     */
    public function forUser(int $userId, array $terms): array
    {
        $known = UserWord::forUser($userId)
            ->active()
            ->where('repetitions', '>=', 1)
            ->with('dictionary')
            ->get();

        $byStem = [];
        foreach ($known as $uw) {
            foreach (Lemma::stems($uw->word) as $stem) {
                $byStem[$stem] ??= $uw;
            }
        }
        $find = function (string $word) use ($byStem): ?UserWord {
            foreach (Lemma::stems($word) as $stem) {
                if (isset($byStem[$stem])) {
                    return $byStem[$stem];
                }
            }

            return null;
        };

        $words = Word::whereIn('word', $terms)->get()->keyBy(fn ($w) => strtolower($w->word));
        $out = [];

        foreach ($terms as $term) {
            $matches = [];
            $add = function (UserWord $uw, string $note) use (&$matches, $term) {
                if (! Lemma::sameWord($uw->word, $term) && ! isset($matches[$uw->word])) {
                    $matches[$uw->word] = ['word' => $uw->word, 'note' => $note, 'zh' => $uw->dictionary?->definition_zh];
                }
            };

            // the term's own list first: its notes are written about the term
            foreach ($words[$term]->related ?? [] as $r) {
                if ($uw = $find($r['word'])) {
                    $add($uw, $r['note']);
                }
            }

            // then known words whose list names the term
            foreach ($known as $uw) {
                foreach ($uw->dictionary?->related ?? [] as $r) {
                    if (Lemma::sameWord($r['word'], $term)) {
                        $add($uw, $r['note']);
                    }
                }
            }

            $out[$term] = array_values($matches);
        }

        return $out;
    }
}
