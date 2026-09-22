@props([
    'text',
    'label' => 'نسخ النص',
    'done' => 'تم النسخ',
    'size' => 'sm',
])

{{--
    النسخ فعل بلا نتيجة مرئية، فيشك المستخدم أنه نجح.
    لذلك الزر يبدّل شكله ونصه لثانيتين، ويعلن النتيجة لقارئ الشاشة.
--}}
<div
    x-data="copyText(@js($text))"
    class="contents"
>
    <button
        type="button"
        @click="copy()"
        {{ $attributes->merge(['class' => 'btn btn-secondary btn-'.$size]) }}
        :class="copied && 'border-success/40 text-success-fg bg-success-soft'"
    >
        <x-icon name="copy" class="w-4 h-4" x-show="!copied" />
        <x-icon name="check" class="w-4 h-4" x-show="copied" x-cloak />
        <span x-text="copied ? @js($done) : @js($label)">{{ $label }}</span>
    </button>

    <span class="sr-only" role="status" aria-live="polite" x-text="copied ? @js($done) : ''"></span>
</div>
