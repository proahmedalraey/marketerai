@props([
    'name',
    'label' => null,
    'stroke' => '1.75',
])

@php
    /**
     * أيقونات تحتاجها الصفحات العامة وحدها، على شبكة المجموعة المشتركة نفسها (24، سُمك 1.75).
     * ما ليس هنا يُمرَّر إلى <x-icon> كما هو، فلا تتكرر أيقونة بين الملفين.
     */
    $icons = [
        'rocket'    => '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>',
        'database'  => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/>',
        'shopping-cart' => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
        'share'     => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.59 13.51 6.83 3.98"/><path d="m15.41 6.51-6.82 3.98"/>',
        'handshake' => '<path d="m11 17 2 2a1 1 0 1 0 3-3"/><path d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"/><path d="m21 3 1 11h-2"/><path d="M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3"/><path d="M3 4h8"/>',
        'pointer-click' => '<path d="m9 9 5 12 1.8-5.2L21 14Z"/><path d="M7.2 2.2 8 5.1"/><path d="m5.1 8-2.9-.8"/><path d="M14 4.1 12 6"/><path d="m6 12-1.9 2"/>',
        'layout'    => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/>',
        'login'     => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/>',
    ];

    $hasOwnSize = (bool) preg_match('/(^|\s)(w-|h-|size-)/', (string) $attributes->get('class'));
@endphp

@if (isset($icons[$name]))
<svg
    {{ $attributes->merge(['class' => $hasOwnSize ? 'shrink-0' : 'w-5 h-5 shrink-0']) }}
    viewBox="0 0 24 24" fill="none" stroke="currentColor"
    stroke-width="{{ $stroke }}" stroke-linecap="round" stroke-linejoin="round"
    @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" focusable="false" @endif
>{!! $icons[$name] !!}</svg>
@else
<x-icon :name="$name" :label="$label" :stroke="$stroke" {{ $attributes }} />
@endif
