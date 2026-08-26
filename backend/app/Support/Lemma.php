<?php

namespace App\Support;

class Lemma
{
    /**
     * Candidate base forms for a word, stripping *inflectional* endings only
     * (plural -s/-es, past -ed, progressive -ing). Two words belong to the same
     * lemma when their candidate sets overlap, so "offer"/"offers" collide but
     * "invest"/"investment" do not — Part 5 tests word families, so derived
     * forms are worth learning separately.
     *
     * Spelling changes are handled by offering several candidates rather than
     * guessing one: "shipping" yields shipp/ship, "arranged" yields arrang and
     * arrange. Extra candidates only ever cost a skipped suggestion.
     *
     * @return string[]
     */
    public static function stems(string $word): array
    {
        $w = trim(strtolower($word));
        $stems = [$w];

        $add = function (string $base) use (&$stems) {
            if (strlen($base) < 3) {
                return;
            }
            $stems[] = $base;
            // undo a doubled final consonant: shipp -> ship
            if (strlen($base) > 3 && $base[-1] === $base[-2] && ! in_array($base[-1], ['s', 'l', 'e', 'o'], true)) {
                $stems[] = substr($base, 0, -1);
            }
            // restore a dropped silent e: arrang -> arrange
            $stems[] = $base . 'e';
        };

        if (strlen($w) > 4 && str_ends_with($w, 'ies')) {
            $stems[] = substr($w, 0, -3) . 'y';
        } elseif (strlen($w) > 4 && str_ends_with($w, 'ied')) {
            $stems[] = substr($w, 0, -3) . 'y';
        } elseif (strlen($w) > 5 && str_ends_with($w, 'ing')) {
            $add(substr($w, 0, -3));
        } elseif (strlen($w) > 4 && str_ends_with($w, 'ed')) {
            $add(substr($w, 0, -2));
        } elseif (strlen($w) > 4 && preg_match('/(s|x|z|ch|sh)es$/', $w)) {
            $stems[] = substr($w, 0, -2);
        } elseif (str_ends_with($w, 's')
            && ! str_ends_with($w, 'ss')
            && ! str_ends_with($w, 'us')
            && ! str_ends_with($w, 'is')) {
            $stems[] = substr($w, 0, -1);
        }

        return array_values(array_unique($stems));
    }

    /** Whether two words are inflections of the same base word. */
    public static function sameWord(string $a, string $b): bool
    {
        return (bool) array_intersect(self::stems($a), self::stems($b));
    }

    /**
     * Build a lookup of every inflectional stem across a list of words, for
     * cheap membership tests against a whole library.
     *
     * @param  iterable<string>  $words
     * @return array<string,true>
     */
    public static function index(iterable $words): array
    {
        $index = [];
        foreach ($words as $word) {
            foreach (self::stems((string) $word) as $stem) {
                $index[$stem] = true;
            }
        }

        return $index;
    }

    /** Whether $word collides with a stem index built by index(). */
    public static function isKnown(string $word, array $index): bool
    {
        foreach (self::stems($word) as $stem) {
            if (isset($index[$stem])) {
                return true;
            }
        }

        return false;
    }
}
