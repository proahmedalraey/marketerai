<?php

namespace App\Support;

/**
 * أسعار الباقات كما تُعرض في الصفحات العامة وصفحة التسجيل.
 * المصدر الوحيد config/marketing.php، فلا يتفرّق رقم بين قالبين.
 */
final class Offer
{
    /**
     * «$19» معزولًا باتجاه يسار-يمين (LRI…PDI): بدونه يقلبه النص العربي المحيط إلى «19$».
     */
    public static function money(float $amount): string
    {
        $value = floor($amount) == $amount ? (string) (int) $amount : number_format($amount, 2);

        return self::ltr(config('marketing.currency', '$').$value);
    }

    public static function ltr(string $text): string
    {
        return "\u{2066}{$text}\u{2069}";
    }

    public static function monthly(): string
    {
        return self::money((float) config('marketing.offer.monthly_price'));
    }

    public static function yearly(): string
    {
        return self::money((float) config('marketing.offer.yearly_price'));
    }

    /** السعر السنوي موزّعًا على 12 شهرًا، كما يُكتب على بطاقة الباقة. */
    public static function yearlyPerMonth(): string
    {
        return self::money(round((float) config('marketing.offer.yearly_price') / 12, 2));
    }

    /** نسبة التوفير الفعلية مقابل 12 دفعة شهرية — تُحسب ولا تُكتب يدويًا. */
    public static function yearlySaving(): int
    {
        $monthlyTotal = (float) config('marketing.offer.monthly_price') * 12;

        if ($monthlyTotal <= 0) {
            return 0;
        }

        return (int) round((1 - (float) config('marketing.offer.yearly_price') / $monthlyTotal) * 100);
    }

    public static function yearlySavingLabel(): string
    {
        return 'وفّر '.self::ltr(self::yearlySaving().'%');
    }

    public static function trialDays(): int
    {
        return (int) config('marketing.offer.trial_days');
    }

    public static function trialPrice(): string
    {
        return self::money((float) config('marketing.offer.trial_price'));
    }

    /** «جرّب 7 أيام بـ $1» — نص واحد لكل الأزرار التي تعرض التجربة. */
    public static function trialLabel(): string
    {
        return 'جرّب '.self::trialDays().' أيام بـ '.self::trialPrice();
    }
}
