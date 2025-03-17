<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Card extends Model
{
    protected $fillable = ['user_id', 'bank_id', 'number', 'color'];

    public $timestamps = false;

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }
}
