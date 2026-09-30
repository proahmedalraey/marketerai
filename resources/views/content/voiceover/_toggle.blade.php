{{--
    زر طي القسم: دائرة بظل، وعلامة «+» تصير «−» حين يُفتح (الخط العمودي ينطوي).
    $open: تعبير Alpine لحالة الفتح، مثل 'open.content'. الزر الأب يحمل aria-expanded.
--}}
<span class="vo-toggle {{ $class ?? '' }}" aria-hidden="true">
    <svg viewBox="0 0 24 24" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round">
        <path d="M6 12h12" />
        <path d="M12 6v12" class="vo-toggle-bar" x-bind:class="{{ $open }} && 'scale-y-0 opacity-0'" />
    </svg>
</span>
