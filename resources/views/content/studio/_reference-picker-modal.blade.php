{{-- اختيار صورة سابقة من معرض العلامة كمرجع بصري للتوليد الجديد. --}}
<div
    x-show="referencePickerOpen"
    x-cloak
    class="fixed inset-0 z-[70] overflow-y-auto"
    role="dialog"
    aria-modal="true"
    aria-labelledby="reference-picker-title"
>
    <div
        x-show="referencePickerOpen"
        x-transition.opacity.duration.150ms
        @click="referencePickerOpen = false"
        class="fixed inset-0 bg-scrim/50 backdrop-blur-sm"
        aria-hidden="true"
    ></div>

    <div class="relative min-h-full grid place-items-center p-4">
        <template x-if="referencePickerOpen">
            <div class="relative w-full max-w-2xl card shadow-pop motion-safe:animate-scale-in max-h-[85vh] flex flex-col">
                <header class="flex items-center justify-between gap-4 px-5 py-4 border-b border-line shrink-0">
                    <h2 id="reference-picker-title" class="text-base font-bold text-fg">اختر صورة كمرجع</h2>
                    <button type="button" @click="referencePickerOpen = false" class="btn btn-ghost btn-sm btn-icon">
                        <x-icon name="close" class="w-4 h-4" />
                        <span class="sr-only">إغلاق</span>
                    </button>
                </header>

                <div class="p-5 overflow-y-auto">
                    @if ($referenceLibrary->isEmpty())
                        <x-empty-state icon="image" title="لا صور بعد" description="ولّد صوراً أو ارفع واحدة من الجهاز لتتمكن من استخدامها كمرجع." />
                    @else
                        <div class="grid grid-cols-3 sm:grid-cols-4 gap-2.5">
                            @foreach ($referenceLibrary as $asset)
                                <button
                                    type="button"
                                    @click="pickReferenceAsset({ id: {{ $asset->id }}, url: @js($asset->url()) })"
                                    class="relative block w-full rounded-xl overflow-hidden border-2 transition"
                                    :class="referenceAssetId === {{ $asset->id }} ? 'border-brand-500' : 'border-line hover:border-brand-300'"
                                >
                                    <img
                                        src="{{ $asset->url() }}" alt="" loading="lazy" decoding="async"
                                        class="aspect-square w-full object-cover bg-muted"
                                    >
                                    <x-icon
                                        name="check-circle"
                                        class="absolute top-1.5 end-1.5 w-5 h-5 text-brand-600 bg-white rounded-full"
                                        x-show="referenceAssetId === {{ $asset->id }}"
                                    />
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </template>
    </div>
</div>
