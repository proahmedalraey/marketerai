<?php

namespace App\Enums;

/**
 * البنية التي يُكتب بها المحتوى. الشكل كما اختاره التاجر («Reels»، «سنابة واحدة»)
 * يُحفظ في عمود variant؛ هذه القيمة تحدد المخطط والفحص والعرض.
 */
enum ContentFormat: string
{
    case Post = 'post';
    case Carousel = 'carousel';
    case Reel = 'reel';
    case Thread = 'thread';
    case Story = 'story';
    case Infographic = 'infographic';

    public function label(): string
    {
        return match ($this) {
            self::Post => 'منشور',
            self::Carousel => 'كاروسيل',
            self::Reel => 'فيديو',
            self::Thread => 'ثريد',
            self::Story => 'ستوري',
            self::Infographic => 'إنفوجرافيك',
        };
    }

    /**
     * اسم الأيقونة في مكوّن <x-icon>.
     * الإيموجي تُرسم بخط النظام فتختلف بين ويندوز وآيفون ولا ترث لون النص.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Post => 'file-text',
            self::Carousel => 'layers',
            self::Reel => 'video',
            self::Thread => 'thread',
            self::Story => 'smartphone',
            self::Infographic => 'list',
        };
    }

    public function creditOperation(): string
    {
        return match ($this) {
            self::Carousel => 'content.carousel',
            self::Reel => 'content.reel_script',
            self::Story => 'content.story',
            self::Infographic => 'content.infographic',
            default => 'content.post',
        };
    }
}
