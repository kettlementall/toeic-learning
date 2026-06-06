<?php

namespace App\Models;

use App\Services\SpacedRepetitionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserWord extends Model
{
    protected $fillable = [
        'user_id', 'word_id', 'word', 'source', 'notes', 'tags',
        'ease_factor', 'interval_days', 'repetitions', 'lapses',
        'next_review_at', 'last_reviewed_at',
    ];

    protected $casts = [
        'ease_factor' => 'float',
        'interval_days' => 'integer',
        'repetitions' => 'integer',
        'lapses' => 'integer',
        'next_review_at' => 'datetime',
        'last_reviewed_at' => 'datetime',
    ];

    public function dictionary(): BelongsTo
    {
        return $this->belongsTo(Word::class, 'word_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Restrict to a given user's words (defaults to the logged-in user). */
    public function scopeForUser(Builder $query, ?int $userId = null): Builder
    {
        return $query->where('user_id', $userId ?? auth()->id());
    }

    public function scopeLeeches(Builder $query): Builder
    {
        return $query->where('lapses', '>=', SpacedRepetitionService::LEECH_THRESHOLD);
    }

    public function getIsLeechAttribute(): bool
    {
        return $this->lapses >= SpacedRepetitionService::LEECH_THRESHOLD;
    }
}
