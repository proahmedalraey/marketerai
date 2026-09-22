@props([
    'text',
    'label' => 'النص',
])

{{-- زر نسخ صغير بجانب عنوان قسم: الأيقونة تصير علامة صح لثانيتين، والنتيجة تُعلن لقارئ الشاشة --}}
<button
    type="button"
    x-data="copyText(@js($text))"
    @click="copy()"
    {{ $attributes->merge(['class' => 'btn-ghost btn-sm btn-icon -my-1']) }}
    title="نسخ {{ $label }}"
>
    <x-icon name="copy" class="w-4 h-4" x-show="!copied" />
    <x-icon name="check" class="w-4 h-4 text-success" x-show="copied" x-cloak />
    <span class="sr-only" x-text="copied ? 'نُسخ' : @js('نسخ '.$label)">نسخ {{ $label }}</span>
</button>
