/* ==========================================================================
   صفحة «استوديو الصور»
   دقة (1K/2K/4K) × جودة (5 مستويات) = مصفوفة تكلفة كسرية حقيقية من الخادم.
   مفتاحا الدقة والجودة يُركّبان محلياً في quality واحد ("1k_medium") يطابق
   مفاتيح config('ai.image_quality_matrix') وconfig('credits.costs') مباشرة.

   النموذج يُرسَل مع الطلب ويُستخدم فعلاً حين يكون مزود الصور OpenRouter
   (modelsRoutable). لكل نموذج قدرات (دقات/جودات) من OpenRouter: الخيارات غير
   المدعومة تُعطَّل ويُعاد ضبط الاختيار عند تبديل النموذج، فلا يُدفع ثمن ما
   لا يُنتَج. إعدادات المستخدم تُحفظ في المتصفح بين عمليات التوليد.
   ========================================================================== */

const STORAGE_KEY = 'studio.settings.v1';

export default function registerImageStudio(Alpine) {
    Alpine.data('imageStudio', (config = {}) => ({
        resolutions: config.resolutions || {},
        qualityLevels: config.qualityLevels || {},
        qualityMatrix: config.qualityMatrix || {},
        ratios: config.ratios || {},
        models: config.models || {},
        modelCaps: config.modelCaps || {},
        modelsRoutable: config.modelsRoutable ?? false,
        notice: '',
        noticeTimer: null,

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

        // تحسين الوصف بالذكاء: مهمة في الطابور نستطلعها، والأصل يبقى للتراجع
        enhanceUrl: config.enhanceUrl || '',
        enhanceCost: config.enhanceCost || 0,
        enhancing: false,
        enhanceError: '',
        enhancedFrom: null, // الوصف قبل التحسين
        enhancedText: null, // ما وضعه التحسين (للتراجع فقط ما دام لم يُعدَّل يدوياً)

        init() {
            // رجوع الخادم بخطأ (old input) يعلو المحفوظ محلياً
            if (! config.hasOld) this.restore();

            this.normalizeForModel(false);

            ['prompt', 'resolution', 'quality', 'autoRatio', 'aspectRatio', 'count', 'useBrandIdentity', 'model']
                .forEach((key) => this.$watch(key, () => this.persist()));

            // الحقل يكبر مع النص: الوصف المحسَّن فقرة كاملة لا سطر واحد
            this.$watch('prompt', () => this.$nextTick(() => this.fitPrompt()));
            this.$nextTick(() => this.fitPrompt());

            // الشريط يقع عند حافة الشاشة: القوائم المرتبطة بأزرارها تخرج منها (RTL)
            // فتُقصّ. نزيحها أفقياً بعد ظهورها حتى تبقى داخل الإطار.
            this.$watch('popover', (name) => {
                if (! name) return;

                // مرتان: الظهور قد يتأخر إطاراً (x-show + x-transition) في بعض المتصفحات
                this.$nextTick(() => this.keepPopoverInView());
                setTimeout(() => this.keepPopoverInView(), 120);
            });
        },

        fitPrompt() {
            const el = this.$refs.prompt;

            if (! el) return;

            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight + 2, 200) + 'px';
        },

        keepPopoverInView() {
            const margin = 8;
            const width = document.documentElement.clientWidth;

            document.querySelectorAll('[data-popover]').forEach((el) => {
                if (el.getClientRects().length === 0) return;

                // قياس نظيف: بلا إزاحة سابقة ولا انيميشن ظهور (scale) يقلّص المستطيل
                const animation = el.style.animation;
                el.style.translate = '';
                el.style.animation = 'none';
                const { left, right } = el.getBoundingClientRect();
                el.style.animation = animation;

                let shift = 0;
                if (right > width - margin) shift = width - margin - right;
                if (left + shift < margin) shift = margin - left;

                if (shift) el.style.translate = `${Math.round(shift)}px 0`;
            });
        },

        persist() {
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify({
                    prompt: this.prompt,
                    resolution: this.resolution,
                    quality: this.quality,
                    autoRatio: this.autoRatio,
                    aspectRatio: this.aspectRatio,
                    count: this.count,
                    useBrandIdentity: this.useBrandIdentity,
                    model: this.model,
                }));
            } catch (e) { /* تخزين معطّل أو ممتلئ: نكمل بلا حفظ */ }
        },

        restore() {
            let saved;

            try {
                saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
            } catch (e) {
                return;
            }

            if (! saved || typeof saved !== 'object') return;

            // نقبل القيمة فقط إن كانت لا تزال خياراً صالحاً (الإعدادات تتغير بين الزيارات)
            if (typeof saved.prompt === 'string') this.prompt = saved.prompt.slice(0, 1500);
            if (saved.resolution in this.resolutions) this.resolution = saved.resolution;
            if (saved.quality in this.qualityLevels) this.quality = saved.quality;
            if (saved.aspectRatio in this.ratios) {
                this.aspectRatio = saved.aspectRatio;
                this.autoRatio = saved.autoRatio === true && saved.aspectRatio === '1:1';
            }
            if (Number.isInteger(saved.count)) this.count = Math.max(1, Math.min(4, saved.count));
            if (typeof saved.useBrandIdentity === 'boolean') this.useBrandIdentity = saved.useBrandIdentity;
            if (this.modelsRoutable && saved.model in this.models) this.model = saved.model;
        },

        // ---------- قدرات النموذج ----------
        get caps() {
            return this.modelsRoutable ? (this.modelCaps[this.model] || {}) : {};
        },

        resolutionAllowed(key) {
            return ! this.caps.resolutions || this.caps.resolutions.includes(key);
        },

        qualityAllowed(key) {
            return ! this.caps.qualities || this.caps.qualities.includes(key);
        },

        /** وصف مختصر لما يدعمه نموذج (لقائمة النماذج). */
        capsLabel(modelKey) {
            const caps = this.modelsRoutable ? (this.modelCaps[modelKey] || {}) : {};
            const list = caps.resolutions;

            if (! list) return '';

            const top = list[list.length - 1]?.toUpperCase();

            return list.length === 1 ? `${top} فقط` : `حتى ${top}`;
        },

        selectModel(key) {
            this.model = key;
            this.popover = null;
            this.normalizeForModel(true);
        },

        /** بعد تبديل النموذج: أقرب دقة/جودة مدعومة، مع تنبيه إن تغيّر شيء. */
        normalizeForModel(announce) {
            const notes = [];

            if (! this.resolutionAllowed(this.resolution)) {
                const before = this.resolution.toUpperCase();
                this.resolution = this.caps.resolutions[0];
                notes.push(`الدقة ${before} غير مدعومة ← ${this.resolution.toUpperCase()}`);
            }

            if (! this.qualityAllowed(this.quality)) {
                const before = this.qualityLevels[this.quality]?.label;
                this.quality = this.caps.qualities.includes('medium') ? 'medium' : this.caps.qualities[0];
                notes.push(`«${before}» غير مدعومة ← «${this.qualityLevels[this.quality]?.label}»`);
            }

            this.notice = announce && notes.length ? `عُدّل لتناسب ${this.models[this.model]?.label}: ${notes.join('، ')}` : '';

            clearTimeout(this.noticeTimer);
            if (this.notice) this.noticeTimer = setTimeout(() => (this.notice = ''), 6000);
        },

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

        // ---------- تحسين الوصف بالذكاء ----------
        get canEnhance() {
            return this.prompt.trim().length >= 3 && ! this.enhancing;
        },

        get canUndoEnhance() {
            return this.enhancedFrom !== null && this.prompt === this.enhancedText;
        },

        get enhanceTitle() {
            if (this.enhancing) return 'يحسّن الوصف…';

            return 'حسّن الوصف بالذكاء الاصطناعي' + (this.enhanceCost > 0 ? ` (${this.enhanceCost} نقطة)` : ' — مجاناً');
        },

        async enhance() {
            if (! this.canEnhance || ! this.enhanceUrl) return;

            const original = this.prompt;

            this.enhancing = true;
            this.enhanceError = '';

            try {
                const response = await fetch(this.enhanceUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({
                        prompt: original,
                        aspect_ratio: this.aspectRatio,
                        product_id: this.referenceMode === 'product' ? this.productId || null : null,
                        reference_asset_id: this.referenceMode && this.referenceMode !== 'product' ? this.referenceAssetId : null,
                        use_brand_identity: this.useBrandIdentity,
                    }),
                });

                const body = await response.json().catch(() => ({}));

                if (! response.ok) {
                    const first = Object.values(body.errors || {})[0];
                    throw new Error(body.message || (Array.isArray(first) ? first[0] : '') || 'تعذّر بدء تحسين الوصف.');
                }

                const result = await this.pollEnhance(body.status_url);

                // كتب المستخدم شيئاً آخر أثناء الانتظار: لا نمحو ما كتبه
                if (this.prompt !== original) {
                    this.enhanceError = 'عدّلت الوصف أثناء التحسين، فلم نستبدله.';

                    return;
                }

                this.enhancedFrom = original;
                this.enhancedText = result.prompt;
                this.prompt = result.prompt;
                this.$nextTick(() => this.$refs.prompt?.focus());
            } catch (e) {
                this.enhanceError = e.message || 'تعذّر تحسين الوصف. حاول مرة أخرى.';
            } finally {
                this.enhancing = false;
            }
        },

        /** يستطلع المهمة كل ثانية (حتى دقيقتين) ويعيد نتيجتها أو يرمي رسالة الفشل. */
        async pollEnhance(statusUrl) {
            for (let i = 0; i < 120; i++) {
                await new Promise((resolve) => setTimeout(resolve, i < 3 ? 700 : 1000));

                const response = await fetch(statusUrl, { headers: { Accept: 'application/json' } });

                if (! response.ok) throw new Error('تعذّر متابعة تحسين الوصف.');

                const job = await response.json();

                if (['failed', 'cancelled'].includes(job.status)) {
                    throw new Error(job.error || 'تعذّر تحسين الوصف.');
                }

                if (['completed', 'partial'].includes(job.status)) {
                    if (! job.result?.prompt) throw new Error('لم يصل وصف محسَّن.');

                    return job.result;
                }
            }

            throw new Error('استغرق التحسين وقتاً أطول من المعتاد. جرّب مرة أخرى.');
        },

        undoEnhance() {
            if (! this.canUndoEnhance) return;

            this.prompt = this.enhancedFrom;
            this.enhancedFrom = null;
            this.enhancedText = null;
        },

        clearPrompt() {
            this.prompt = '';
            this.enhancedFrom = null;
            this.enhancedText = null;
            this.enhanceError = '';
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
