<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    protected $fillable = ['product_id', 'disk', 'path', 'original_url', 'is_reference', 'position'];

    protected function casts(): array
    {
        return ['is_reference' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function url(): string
    {
        return $this->original_url ?: Storage::disk($this->disk)->url($this->path);
    }
}
