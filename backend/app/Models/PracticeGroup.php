<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PracticeGroup extends Model
{
    protected $fillable = ['set_id', 'key', 'label', 'body', 'audio', 'transcript', 'image_path', 'sort'];

    protected $casts = [
        'audio' => 'array',
        'sort' => 'integer',
    ];

    public function set(): BelongsTo
    {
        return $this->belongsTo(PracticeSet::class, 'set_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(PracticeQuestion::class, 'group_id')->orderBy('sort');
    }
}
