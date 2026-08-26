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
        'frequency_rank',
    ];

    protected $casts = [
        'raw_json' => 'array',
        'meanings' => 'array',
        'level' => 'integer',
        'frequency_rank' => 'integer',
    ];

    /** Words from the curated TOEIC high-frequency list, most common first. */
    public function scopeToeicCore($query)
    {
        return $query->where('source', self::SOURCE_TOEIC_CORE)->orderBy('frequency_rank');
    }

    public const SOURCE_TOEIC_CORE = 'toeic_core';

    public function userWords(): HasMany
    {
        return $this->hasMany(UserWord::class);
    }
}
