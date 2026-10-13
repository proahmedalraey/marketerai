<?php

namespace App\Support;

/**
 * روابط «تواصل معنا» والسوشيال ميديا في الصفحات العامة — كلها من config/marketing.php.
 * لا رابط يقود إلى لا شيء: القناة تفتح واتساب برسالتها إن وُجد رقم، وإلا البريد بعنوانها،
 * وإلا قسمها في صفحة /contact التي تشرح أن القنوات قيد الإعداد.
 */
final class Contact
{
    /** اسم المنصة كما يظهر نصًا بديلًا لأيقونتها، ومتغير .env الذي يضبط رابطها. */
    private const SOCIAL = [
        'instagram' => ['إنستغرام', 'instagram', 'MARKETING_INSTAGRAM'],
        'x' => ['إكس (تويتر)', 'x-logo', 'MARKETING_X'],
        'tiktok' => ['تيك توك', 'tiktok', 'MARKETING_TIKTOK'],
        'snapchat' => ['سناب شات', 'snapchat', 'MARKETING_SNAPCHAT'],
        'youtube' => ['يوتيوب', 'youtube', 'MARKETING_YOUTUBE'],
        'linkedin' => ['لينكدإن', 'linkedin', 'MARKETING_LINKEDIN'],
    ];

    /** @return array<string, array{label:string, icon:string, text:string, href:string}> */
    public static function channels(): array
    {
        return collect(config('marketing.channels'))
            ->map(fn (array $channel, string $key) => $channel + ['href' => self::link($key)])
            ->all();
    }

    public static function link(string $channel): string
    {
        return self::whatsappLink($channel) ?? self::emailLink($channel) ?? route('contact').'#'.$channel;
    }

    /** واتساب برسالة القناة جاهزة في خانة الكتابة. */
    public static function whatsappLink(string $channel): ?string
    {
        $number = self::whatsapp();

        return $number ? 'https://wa.me/'.$number.'?text='.rawurlencode(config("marketing.channels.$channel.message")) : null;
    }

    /** بريد بعنوان القناة، كي يصل إلى الجهة الصحيحة دون أن يكتب الزائر الموضوع. */
    public static function emailLink(string $channel): ?string
    {
        $email = config('marketing.contact.email');

        return $email ? 'mailto:'.$email.'?subject='.rawurlencode(config("marketing.channels.$channel.label").' — '.config('marketing.name')) : null;
    }

    public static function whatsapp(): ?string
    {
        $number = preg_replace('/\D/', '', (string) config('marketing.contact.whatsapp'));

        return $number !== '' ? $number : null;
    }

    public static function hasDirect(): bool
    {
        return self::whatsapp() !== null || filled(config('marketing.contact.email'));
    }

    /**
     * الحسابات المضبوطة فقط تظهر للزوار. في البيئة المحلية تظهر الفارغة أيضًا بلا رابط
     * ومُعلَّمة باسم متغيرها، كي يرى صاحب الموقع مكانها ويعرف ما يضبطه.
     *
     * @return list<array{label:string, icon:string, href:?string, env:string}>
     */
    public static function socials(): array
    {
        $local = app()->isLocal();

        return collect(self::SOCIAL)
            ->map(fn (array $meta, string $key) => [
                'label' => $meta[0],
                'icon' => $meta[1],
                'href' => config("marketing.social.$key") ?: null,
                'env' => $meta[2],
            ])
            ->filter(fn (array $social) => $social['href'] || $local)
            ->values()
            ->all();
    }
}
