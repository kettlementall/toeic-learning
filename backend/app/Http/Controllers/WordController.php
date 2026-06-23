<?php

namespace App\Http\Controllers;

use App\Models\UserWord;
use App\Models\Word;
use App\Services\ClaudeService;
use App\Services\DictionaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class WordController extends Controller
{
    public function __construct(
        private DictionaryService $dictionary,
        private ClaudeService $claude,
    ) {
    }

    public function index()
    {
        return view('words.search', [
            'hasKey' => $this->claude->hasKey(),
        ]);
    }

    /**
     * On-the-fly example sentence for a word that has none. Generates one with
     * Claude, persists it to the words cache (so it's only generated once), and
     * returns it as JSON. Used as a fallback by the review/vocabulary cards.
     */
    public function example(Request $request)
    {
        $term = trim(strtolower((string) $request->query('q', '')));
        if ($term === '') {
            return response()->json(['error' => 'Missing word'], 422);
        }

        $word = Word::where('word', $term)->first() ?? $this->dictionary->lookup($term);

        if ($word && ! $word->example && $this->claude->hasKey()) {
            $example = $this->claude->generateExamples([$word->word])[$word->word] ?? null;
            if ($example) {
                $word->update(['example' => $example]);
            }
        }

        return response()->json(['example' => $word?->example]);
    }

    /**
     * On-the-fly memory aid / mnemonic for a word that has none. Generates one
     * with Claude, persists it to the words cache (so it's only generated once),
     * and returns it as JSON. Used by the search/vocabulary card buttons and the
     * review reveal fallback. Words acquired via lookup/quiz/news already get a
     * mnemonic folded into those AI calls.
     */
    public function mnemonic(Request $request)
    {
        $term = trim(strtolower((string) $request->query('q', '')));
        if ($term === '') {
            return response()->json(['error' => 'Missing word'], 422);
        }

        $word = Word::where('word', $term)->first() ?? $this->dictionary->lookup($term);

        if ($word && ! $word->mnemonic && $this->claude->hasKey()) {
            $mnemonic = $this->claude->generateMnemonics([
                ['word' => $word->word, 'definition_zh' => $word->definition_zh],
            ])[$word->word] ?? null;
            if ($mnemonic) {
                $word->update(['mnemonic' => $mnemonic]);
            }
        }

        return response()->json(['mnemonic' => $word?->mnemonic]);
    }

    /**
     * AJAX dictionary lookup. Auto-adds the word to the user's library.
     */
    public function lookup(Request $request)
    {
        $term = trim((string) $request->query('q', ''));
        if ($term === '') {
            return response()->json(['error' => 'Please enter a word to look up'], 422);
        }

        $word = $this->dictionary->lookup($term);

        if (! $word) {
            return response()->json(['error' => "Couldn't find “{$term}”. Please check the spelling."], 404);
        }

        // auto-add to vocabulary (source=search), init SRS to today
        $uw = UserWord::firstOrNew(['user_id' => auth()->id(), 'word' => $word->word]);
        $created = ! $uw->exists;
        if ($created) {
            $uw->word_id = $word->id;
            $uw->source = 'search';
            $uw->next_review_at = Carbon::today();
            $uw->save();
        }

        return response()->json([
            'word' => [
                'word' => $word->word,
                'phonetic' => $word->phonetic,
                'audio_url' => $word->audio_url,
                'part_of_speech' => $word->part_of_speech,
                'definition_en' => $word->definition_en,
                'definition_zh' => $word->definition_zh,
                'meanings' => $word->meanings ?? [],
                'example' => $word->example,
                'toeic_note' => $word->toeic_note,
                'mnemonic' => $word->mnemonic,
                'synonyms' => $word->synonyms,
            ],
            'added' => $created,
            'in_library' => true,
        ]);
    }
}
