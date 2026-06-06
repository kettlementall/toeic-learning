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
        $words = $this->srs->dueWords()->load('dictionary');

        return view('review.session', compact('words'));
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
        ]);
    }
}
