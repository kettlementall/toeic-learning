<?php

namespace App\Http\Controllers;

use App\Services\PracticeNotes;

/**
 * Study notes from the course handouts (PracticeNotes), for signed-in users
 * only — like the rest of the course material.
 */
class PracticeNotesController extends Controller
{
    public function show(string $note, PracticeNotes $notes)
    {
        $found = $notes->find($note);
        abort_if($found === null, 404);

        return view('practice.notes', [
            'note' => $found,
            'all' => $notes->all(),
        ]);
    }
}
