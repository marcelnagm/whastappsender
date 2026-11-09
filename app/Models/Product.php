<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['user_id', 'name', 'sku', 'unit', 'price', 'active'];

    protected static function booted(): void
    {
        // the AI's copy of the catalogue follows it (only for accounts that imported their products)
        static::saved(fn (Product $p) => app(\App\Services\Rag\KnowledgeSync::class)->products($p->user_id));
        static::deleted(fn (Product $p) => app(\App\Services\Rag\KnowledgeSync::class)->products($p->user_id));
    }

    protected $casts = ['price' => 'decimal:2', 'active' => 'boolean'];
}
