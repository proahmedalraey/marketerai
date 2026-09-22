<?php

namespace App\Models;

use App\Enums\ProductType;
use App\Models\Concerns\BelongsToBrand;
use App\Services\Content\ContentFacts;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Product extends Model
{
    use BelongsToBrand, HasFactory;

    protected $fillable = [
        'brand_id', 'type', 'title', 'brand_name', 'summary', 'spec_sheet',
        'features', 'specifications', 'audience', 'deliverables', 'notes',
        'price', 'compare_at_price', 'sale_ends_at', 'currency',
        'stock_status', 'rating_value', 'rating_count', 'installments',
        'sku', 'category', 'origin_country', 'attributes',
        'source', 'external_id', 'synced_at', 'is_primary', 'is_active',
    ];

    public const STOCK = [
        'in_stock' => 'متوفر',
        'out_of_stock' => 'نفد من المخزون',
        'preorder' => 'طلب مسبق',
    ];

    public const INSTALLMENT_PROVIDERS = [
        'tabby' => 'تابي',
        'tamara' => 'تمارا',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'attributes' => 'array',
            'installments' => 'array',
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'sale_ends_at' => 'date',
            'rating_value' => 'decimal:2',
            'rating_count' => 'integer',
            'synced_at' => 'datetime',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * مبلغ كما يُكتب لقارئ عربي: «75 ريال» لا «75.00 SAR».
     * النموذج ينقل ما يراه حرفياً، فالصيغة هنا هي صيغة المنشور.
     */
    public function money(float|int|string $value): string
    {
        $currency = strtoupper($this->currency ?: 'SAR');

        return ContentFacts::canonical((string) $value).' '.($currency === 'SAR' ? 'ريال' : $currency);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function testimonials(): HasMany
    {
        return $this->hasMany(Testimonial::class);
    }

    /** السعر المخفّض ساري: سعر قبل الخصم أعلى من الحالي، ولم ينتهِ. */
    public function saleIsRunning(): bool
    {
        return $this->price !== null
            && $this->compare_at_price !== null
            && (float) $this->compare_at_price > (float) $this->price
            && (! $this->sale_ends_at || $this->sale_ends_at->gte(today()));
    }

    /**
     * حقائق البيع كما تُقرأ يوم التوليد: الخصم الساري، التوفر، التقييم، التقسيط.
     *
     * لا تُخزَّن في spec_sheet لأنها تتقادم: خصم انتهى أمس لا يجوز أن يُذكر اليوم.
     * وتُكتب موجبةً فقط — «لا تقسيط» نصاً يجعل كلمة التقسيط «واردة» عند الفحص.
     *
     * @return array<int, string>
     */
    public function salesContext(): array
    {
        $money = fn ($value) => $this->money($value);

        $lines = [];

        if ($this->saleIsRunning()) {
            $lines[] = 'السعر قبل الخصم: '.$money($this->compare_at_price).'، والسعر الحالي '.$money($this->price)
                .($this->sale_ends_at ? ' حتى '.$this->sale_ends_at->locale('ar')->translatedFormat('j F Y') : '');
        }

        if ($this->stock_status && isset(self::STOCK[$this->stock_status])) {
            $lines[] = 'التوفر: '.self::STOCK[$this->stock_status];
        }

        if ($this->rating_value !== null && $this->rating_count) {
            $lines[] = 'تقييم المنتج في المتجر: '.ContentFacts::canonical((string) $this->rating_value)
                ." من 5 ({$this->rating_count} تقييماً)";
        }

        foreach ((array) $this->installments as $plan) {
            $provider = self::INSTALLMENT_PROVIDERS[$plan['provider'] ?? ''] ?? null;
            $count = (int) ($plan['count'] ?? 0);

            if (! $provider || $count < 2) {
                continue;
            }

            $each = $this->price !== null ? '، كل دفعة '.$money(round((float) $this->price / $count, 2)) : '';
            $lines[] = "التقسيط: {$provider} على {$count} دفعات{$each}";
        }

        return $lines;
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    public function referenceImage(): ?ProductImage
    {
        return $this->images()->where('is_reference', true)->first()
            ?? $this->images()->first();
    }

    /**
     * الوصف المختصر المعروض في البطاقة.
     * النموذج اليدوي لا يجمعه — يُشتق من المميزات (أو التسليمات للخدمة)،
     * ويستبدله الاستيراد بوصف المتجر لاحقاً.
     */
    public static function deriveSummary(array $data): ?string
    {
        $source = collect([
            $data['summary'] ?? null,
            $data['features'] ?? null,
            $data['deliverables'] ?? null,
        ])->first(fn ($value) => filled($value));

        if (blank($source)) {
            return null;
        }

        return Str::limit(trim(preg_replace('/\s+/u', ' ', $source)), 180);
    }

    /**
     * النص الذي يدخل البرومبت فعلياً.
     * نفضّل ورقة المواصفات المجهزة، وإلا نبني واحدة من الحقول المتاحة.
     */
    public function promptContext(): string
    {
        if (filled($this->spec_sheet)) {
            return $this->spec_sheet;
        }

        $lines = array_filter([
            "الاسم: {$this->title}",
            filled($this->brand_name) ? "العلامة التجارية: {$this->brand_name}" : null,
            filled($this->summary) ? "الوصف: {$this->summary}" : null,
            filled($this->features) ? "المميزات: {$this->features}" : null,
            filled($this->specifications) ? "المواصفات: {$this->specifications}" : null,
            filled($this->deliverables) ? "ما يحصل عليه العميل: {$this->deliverables}" : null,
            filled($this->audience) ? "الجمهور المستهدف: {$this->audience}" : null,
            $this->price ? 'السعر: '.$this->money($this->price) : null,
            filled($this->notes) ? "ملاحظات: {$this->notes}" : null,
        ]);

        return implode("\n", $lines);
    }
}
