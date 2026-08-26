<?php

namespace App\Http\Controllers;

use App\Models\UserWord;
use App\Services\SpacedRepetitionService;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function __construct(private SpacedRepetitionService $srs)
    {
    }

    public function session()
    {
        // dueWords() rebalances first, so the queue is always a day's worth
        $words = $this->srs->dueWords()->load('dictionary');
        $backlog = $this->srs->backlogCount();

        return view('review.session', compact('words', 'backlog'));
    }

    public function grade(Request $request, UserWord $userWord)
    {
        abort_if($userWord->user_id !== auth()->id(), 403);

        $data = $request->validate([
            'quality' => 'required|integer|min:0|max:5',
        ]);

        $this->srs->grade($userWord, $data['quality']);

        return response()->json([
            'ok' => true,
            'next_review_at' => $userWord->next_review_at?->toDateString(),
            'interval_days' => $userWord->interval_days,
            'suspended' => $userWord->suspended_at !== null,
        ]);
    }

    /** Put a suspended (leech) word back into the rotation, relearning it. */
    public function resume(UserWord $userWord)
    {
        abort_if($userWord->user_id !== auth()->id(), 403);

        $this->srs->resume($userWord);

        return back()->with('status', "\"{$userWord->word}\" is back in the review rotation.");
    }
}
