<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PracticeQuestion extends Model
{
    protected $fillable = [
        'set_id', 'group_id', 'number', 'part', 'stem', 'options', 'answer',
        'answer_source', 'skill', 'explanation', 'ai_explanation', 'ai_model', 'sort',
    ];

    protected $casts = [
        'options' => 'array',
        'number' => 'integer',
        'part' => 'integer',
        'sort' => 'integer',
    ];

    public function set(): BelongsTo
    {
        return $this->belongsTo(PracticeSet::class, 'set_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(PracticeGroup::class, 'group_id');
    }

    public function isListening(): bool
    {
        return $this->part <= 4;
    }
}
