<?php

namespace App\Http\Controllers;

/**
 * Streams practice-bank audio and photos to signed-in users.
 *
 * The files sit under config('practice.root') (not public/), so the course
 * material is only reachable through the app's login. response()->file() hands
 * back a BinaryFileResponse, which answers Range requests with 206 — the audio
 * element needs that to seek in practice mode.
 */
class PracticeMediaController extends Controller
{
    private const TYPES = ['mp3' => 'audio/mpeg', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];

    public function show(string $path)
    {
        $root = realpath(config('practice.root'));
        $full = $root ? realpath($root . '/' . $path) : false;

        // realpath() resolves ../ and symlinks, so this also blocks traversal
        abort_if(! $full || ! str_starts_with($full, $root . DIRECTORY_SEPARATOR) || ! is_file($full), 404);

        $type = self::TYPES[strtolower(pathinfo($full, PATHINFO_EXTENSION))] ?? null;
        abort_if($type === null, 404);

        return response()->file($full, [
            'Content-Type' => $type,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
