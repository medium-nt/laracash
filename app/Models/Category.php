<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Category extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'title',
        'user_id',
        'keywords'
    ];

    public function cards(): BelongsToMany
    {
        return $this->belongsToMany(Card::class, 'card_category_cashback', 'category_id', 'card_id')
            ->withPivot('cashback_percentage', 'mcc', 'updated_at');
    }

    public function cashbacks(): BelongsToMany
    {
        return $this->belongsToMany(Cashback::class, 'card_category_cashback', 'category_id', 'cashback_id');
    }
}
