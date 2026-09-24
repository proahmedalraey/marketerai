/* ==========================================================================
   صفحة «استوديو الصور»
   دقة (1K/2K/4K) × جودة (5 مستويات) = مصفوفة تكلفة كسرية حقيقية من الخادم.
   مفتاحا الدقة والجودة يُركّبان محلياً في quality واحد ("1k_medium") يطابق
   مفاتيح config('ai.image_quality_matrix') وconfig('credits.costs') مباشرة.

   اختيار النموذج شكلي حالياً (قرار §2 في جلسة إعادة التصميم): لا يُرسَل مع
   النموذج، والتوليد الفعلي يستخدم دوماً المزوّد النشط في الإعدادات.
   ========================================================================== */

export default function registerImageStudio(Alpine) {
    Alpine.data('imageStudio', (config = {}) => ({
        resolutions: config.resolutions || {},
        qualityLevels: config.qualityLevels || {},
        qualityMatrix: config.qualityMatrix || {},
        ratios: config.ratios || {},
        models: config.models || {},

        prompt: config.prompt || '',
        resolution: config.resolution || '1k',
        quality: config.quality || 'medium',
        autoRatio: config.autoRatio ?? true,
        aspectRatio: config.aspectRatio || '1:1',
        count: config.count || 1,
        useBrandIdentity: config.useBrandIdentity ?? true,
        model: config.defaultModel || '',

        referenceMode: config.referenceMode || null, // null | 'product' | 'gallery'
        productId: config.productId || '',
        referenceAssetId: config.referenceAssetId || null,
        referenceAssetUrl: config.referenceAssetUrl || null,

        popover: null, // null | 'model' | 'quality' | 'ratio' | 'upload'
        lightbox: null, // null | { id, url, prompt }
        referencePickerOpen: false,

        uploadUrl: config.uploadUrl || '',
        uploading: false,
        uploadError: '',

        get qualityKey() {
            return `${this.resolution}_${this.quality}`;
        },

        get unitCost() {
            return this.qualityMatrix[this.qualityKey]?.credits ?? 0;
        },

        get totalCost() {
            const count = Math.max(1, Math.min(4, this.count || 1));

            return Math.round(this.unitCost * count * 10) / 10;
        },

        get totalCostLabel() {
            return Number.isInteger(this.totalCost) ? String(this.totalCost) : this.totalCost.toFixed(1);
        },

        costFor(key) {
            const value = this.qualityMatrix[`${this.resolution}_${key}`]?.credits ?? 0;

            return Number.isInteger(value) ? String(value) : value.toFixed(1);
        },

        togglePopover(name) {
            this.popover = this.popover === name ? null : name;
        },

        closePopovers() {
            this.popover = null;
        },

        setAutoRatio() {
            this.autoRatio = true;
            this.aspectRatio = '1:1';
            this.popover = null;
        },

        setRatio(key) {
            this.autoRatio = false;
            this.aspectRatio = key;
            this.popover = null;
        },

        clearPrompt() {
            this.prompt = '';
        },

        pickProduct(id) {
            this.productId = id;
            this.referenceMode = id ? 'product' : null;
            this.referenceAssetId = null;
            this.referenceAssetUrl = null;

            if (id) this.popover = null;
        },

        pickReferenceAsset(asset, mode = 'gallery') {
            this.referenceAssetId = asset.id;
            this.referenceAssetUrl = asset.url;
            this.referenceMode = mode; // 'gallery' | 'upload'
            this.productId = '';
            this.popover = null;
            this.referencePickerOpen = false;
        },

        clearReference() {
            this.referenceMode = null;
            this.productId = '';
            this.referenceAssetId = null;
            this.referenceAssetUrl = null;
        },

        openLightbox(asset) {
            this.lightbox = asset;
        },

        closeLightbox() {
            this.lightbox = null;
        },

        useAsReference() {
            if (! this.lightbox) return;

            this.pickReferenceAsset(this.lightbox);
            this.closeLightbox();
            this.$nextTick(() => document.getElementById('studio-prompt')?.focus());
        },

        async togglePin() {
            if (! this.lightbox?.pinUrl) return;

            const asset = this.lightbox;
            asset.pinned = ! asset.pinned; // تفاؤلي: يتراجع إن فشل الطلب

            try {
                const response = await fetch(asset.pinUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        Accept: 'application/json',
                    },
                });

                if (! response.ok) throw new Error('pin failed');

                const data = await response.json();
                asset.pinned = data.pinned;
            } catch (e) {
                asset.pinned = ! asset.pinned;
            }
        },

        async uploadReference(file) {
            if (! file) return;

            this.uploading = true;
            this.uploadError = '';
            this.popover = null;

            try {
                const body = new FormData();
                body.append('file', file);

                const response = await fetch(this.uploadUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        Accept: 'application/json',
                    },
                    body,
                });

                if (! response.ok) throw new Error('upload failed');

                this.pickReferenceAsset(await response.json(), 'upload');
            } catch (e) {
                this.uploadError = 'تعذّر رفع الصورة. جرّب صورة أصغر أو بصيغة PNG أو JPG أو WEBP.';
            } finally {
                this.uploading = false;
            }
        },
    }));
}
