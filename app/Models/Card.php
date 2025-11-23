<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Card extends Model
{
    protected $fillable = [
        'user_id',
        'bank_id',
        'number',
        'color',
        'cashback_image',
        'cashback_json'
    ];

    public $timestamps = false;

    protected $casts = [
        'cashback_json' => 'array',
    ];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'card_category_cashback', 'card_id', 'category_id')
            ->withPivot('cashback_percentage', 'mcc', 'updated_at');
    }

    public function cashbacks(): HasMany|Card
    {
        return $this->hasMany(Cashback::class, 'card_id', 'id');
    }
}
