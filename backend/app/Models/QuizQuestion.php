<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizQuestion extends Model
{
    protected $fillable = [
        'quiz_id', 'word', 'grammar_point', 'question', 'options',
        'correct_answer', 'explanation', 'user_answer', 'is_correct',
    ];

    protected $casts = [
        'options' => 'array',
        'is_correct' => 'boolean',
    ];

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }
}
