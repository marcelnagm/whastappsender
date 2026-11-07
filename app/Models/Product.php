<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['user_id', 'name', 'sku', 'unit', 'price', 'active'];

    protected $casts = ['price' => 'decimal:2', 'active' => 'boolean'];
}
