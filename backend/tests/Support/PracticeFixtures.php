<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * A throwaway practice media root with two made-up sets — no course material
 * in the test suite.
 */
trait PracticeFixtures
{
    protected string $practiceRoot;

    protected function setUpPracticeRoot(): void
    {
        $this->practiceRoot = sys_get_temp_dir() . '/practice-test-' . uniqid();
        File::ensureDirectoryExists($this->practiceRoot . '/audio/demo');
        file_put_contents($this->practiceRoot . '/audio/demo/q1.mp3', str_repeat('A', 4096));
        file_put_contents($this->practiceRoot . '/audio/demo/conv1.mp3', str_repeat('B', 4096));
        file_put_contents($this->practiceRoot . '/audio/demo/dict1.mp3', str_repeat('C', 1024));
        config(['practice.root' => $this->practiceRoot]);
    }

    protected function tearDownPracticeRoot(): void
    {
        File::deleteDirectory($this->practiceRoot);
    }

    protected function readingSet(array $overrides = []): array
    {
        return array_replace([
            'code' => 'demo-reading',
            'title' => 'Demo Reading',
            'source' => 'zhuifen',
            'section' => 'reading',
            'sort' => 1,
            'groups' => [
                ['key' => 'p131', 'label' => '131–132', 'body' => 'Dear staff, the office will --131-- on Friday. --132--.'],
            ],
            'questions' => [
                ['number' => 101, 'part' => 5, 'stem' => 'Staying ------ helps.', 'options' => ['fit', 'fitting', 'fitted', 'fitness'],
                    'answer' => 'A', 'answer_source' => 'verified', 'skill' => 'word_form', 'explanation' => '主詞補語用形容詞'],
                ['number' => 102, 'part' => 5, 'stem' => 'Please ------ the form.', 'options' => ['fill out', 'come', 'clear', 'write'],
                    'answer' => 'A', 'answer_source' => 'verified', 'skill' => 'collocation'],
                ['number' => 131, 'part' => 6, 'group' => 'p131', 'options' => ['close', 'closes', 'closed', 'closing'],
                    'answer' => 'A', 'answer_source' => 'handwriting', 'skill' => 'tense_voice'],
                ['number' => 132, 'part' => 6, 'group' => 'p131', 'options' => ['We apologize.', 'It rained.', 'Buy now.', 'See you.'],
                    'answer' => 'A', 'answer_source' => 'solved', 'skill' => 'sentence_insertion'],
            ],
        ], $overrides);
    }

    protected function listeningSet(array $overrides = []): array
    {
        return array_replace([
            'code' => 'demo-listening',
            'title' => 'Demo Listening',
            'source' => 'lecture',
            'section' => 'listening',
            'week' => 2,
            'groups' => [
                ['key' => 'q7', 'audio' => ['audio/demo/q1.mp3'], 'transcript' => 'Where is the bank? (A) Next to the post office. (B) Yes. (C) At noon.'],
                ['key' => 'c32', 'audio' => ['audio/demo/conv1.mp3'], 'transcript' => 'M: Hello…'],
            ],
            'questions' => [
                ['number' => 7, 'part' => 2, 'group' => 'q7', 'options' => ['Next to the post office.', 'Yes.', 'At noon.'],
                    'answer' => 'A', 'answer_source' => 'verified', 'skill' => 'wh_question'],
                ['number' => 32, 'part' => 3, 'group' => 'c32', 'stem' => 'Where are the speakers?',
                    'options' => ['At a bank', 'At a store', 'At a hotel', 'At a station'],
                    'answer' => 'C', 'answer_source' => 'verified', 'skill' => 'speaker_place'],
            ],
        ], $overrides);
    }
}
