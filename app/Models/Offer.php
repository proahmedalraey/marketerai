<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use App\Services\Content\ContentFacts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * عرض أو كود خصم أدخله التاجر. المصدر الوحيد لأي خصم يذكره المحتوى.
 */
class Offer extends Model
{
    use BelongsToBrand;

    public const TYPES = [
        'percent' => 'خصم بنسبة',
        'amount' => 'خصم بمبلغ',
        'free_shipping' => 'شحن مجاني',
        'other' => 'عرض آخر',
    ];

    protected $fillable = [
        'brand_id', 'product_id', 'title', 'type', 'value', 'coupon_code',
        'conditions', 'starts_at', 'ends_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** سارٍ في هذا اليوم: مفعّل، وبدأ، ولم ينتهِ. */
    public function scopeRunningOn(Builder $query, Carbon|string|null $day = null): Builder
    {
        $day = Carbon::parse($day ?? today())->toDateString();

        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhereDate('starts_at', '<=', $day))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $day));
    }

    /** عروض المتجر كله + عروض هذا المنتج. */
    public function scopeApplyingTo(Builder $query, ?Product $product): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('product_id')
            ->when($product?->id, fn ($q) => $q->orWhere('product_id', $product->id)));
    }

    public function isRunningOn(Carbon|string|null $day = null): bool
    {
        $day = Carbon::parse($day ?? today())->startOfDay();

        return $this->is_active
            && (! $this->starts_at || $this->starts_at->lte($day))
            && (! $this->ends_at || $this->ends_at->gte($day));
    }

    /**
     * العرض كما يقرؤه النموذج: كل ما يجوز ذكره، ولا شيء غيره.
     * «خصم اليوم الوطني: 15% بكود KSA96، حتى 30 سبتمبر 2026. للطلبات فوق 200 ريال»
     */
    public function promptLine(): string
    {
        $value = $this->value !== null ? ContentFacts::canonical((string) $this->value) : null;

        $what = match ($this->type) {
            'percent' => $value ? "خصم {$value}%" : 'خصم',
            'amount' => $value ? "خصم {$value} ريال" : 'خصم',
            'free_shipping' => 'شحن مجاني',
            default => null,
        };

        $parts = array_filter([
            $this->title.($what && ! str_contains($this->title, $what) ? ": {$what}" : ''),
            filled($this->coupon_code) ? "بكود {$this->coupon_code}" : null,
            $this->ends_at ? 'حتى '.$this->ends_at->locale('ar')->translatedFormat('j F Y') : null,
        ]);

        $line = implode('، ', $parts);

        return filled($this->conditions) ? "{$line}. الشروط: {$this->conditions}" : $line;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
