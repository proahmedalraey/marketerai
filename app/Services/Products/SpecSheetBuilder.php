<?php

namespace App\Services\Products;

use App\Enums\ProductType;
use App\Models\Product;

/**
 * ورقة المنتج المرجعية.
 *
 * نخزن النص المجهز للنموذج لا البيانات الخام: يوفر توكنز في كل توليد لاحق
 * ويرفع ثبات المخرجات لأن كل منشور يقرأ نفس الوصف بالضبط.
 *
 * السلعة والخدمة لهما حقول مختلفة، فلكل منهما ترتيب مختلف في الورقة.
 */
class SpecSheetBuilder
{
    public function build(Product $product): string
    {
        $lines = ["• النوع: {$product->type->label()}"];
        $lines[] = "• الاسم: {$product->title}";

        if (filled($product->brand_name)) {
            $lines[] = "• العلامة التجارية: {$product->brand_name}";
        }

        if (filled($product->summary)) {
            $lines[] = '• نبذة: '.$this->clean($product->summary);
        }

        $lines = array_merge(
            $lines,
            $product->type === ProductType::Service
                ? $this->serviceLines($product)
                : $this->goodLines($product),
        );

        // الصفوف التي سبقت هذا الحقل لا تملك جمهوراً بعد، فنقرأ جمهور العلامة
        $audience = $product->audience ?: $product->brand?->audience;

        if (filled($audience)) {
            $lines[] = '• الجمهور المستهدف: '.$this->clean($audience);
        }

        if ($product->price !== null) {
            $lines[] = '• السعر: '.$product->money($product->price);
        }

        if (filled($product->notes)) {
            $lines[] = '• ملاحظات: '.$this->clean($product->notes);
        }

        // حقول تأتي من الاستيراد لا من النموذج اليدوي.
        // حقائق البيع (خصم، توفر، تقييم، تقسيط) ليست هنا: تتقادم، فتُقرأ حيّة (Product::salesContext)
        foreach (['category' => 'الفئة', 'origin_country' => 'بلد المنشأ', 'sku' => 'رمز المنتج (SKU)'] as $field => $label) {
            if (filled($product->{$field})) {
                $lines[] = "• {$label}: {$product->{$field}}";
            }
        }

        foreach ((array) $product->attributes as $key => $value) {
            if (is_scalar($value) && filled($value)) {
                $lines[] = "• {$key}: {$value}";
            }
        }

        return implode("\n", $lines);
    }

    /** @return array<int, string> */
    protected function goodLines(Product $product): array
    {
        $lines = [];

        if (filled($product->features)) {
            $lines[] = '• المميزات:'."\n".$this->bullets($product->features);
        }

        if (filled($product->specifications)) {
            $lines[] = '• المواصفات:'."\n".$this->bullets($product->specifications);
        }

        return $lines;
    }

    /** @return array<int, string> */
    protected function serviceLines(Product $product): array
    {
        $lines = [];

        if (filled($product->features)) {
            $lines[] = '• وصف الخدمة: '.$this->clean($product->features);
        }

        if (filled($product->deliverables)) {
            $lines[] = '• ما يحصل عليه العميل:'."\n".$this->bullets($product->deliverables);
        }

        return $lines;
    }

    /**
     * يحافظ على الأسطر كنقاط منفصلة: النموذج يلتزم بالتعداد أكثر من التزامه بفقرة مسترسلة.
     */
    protected function bullets(string $value): string
    {
        $lines = preg_split('/\R+/u', trim($value)) ?: [];

        return collect($lines)
            ->map(fn ($line) => trim(ltrim($line, "-•* \t")))
            ->filter()
            ->map(fn ($line) => "   - {$line}")
            ->implode("\n");
    }

    protected function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value));
    }
}
