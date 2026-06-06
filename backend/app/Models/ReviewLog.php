<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewLog extends Model
{
    protected $fillable = [
        'user_id', 'user_word_id', 'quiz_id', 'category', 'part_of_speech',
        'grammar_point', 'quality', 'reviewed_at',
    ];

    protected $casts = [
        'quality' => 'integer',
        'reviewed_at' => 'datetime',
    ];
}
