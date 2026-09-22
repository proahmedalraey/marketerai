<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * تجربة عميل حقيقية أدخلها التاجر بإذن صاحبها. تُقتبس بنصها، لا تُعاد صياغتها.
 */
class Testimonial extends Model
{
    use BelongsToBrand;

    public const DISPLAY = [
        'full' => 'الاسم كاملاً',
        'first_name' => 'الاسم الأول فقط',
        'anonymous' => 'بلا اسم',
    ];

    protected $fillable = [
        'brand_id', 'product_id', 'author_name', 'display_as', 'body', 'rating', 'source', 'consented_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'consented_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** الاسم كما يُنشر: ما اختاره التاجر وأذن به العميل. */
    public function displayName(): string
    {
        return match ($this->display_as) {
            'full' => $this->author_name,
            'anonymous' => 'أحد العملاء',
            default => Str::before(trim($this->author_name), ' ') ?: $this->author_name,
        };
    }

    public function promptLine(): string
    {
        return '«'.trim($this->body).'» — '.$this->displayName()
            .($this->rating ? " (تقييمه {$this->rating} من 5)" : '');
    }
}
