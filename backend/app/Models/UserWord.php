<?php

namespace App\Models;

use App\Services\SpacedRepetitionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function logs(): HasMany
    {
        return $this->hasMany(ReviewLog::class);
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

    /**
     * Words the user looked up themselves and has not been tested on yet.
     * They jump the queue in quizzes and reviews; the first graded review
     * (sets last_reviewed_at) or failure (adds a lapse) ends that.
     */
    public function scopeFreshSearch(Builder $query): Builder
    {
        return $query->active()
            ->where('source', 'search')
            ->whereNull('last_reviewed_at')
            ->where(fn ($q) => $q->whereNull('lapses')->orWhere('lapses', 0));
    }

    /**
     * Cards today has not already finished with: never answered at all today,
     * or answered and **missed**. A miss belongs back in the queue — demote()
     * returns it to today on purpose — while a word already recalled correctly
     * has nothing left to give this sitting, so several quizzes in a row keep
     * finding material instead of re-asking what the last one covered.
     *
     * Expressed in SQL rather than as a word list so it stays one query no
     * matter how long the sitting runs.
     */
    public function scopeNotClearedToday(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q
            ->whereDoesntHave('logs', fn ($l) => $l->where('reviewed_at', '>=', today()))
            ->orWhereHas('logs', fn ($l) => $l
                ->where('reviewed_at', '>=', today())
                ->where('quality', '<', 3)
            )
        );
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

    /** PHP mirror of scopeFreshSearch(), for deciding on an already-loaded card. */
    public function getIsFreshSearchAttribute(): bool
    {
        return $this->source === 'search'
            && $this->last_reviewed_at === null
            && (int) ($this->lapses ?? 0) === 0;
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
