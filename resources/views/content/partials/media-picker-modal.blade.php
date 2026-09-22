{{-- اختيار صورة موجودة من معرض العلامة وإرفاقها بالمحتوى المختار في مودال الجدولة. --}}
<div
    x-show="mediaOpen"
    x-cloak
    class="fixed inset-0 z-[70] overflow-y-auto"
    role="dialog"
    aria-modal="true"
    aria-labelledby="media-picker-title"
>
    <div
        x-show="mediaOpen"
        x-transition.opacity.duration.150ms
        @click="closeMedia()"
        class="fixed inset-0 bg-scrim/50 backdrop-blur-sm"
        aria-hidden="true"
    ></div>

    <div class="relative min-h-full grid place-items-center p-4">
        <template x-if="mediaOpen">
            <div class="relative w-full max-w-2xl card shadow-pop motion-safe:animate-scale-in max-h-[85vh] flex flex-col">
                <header class="flex items-center justify-between gap-4 px-5 py-4 border-b border-line shrink-0">
                    <h2 id="media-picker-title" class="text-base font-bold text-fg">اختر من الاستوديو</h2>
                    <button type="button" @click="closeMedia()" class="btn btn-ghost btn-sm btn-icon">
                        <x-icon name="close" class="w-4 h-4" />
                        <span class="sr-only">إغلاق</span>
                    </button>
                </header>

                <div class="p-5 overflow-y-auto">
                    @if ($mediaGallery->isEmpty())
                        <x-empty-state icon="image" title="لا صور في الاستوديو بعد" description="ولّد صوراً من صفحة استوديو الصور أولاً." />
                    @else
                        <div class="grid grid-cols-3 sm:grid-cols-4 gap-2.5">
                            @foreach ($mediaGallery as $asset)
                                <form method="POST" :action="'{{ url('/studio') }}/' + contentItemId + '/attach'" data-busy-on-submit>
                                    @csrf
                                    <input type="hidden" name="media_asset_id" value="{{ $asset->id }}">
                                    <button type="submit" class="block w-full rounded-xl overflow-hidden border border-line hover:border-brand-400 transition">
                                        <img
                                            src="{{ $asset->url() }}" alt="" loading="lazy" decoding="async"
                                            class="aspect-square w-full object-cover bg-muted"
                                        >
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </template>
    </div>
</div>
