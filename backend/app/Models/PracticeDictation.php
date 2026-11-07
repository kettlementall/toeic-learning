<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PracticeDictation extends Model
{
    protected $fillable = ['set_id', 'number', 'audio', 'transcript'];

    protected $casts = ['number' => 'integer'];

    public function set(): BelongsTo
    {
        return $this->belongsTo(PracticeSet::class, 'set_id');
    }
}
