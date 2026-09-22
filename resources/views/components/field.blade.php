@props([
    'label',
    'name' => null,
    'for' => null,
    'required' => false,
    'hint' => null,
    'optional' => false,
])

@php
    $id = $for ?? $name;
    $hasError = $name && $errors->has($name);
@endphp

<div {{ $attributes->merge(['class' => 'min-w-0']) }}>
    <label @if ($id) for="{{ $id }}" @endif class="label">
        {{ $label }}
        @if ($required)
            <span class="text-danger-fg" aria-hidden="true">*</span>
            <span class="sr-only">(حقل مطلوب)</span>
        @elseif ($optional)
            <span class="font-normal text-fg-subtle">— اختياري</span>
        @endif
    </label>

    {{ $slot }}

    @if ($hasError)
        <p class="error-text" id="{{ $id }}-error">
            <x-icon name="alert-circle" class="w-3.5 h-3.5 mt-px" />
            <span>{{ $errors->first($name) }}</span>
        </p>
    @elseif ($hint)
        <p class="hint" id="{{ $id }}-hint">{{ $hint }}</p>
    @endif
</div>
