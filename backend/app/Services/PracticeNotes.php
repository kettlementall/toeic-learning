<?php

namespace App\Services;

use App\Models\PracticeSet;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Study notes distilled from the course handouts: strategies, rules and
 * phrase tables, one markdown file per week and part (`w3-part5.md`) plus
 * `zhuifen-grammar.md` for the mock-test supplements. They live with the rest
 * of the course material under config('practice.root')/notes, out of git, and
 * are read straight from disk — no import step, editing a file is enough.
 */
class PracticeNotes
{
    private const KEY = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /** @return Collection<int, array{key: string, title: string, week: ?int, part: ?int}> */
    public function all(): Collection
    {
        return collect(glob($this->dir() . '/*.md') ?: [])
            ->map(fn ($path) => basename($path, '.md'))
            ->filter(fn ($key) => preg_match(self::KEY, $key))
            ->map(fn ($key) => [
                'key' => $key,
                'title' => $this->title($key),
                'week' => preg_match('/^w(\d+)-/', $key, $m) ? (int) $m[1] : null,
                'part' => preg_match('/-part(\d)$/', $key, $m) ? (int) $m[1] : null,
            ])
            // weekly notes in week/part order, anything else after
            ->sortBy(fn ($n) => [$n['week'] ?? 99, $n['part'] ?? 9, $n['key']])
            ->values();
    }

    /** @return array{key: string, title: string, html: HtmlString}|null */
    public function find(string $key): ?array
    {
        $path = $this->path($key);
        if ($path === null) {
            return null;
        }

        $markdown = (string) file_get_contents($path);

        return [
            'key' => $key,
            'title' => $this->title($key),
            // the notes are ours, but raw HTML is never needed: strip it
            'html' => new HtmlString(Str::markdown(
                preg_replace('/\A#[^\n]*\n/', '', $markdown), // the title is shown separately
                ['html_input' => 'strip', 'allow_unsafe_links' => false],
            )),
        ];
    }

    /**
     * The note that goes with a practice set: its week and part for lecture
     * sets (code "w4-part5-…" → "w4-part5"), the grammar notes for mock tests.
     */
    public function keyForSet(PracticeSet $set): ?string
    {
        $key = match (true) {
            (bool) preg_match('/^(w\d+-part\d)/', $set->code, $m) => $m[1],
            str_starts_with($set->code, 'zhuifen-') => 'zhuifen-grammar',
            default => null,
        };

        return $key !== null && $this->path($key) !== null ? $key : null;
    }

    private function title(string $key): string
    {
        $handle = fopen($this->path($key), 'r');
        $first = $handle ? (string) fgets($handle) : '';
        if ($handle) {
            fclose($handle);
        }

        return str_starts_with($first, '#') ? trim(ltrim($first, '#')) : $key;
    }

    private function path(string $key): ?string
    {
        if (! preg_match(self::KEY, $key)) {
            return null;
        }
        $path = $this->dir() . '/' . $key . '.md';

        return is_file($path) ? $path : null;
    }

    private function dir(): string
    {
        return rtrim(config('practice.root'), '/') . '/notes';
    }
}
