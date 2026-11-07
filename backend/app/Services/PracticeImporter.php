<?php

namespace App\Services;

use App\Models\PracticeAnswer;
use App\Models\PracticeDictation;
use App\Models\PracticeGroup;
use App\Models\PracticeQuestion;
use App\Models\PracticeSet;
use Illuminate\Support\Facades\DB;

/**
 * Load practice set files (one JSON per set) into the practice_* tables.
 *
 * File shape:
 *   {code, title, source, section, week?, time_limit_seconds?, sort?,
 *    groups:    [{key, label?, body?, audio?: [path], transcript?, image?}],
 *    questions: [{number, part, group?, stem?, options: [..], answer,
 *                 answer_source, skill?, explanation?}],
 *    dictations:[{number, audio, transcript}]}
 *
 * Media paths are relative to config('practice.root'). Re-running is safe: sets,
 * groups and questions are matched on their natural keys (code, key, number) and
 * updated in place, so answers learners already gave keep pointing at the same
 * question rows. A question dropped from a file is deleted only if nobody has
 * answered it yet.
 */
class PracticeImporter
{
    private const SOURCES = ['zhuifen', 'lecture'];
    private const SECTIONS = ['reading', 'listening', 'dictation'];
    private const ANSWER_SOURCES = ['verified', 'handwriting', 'supplement', 'solved', 'confirmed'];

    public function __construct(private ?string $root = null)
    {
        $this->root ??= config('practice.root');
    }

    /**
     * Validate a decoded set file. Returns a list of problems (empty = valid).
     *
     * @return string[]
     */
    public function validate(array $set): array
    {
        $errors = [];
        $code = $set['code'] ?? '?';
        $err = function (string $msg) use (&$errors, $code) {
            $errors[] = "[{$code}] {$msg}";
        };

        foreach (['code', 'title', 'source', 'section'] as $field) {
            if (! is_string($set[$field] ?? null) || trim($set[$field]) === '') {
                $err("missing {$field}");
            }
        }
        if (isset($set['source']) && ! in_array($set['source'], self::SOURCES, true)) {
            $err("unknown source {$set['source']}");
        }
        if (isset($set['section']) && ! in_array($set['section'], self::SECTIONS, true)) {
            $err("unknown section {$set['section']}");
        }

        $groupKeys = [];
        foreach ($set['groups'] ?? [] as $i => $g) {
            $key = $g['key'] ?? null;
            if (! is_string($key) || $key === '') {
                $err("group #{$i} has no key");
                continue;
            }
            if (isset($groupKeys[$key])) {
                $err("duplicate group key {$key}");
            }
            $groupKeys[$key] = true;
            foreach ((array) ($g['audio'] ?? []) as $path) {
                $this->checkMedia($path, "group {$key} audio", $err);
            }
            if (! empty($g['image'])) {
                $this->checkMedia($g['image'], "group {$key} image", $err);
            }
        }

        $skills = array_keys(config('practice.skills'));
        $numbers = [];
        foreach ($set['questions'] ?? [] as $q) {
            $n = $q['number'] ?? null;
            $label = 'Q' . ($n ?? '?');
            if (! is_int($n)) {
                $err("{$label}: number must be an integer");
                continue;
            }
            if (isset($numbers[$n])) {
                $err("{$label}: duplicate number");
            }
            $numbers[$n] = true;

            $part = $q['part'] ?? null;
            if (! is_int($part) || $part < 1 || $part > 7) {
                $err("{$label}: part must be 1–7");
                continue;
            }

            $options = $q['options'] ?? null;
            $want = $part === 2 ? 3 : 4;
            if (! is_array($options) || count($options) !== $want
                || count(array_filter($options, fn ($o) => is_string($o) && trim($o) !== '')) !== $want) {
                $err("{$label}: Part {$part} needs {$want} non-empty options");
            }

            $answer = $q['answer'] ?? null;
            if (! is_string($answer) || ! preg_match('/^[A-D]$/', $answer) || ord($answer) - 65 >= $want) {
                $err("{$label}: answer must be one of the {$want} option letters");
            }
            if (! in_array($q['answer_source'] ?? null, self::ANSWER_SOURCES, true)) {
                $err("{$label}: unknown answer_source");
            }
            if (isset($q['skill']) && ! in_array($q['skill'], $skills, true)) {
                $err("{$label}: unknown skill {$q['skill']}");
            }

            $group = $q['group'] ?? null;
            if ($group !== null && ! isset($groupKeys[$group])) {
                $err("{$label}: group {$group} is not defined");
            }
            if ($group === null && in_array($part, [1, 2, 3, 4, 6, 7], true)) {
                $err("{$label}: Part {$part} questions need a group (passage / audio)");
            }
            if (($part === 5 || $part === 7) && empty($q['stem'])) {
                $err("{$label}: Part {$part} needs a stem");
            }
        }

        foreach ($set['dictations'] ?? [] as $d) {
            $n = $d['number'] ?? '?';
            if (empty($d['transcript'])) {
                $err("dictation {$n}: missing transcript");
            }
            $this->checkMedia($d['audio'] ?? '', "dictation {$n} audio", $err);
        }

        if (empty($set['questions']) && empty($set['dictations'])) {
            $err('set has no questions or dictations');
        }

        return $errors;
    }

    /**
     * Import one validated set. Returns counts of what was written.
     *
     * @return array{questions: int, groups: int, dictations: int, removed: int, kept: int}
     */
    public function import(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $set = PracticeSet::updateOrCreate(['code' => $data['code']], [
                'title' => $data['title'],
                'source' => $data['source'],
                'section' => $data['section'],
                'week' => $data['week'] ?? null,
                'time_limit_seconds' => $data['time_limit_seconds']
                    ?? ($data['section'] === 'reading' ? config('practice.default_reading_seconds') : null),
                'sort' => $data['sort'] ?? 0,
            ]);

            $groupIds = [];
            foreach ($data['groups'] ?? [] as $i => $g) {
                $group = PracticeGroup::updateOrCreate(['set_id' => $set->id, 'key' => $g['key']], [
                    'label' => $g['label'] ?? null,
                    'body' => $g['body'] ?? null,
                    'audio' => isset($g['audio']) ? array_values((array) $g['audio']) : null,
                    'transcript' => $g['transcript'] ?? null,
                    'image_path' => $g['image'] ?? null,
                    'sort' => $i,
                ]);
                $groupIds[$g['key']] = $group->id;
            }

            $numbers = [];
            foreach (array_values($data['questions'] ?? []) as $i => $q) {
                PracticeQuestion::updateOrCreate(['set_id' => $set->id, 'number' => $q['number']], [
                    'group_id' => isset($q['group']) ? $groupIds[$q['group']] : null,
                    'part' => $q['part'],
                    'stem' => $q['stem'] ?? null,
                    'options' => array_values($q['options']),
                    'answer' => $q['answer'],
                    'answer_source' => $q['answer_source'],
                    'skill' => $q['skill'] ?? null,
                    'explanation' => $q['explanation'] ?? null,
                    'sort' => $i,
                ]);
                $numbers[] = $q['number'];
            }

            // questions no longer in the file: drop the unanswered ones, keep the
            // rest so a learner's history never loses its question
            $stale = PracticeQuestion::where('set_id', $set->id)->whereNotIn('number', $numbers)->get();
            $answered = PracticeAnswer::whereIn('question_id', $stale->pluck('id'))->distinct()->pluck('question_id')->all();
            $removed = 0;
            foreach ($stale as $q) {
                if (! in_array($q->id, $answered, true)) {
                    $q->delete();
                    $removed++;
                }
            }

            PracticeGroup::where('set_id', $set->id)
                ->whereNotIn('key', array_keys($groupIds))
                ->whereDoesntHave('questions')
                ->delete();

            $dictNumbers = [];
            foreach ($data['dictations'] ?? [] as $d) {
                PracticeDictation::updateOrCreate(['set_id' => $set->id, 'number' => $d['number']], [
                    'audio' => $d['audio'],
                    'transcript' => $d['transcript'],
                ]);
                $dictNumbers[] = $d['number'];
            }
            PracticeDictation::where('set_id', $set->id)->whereNotIn('number', $dictNumbers)->delete();

            return [
                'questions' => count($numbers),
                'groups' => count($groupIds),
                'dictations' => count($dictNumbers),
                'removed' => $removed,
                'kept' => count($answered),
            ];
        });
    }

    private function checkMedia(mixed $path, string $what, callable $err): void
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            $err("{$what}: invalid path");
            return;
        }
        if (! is_file($this->root . '/' . $path)) {
            $err("{$what}: file not found ({$path})");
        }
    }
}
