<?php

namespace App\Http\Controllers;

use App\Models\UserWord;
use Illuminate\Http\Request;

class VocabularyController extends Controller
{
    public function index(Request $request)
    {
        $query = UserWord::with('dictionary')->orderByDesc('created_at');

        if ($source = $request->query('source')) {
            $query->where('source', $source);
        }
        if ($tag = $request->query('tag')) {
            $query->where('tags', 'like', '%' . $tag . '%');
        }

        $words = $query->paginate(30)->withQueryString();

        $sources = UserWord::select('source')->distinct()->pluck('source');

        return view('vocabulary.index', compact('words', 'sources'));
    }

    public function update(Request $request, UserWord $vocabulary)
    {
        $data = $request->validate([
            'notes' => 'nullable|string|max:1000',
            'tags' => 'nullable|string|max:200',
        ]);

        $vocabulary->update($data);

        return back()->with('status', 'Updated “' . $vocabulary->word . '”');
    }

    public function destroy(UserWord $vocabulary)
    {
        $word = $vocabulary->word;
        $vocabulary->delete();

        return back()->with('status', 'Removed “' . $word . '”');
    }
}
