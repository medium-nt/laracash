<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvailableAllCashback extends Model
{
//    /** @use HasFactory<\Database\Factories\CashbackFactory> */
//    use HasFactory;

    protected $table = 'card_category_all_available_cashback';

    protected $fillable = [
        'card_id',
        'category_id',
        'cashback_percentage',
        'is_check',
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
