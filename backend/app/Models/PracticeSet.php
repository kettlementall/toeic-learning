<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PracticeSet extends Model
{
    protected $fillable = ['code', 'title', 'source', 'section', 'week', 'time_limit_seconds', 'sort'];

    protected $casts = [
        'week' => 'integer',
        'time_limit_seconds' => 'integer',
        'sort' => 'integer',
    ];

    public function groups(): HasMany
    {
        return $this->hasMany(PracticeGroup::class, 'set_id')->orderBy('sort');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(PracticeQuestion::class, 'set_id')->orderBy('sort');
    }

    public function dictations(): HasMany
    {
        return $this->hasMany(PracticeDictation::class, 'set_id')->orderBy('number');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PracticeAttempt::class, 'set_id');
    }
}
