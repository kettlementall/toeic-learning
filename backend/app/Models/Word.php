<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Word extends Model
{
    protected $fillable = [
        'word', 'phonetic', 'audio_url', 'part_of_speech',
        'definition_en', 'definition_zh', 'meanings', 'example', 'toeic_note',
        'mnemonic', 'synonyms', 'level', 'category', 'source', 'raw_json',
    ];

    protected $casts = [
        'raw_json' => 'array',
        'meanings' => 'array',
        'level' => 'integer',
    ];

    public function userWords(): HasMany
    {
        return $this->hasMany(UserWord::class);
    }
}
