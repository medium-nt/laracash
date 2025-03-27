<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cashback extends Model
{
//    /** @use HasFactory<\Database\Factories\CashbackFactory> */
//    use HasFactory;

    protected $table = 'card_category_cashback';

    protected $fillable = [
        'card_id',
        'category_id',
        'cashback_percentage',
        'mcc',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class , 'card_id', 'id');
    }
}
