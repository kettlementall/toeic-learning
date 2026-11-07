<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PracticeAnswer extends Model
{
    protected $fillable = ['attempt_id', 'user_id', 'question_id', 'choice', 'is_correct'];

    protected $casts = ['is_correct' => 'boolean'];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PracticeAttempt::class, 'attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(PracticeQuestion::class, 'question_id');
    }
}
