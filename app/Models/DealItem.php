<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DealItem extends Model
{
    protected $fillable = ['user_id', 'deal_id', 'product_id', 'name', 'unit_price', 'quantity', 'discount_pct'];

    protected $casts = ['unit_price' => 'decimal:2', 'quantity' => 'decimal:2', 'discount_pct' => 'decimal:2'];

    public function total(): float
    {
        return round((float) $this->unit_price * (float) $this->quantity * (1 - (float) $this->discount_pct / 100), 2);
    }
}
