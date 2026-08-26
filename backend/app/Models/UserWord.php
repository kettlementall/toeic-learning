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
        'next_review_at', 'last_reviewed_at', 'suspended_at',
    ];

    protected $casts = [
        'ease_factor' => 'float',
        'interval_days' => 'integer',
        'repetitions' => 'integer',
        'lapses' => 'integer',
        'next_review_at' => 'datetime',
        'last_reviewed_at' => 'datetime',
        'suspended_at' => 'datetime',
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

    /** Words still in the review rotation (i.e. not suspended as leeches). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('suspended_at');
    }

    /** Words pulled out of the rotation after failing too many times. */
    public function scopeSuspended(Builder $query): Builder
    {
        return $query->whereNotNull('suspended_at');
    }

    public function scopeLeeches(Builder $query): Builder
    {
        return $query->where('lapses', '>=', self::leechThreshold());
    }

    public function getIsLeechAttribute(): bool
    {
        return $this->lapses >= self::leechThreshold();
    }

    public function getIsSuspendedAttribute(): bool
    {
        return $this->suspended_at !== null;
    }

    private static function leechThreshold(): int
    {
        return (int) config('srs.leech_flag_at', SpacedRepetitionService::LEECH_THRESHOLD);
    }
}
