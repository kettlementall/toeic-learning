<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class PracticeAttempt extends Model
{
    protected $fillable = [
        'user_id', 'set_id', 'mode', 'section', 'question_ids', 'status', 'score',
        'total', 'time_limit_seconds', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'question_ids' => 'array',
        'score' => 'integer',
        'total' => 'integer',
        'time_limit_seconds' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function set(): BelongsTo
    {
        return $this->belongsTo(PracticeSet::class, 'set_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(PracticeAnswer::class, 'attempt_id');
    }

    /** The attempt's questions in presentation order, with their groups. */
    public function orderedQuestions(): Collection
    {
        $ids = $this->question_ids ?? [];
        $byId = PracticeQuestion::with('group')->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $byId->get($id))->filter()->values();
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
