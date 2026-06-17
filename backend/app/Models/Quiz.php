<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quiz extends Model
{
    protected $fillable = [
        'user_id', 'title', 'type', 'scope', 'status', 'score', 'total',
        'ai_review', 'article', 'completed_at',
    ];

    protected $casts = [
        'ai_review' => 'array',
        'article' => 'array',
        'completed_at' => 'datetime',
        'score' => 'integer',
        'total' => 'integer',
    ];

    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class);
    }
}
