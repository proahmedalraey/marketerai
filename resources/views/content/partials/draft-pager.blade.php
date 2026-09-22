{{-- تنقّل بين المسودات: السابق، نافذة أرقام حول الحالي، التالي. يقرأ حالة draftPager. --}}
<nav x-show="total > 1" class="flex items-center gap-1 rounded-full border border-line bg-card p-1 shadow-xs" aria-label="التنقل بين المحتويات">
    <button type="button" @click="go(page - 1)" :disabled="page === 0" class="grid place-items-center w-8 h-8 rounded-full text-fg-muted hover:bg-muted disabled:opacity-40 disabled:cursor-not-allowed">
        <x-icon name="chevron-right" class="w-4 h-4" />
        <span class="sr-only">المحتوى السابق</span>
    </button>

    <template x-for="n in pages" :key="n">
        <button
            type="button" @click="go(n)"
            class="grid place-items-center min-w-8 h-8 px-1 rounded-full text-xs font-semibold tnum transition"
            :class="n === page ? 'bg-brand-600 text-white' : 'text-fg-muted hover:bg-muted'"
            :aria-current="n === page ? 'page' : null"
            x-text="n + 1"
        ></button>
    </template>

    <button type="button" @click="go(page + 1)" :disabled="page === total - 1" class="grid place-items-center w-8 h-8 rounded-full text-fg-muted hover:bg-muted disabled:opacity-40 disabled:cursor-not-allowed">
        <x-icon name="chevron-left" class="w-4 h-4" />
        <span class="sr-only">المحتوى التالي</span>
    </button>
</nav>
