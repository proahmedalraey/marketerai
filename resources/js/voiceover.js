/* ==========================================================================
   التعليق الصوتي (Beta)
   نمط الإلقاء ← المحتوى (نص جديد أو من الخطة) ← اللغة واللهجة ← المذيع والجودة.

   كل توليد مهمة طابور: الواجهة ترسل بـ fetch وتستطلع /api/jobs/{uuid} ثم تجلب السجل،
   فلا تضيع الإعدادات بإعادة تحميل. الدفعة والمفضّلون يعيشون في المتصفح.
   ========================================================================== */

const TAG_PATTERN = /\[[^\]\n]{1,40}\]/gu;

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

const spoken = (text) => (text || '').replace(TAG_PATTERN, '').trim();

const countWords = (text) => spoken(text).split(/\s+/u).filter(Boolean).length;

const readStore = (key, fallback) => {
    try {
        const raw = localStorage.getItem(key);
        return raw ? JSON.parse(raw) : fallback;
    } catch {
        return fallback;
    }
};

const writeStore = (key, value) => {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // تصفح خاص أو تخزين محجوب: تبقى القيمة لهذه الجلسة فقط
    }
};

async function request(url, { method = 'GET', body = null } = {}) {
    const res = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf(),
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : null,
    });

    let data = {};
    try {
        data = await res.json();
    } catch {
        // رد بلا JSON (خطأ خادم): الرسالة العامة أدناه
    }

    if (!res.ok && res.status !== 207) {
        const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        const error = new Error(first || data.message || 'تعذّر الاتصال بالخادم. جرّب مرة أخرى.');
        error.status = res.status;
        throw error;
    }

    return { status: res.status, data };
}

/** يستطلع مهمة حتى تنتهي؛ يعيد بيانات نهايتها (completed/failed/timeout). */
const waitForJob = (uuid, onUpdate) => new Promise((resolve) => {
    window.pollJob(uuid, { onUpdate, onDone: resolve, timeout: 300000 });
});

export default function registerVoiceover(Alpine) {
    Alpine.data('voiceoverStudio', (config = {}) => ({
        voices: config.voices || [],
        styles: config.styles || {},
        dialects: config.dialects || {},
        accents: config.accents || {},
        tiers: config.tiers || {},
        minuteCosts: config.minuteCosts || {},
        wordsPerMinute: config.wordsPerMinute || 120,
        maxChars: config.maxChars || 2000,
        maxBatch: config.maxBatch || 10,
        retentionDays: config.retentionDays || 30,
        planItems: config.planItems || [],
        urls: config.urls || {},
        speechReady: !!config.speechReady,
        balance: config.balance ?? 0,

        // ---------- الإعدادات ----------
        style: config.defaults?.style || 'reels',
        customStyle: '',
        source: 'new',
        text: '',
        planId: null,
        planText: '',
        planSearch: '',
        planPlatform: 'all',
        language: 'ar',
        dialect: config.defaults?.dialect || 'auto',
        variant: null,
        accent: config.defaults?.accent || 'american',
        gender: 'all',
        voiceSearch: '',
        voice: config.defaults?.voice || null,
        tier: config.defaults?.tier || 'hd',
        favorites: [],
        open: { content: true, language: true, voice: true },
        howOpen: false,

        // ---------- الأدوات ----------
        busy: { enhance: false, diacritize: false, direction: false },
        undo: null,
        toolError: '',
        toolErrorFor: null,
        listening: false,
        recognition: null,
        dictationSupported: !!(window.SpeechRecognition || window.webkitSpeechRecognition),

        // ---------- التوليد والدفعة ----------
        batch: [],
        generating: false,
        showErrors: false,
        error: '',
        notice: '',
        announcement: '',

        // ---------- السجل والمشغّل ----------
        history: config.history || [],
        running: (config.running || []).map((job) => ({ ...job, progress: 8, stage: 'في الانتظار' })),
        historyOpen: true,
        refreshing: false,
        player: { id: null, playing: false, current: 0, duration: 0, rate: 1, peaks: [] },
        peaksCache: {},
        sample: { voice: null, loading: false, playing: false },
        sampleAudio: null,

        init() {
            this.batch = readStore(config.storageKey, []);
            this.favorites = readStore('voiceover.favorites', []);

            const saudi = this.dialects.saudi;
            this.variant = saudi?.defaultVariant || null;

            // مهام بدأت قبل تحديث الصفحة: نتابعها حتى تنتهي
            this.running.forEach((job) => this.track(job.uuid));
        },

        // =====================================================================
        // المحتوى
        // =====================================================================
        get activeText() {
            return this.source === 'plan' ? this.planText : this.text;
        },

        set activeText(value) {
            if (this.source === 'plan') this.planText = value;
            else this.text = value;
        },

        get hasText() {
            return spoken(this.activeText).length > 0;
        },

        get charCount() {
            return spoken(this.activeText).length;
        },

        get wordCount() {
            return countWords(this.activeText);
        },

        get overLimit() {
            return this.charCount > this.maxChars;
        },

        get planPlatforms() {
            const counts = {};
            this.planItems.forEach((item) => {
                counts[item.platform] ??= { label: item.platform_label, count: 0 };
                counts[item.platform].count += 1;
            });
            return counts;
        },

        get filteredPlan() {
            const q = this.planSearch.trim();
            return this.planItems.filter((item) => (this.planPlatform === 'all' || item.platform === this.planPlatform)
                && (!q || [item.title, item.subtitle, item.text].some((field) => (field || '').includes(q))));
        },

        choosePlan(item) {
            if (this.planId === item.id) return;

            this.planId = item.id;
            this.planText = item.text;
            this.undo = null;

            if (item.language !== this.language) this.setLanguage(item.language);

            this.$nextTick(() => this.$refs.planEditor?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
        },

        clearPlan() {
            this.planId = null;
            this.planText = '';
            this.undo = null;
        },

        // ---------- أدوات النص ----------
        async runTool(tool) {
            const text = this.activeText;

            this.toolErrorFor = tool;

            if (spoken(text).length < 3) {
                this.toolError = tool === 'direction'
                    ? 'اكتب النص في قسم «المحتوى» أولاً: التوجيه يُقترح منه.'
                    : 'اكتب النص أولاً ثم استخدم الأداة.';
                return;
            }

            this.toolError = '';
            this.busy[tool] = true;

            try {
                const { data } = await request(this.urls.tool.replace('__tool__', tool), {
                    method: 'POST',
                    body: { text, style: this.style, language: this.language },
                });

                const done = await waitForJob(data.uuid);

                if (done.status !== 'completed') {
                    this.toolError = done.status === 'timeout'
                        ? 'يستغرق أطول من المعتاد. جرّب بعد قليل.'
                        : (done.error || 'تعذّر تنفيذ الأداة.');
                    return;
                }

                const result = done.result?.text || '';

                if (tool === 'direction') {
                    this.customStyle = result;
                    this.announce('كُتبت توجيهات الإلقاء.');
                    return;
                }

                // التراجع يبقى حتى يكتب التاجر بنفسه (@input يمسحه)
                this.undo = { text: this.activeText, source: this.source, tool };
                this.activeText = result;
                this.announce(tool === 'enhance' ? 'جُهّز النص للإلقاء دون تغيير كلماته.' : 'شُكّل النص.');
            } catch (e) {
                this.toolError = e.message;
            } finally {
                this.busy[tool] = false;
            }
        },

        undoTool() {
            if (!this.undo) return;
            const { text, source } = this.undo;
            if (source === 'plan') this.planText = text;
            else this.text = text;
            this.undo = null;
        },

        toggleDictation() {
            if (this.listening) {
                this.recognition?.stop();
                return;
            }

            const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!Recognition) return;

            const recognition = new Recognition();
            recognition.lang = this.language === 'en' ? 'en-US' : 'ar-SA';
            recognition.continuous = true;
            recognition.interimResults = false;

            recognition.onresult = (event) => {
                const heard = Array.from(event.results)
                    .slice(event.resultIndex)
                    .filter((result) => result.isFinal)
                    .map((result) => result[0].transcript.trim())
                    .join(' ');

                if (!heard) return;

                const current = this.activeText.trimEnd();
                this.activeText = (current ? `${current} ` : '') + heard;
            };

            recognition.onerror = (event) => {
                this.toolErrorFor = 'dictation';
                this.toolError = event.error === 'not-allowed'
                    ? 'اسمح للمتصفح باستخدام الميكروفون لتعمل الإملاء.'
                    : 'توقفت الإملاء. جرّب مرة أخرى.';
            };

            recognition.onend = () => {
                this.listening = false;
                this.recognition = null;
            };

            this.toolError = '';
            this.recognition = recognition;
            this.listening = true;
            recognition.start();
        },

        // =====================================================================
        // النمط واللغة والمذيع
        // =====================================================================
        get styleLabel() {
            return this.styles[this.style]?.label || '';
        },

        setLanguage(language) {
            this.language = language;
            this.sample.voice = null;
            this.stopSample();
        },

        chooseDialect(key) {
            this.dialect = key;
            if (key === 'saudi' && !this.variant) this.variant = this.dialects.saudi?.defaultVariant || null;
        },

        chooseVariant(key) {
            this.dialect = 'saudi';
            this.variant = key;
        },

        get languageSummary() {
            if (this.language === 'en') {
                return `English — ${this.accents[this.accent]?.en || ''}`;
            }

            const dialect = this.dialects[this.dialect];
            if (!dialect) return '';

            const variant = this.dialect === 'saudi' && this.variant ? dialect.variants?.[this.variant] : null;
            return variant ? `${dialect.label} ${variant}` : dialect.label;
        },

        get filteredVoices() {
            const q = this.voiceSearch.trim().toLowerCase();

            return this.voices
                .filter((v) => (this.gender === 'all' || v.gender === this.gender)
                    && (!q || v.name.includes(q) || v.en.toLowerCase().includes(q) || v.tone.includes(q)))
                .sort((a, b) => Number(this.isFavorite(b.key)) - Number(this.isFavorite(a.key)));
        },

        get selectedVoice() {
            return this.voices.find((v) => v.key === this.voice) || null;
        },

        hue(key) {
            const index = Math.max(0, this.voices.findIndex((v) => v.key === key));
            return (index * 47 + 12) % 360;
        },

        isFavorite(key) {
            return this.favorites.includes(key);
        },

        toggleFavorite(key) {
            this.favorites = this.isFavorite(key)
                ? this.favorites.filter((k) => k !== key)
                : [...this.favorites, key];
            writeStore('voiceover.favorites', this.favorites);
        },

        // ---------- عينة «استمع» ----------
        async previewVoice(voice) {
            if (this.sample.voice === voice.key && (this.sample.playing || this.sample.loading)) {
                this.stopSample();
                return;
            }

            this.stopSample();
            this.pause();
            this.sample = { voice: voice.key, loading: true, playing: false };

            const url = this.urls.sample.replace('__voice__', voice.key) + `?language=${this.language}`;
            const startedAt = Date.now();

            try {
                let result = await request(url);

                // العينة تُجهَّز في الطابور أول مرة: نعيد السؤال حتى تجهز
                while (!result.data.url && Date.now() - startedAt < 45000) {
                    if (this.sample.voice !== voice.key) return;
                    await new Promise((r) => setTimeout(r, 1500));
                    result = await request(url);
                }

                if (this.sample.voice !== voice.key) return;

                if (!result.data.url) {
                    this.sample.loading = false;
                    this.error = 'العينة تتأخر. جرّب بعد قليل.';
                    return;
                }

                this.sampleAudio = new Audio(result.data.url);
                this.sampleAudio.addEventListener('ended', () => this.stopSample());
                await this.sampleAudio.play();
                this.sample = { voice: voice.key, loading: false, playing: true };
            } catch (e) {
                this.sample = { voice: null, loading: false, playing: false };
                this.error = e.message;
            }
        },

        stopSample() {
            this.sampleAudio?.pause();
            this.sampleAudio = null;
            this.sample = { voice: null, loading: false, playing: false };
        },

        // =====================================================================
        // التوليد والدفعة
        // =====================================================================
        get missing() {
            const missing = [];
            if (!spoken(this.activeText)) missing.push(this.source === 'plan' ? 'اختر محتوى من خطتك' : 'اكتب النص');
            if (this.overLimit) missing.push(`اختصر النص إلى ${this.maxChars} حرف`);
            if (this.style === 'custom' && !this.customStyle.trim()) missing.push('اكتب توجيهات الأسلوب المخصص');
            if (!this.voice) missing.push('اختر المذيع');
            return missing;
        },

        get complete() {
            return this.missing.length === 0;
        },

        // ---------- مسار الخطوات ----------
        /** اكتمال كل قسم بترتيب الصفحة؛ الترتيب نفسه يحدد «الخطوة الحالية». */
        get steps() {
            return {
                style: this.style !== 'custom' || this.customStyle.trim() !== '',
                content: this.hasText && !this.overLimit,
                language: this.language === 'en' ? !!this.accent : !!this.dialect,
                voice: !!this.voice,
            };
        },

        /** done: اكتملت · current: أول ما ينقص (يُبرز) · todo: ناقصة بعدها */
        stepState(step) {
            const steps = this.steps;

            if (steps[step]) return 'done';

            return Object.keys(steps).find((key) => !steps[key]) === step ? 'current' : 'todo';
        },

        minutesFor(text) {
            return Math.max(1, Math.ceil(countWords(text) / this.wordsPerMinute));
        },

        costFor(text, tier) {
            return Math.round((this.minuteCosts[tier] ?? 0) * this.minutesFor(text) * 10) / 10;
        },

        get cost() {
            return spoken(this.activeText) ? this.costFor(this.activeText, this.tier) : 0;
        },

        get batchCost() {
            return Math.round(this.batch.reduce((sum, item) => sum + this.costFor(item.text, item.tier), 0) * 10) / 10;
        },

        currentItem() {
            return {
                text: this.activeText.trim(),
                voice: this.voice,
                style: this.style,
                custom_style: this.style === 'custom' ? this.customStyle.trim() : null,
                language: this.language,
                dialect: this.language === 'ar' ? this.dialect : null,
                variant: this.language === 'ar' && this.dialect === 'saudi' ? this.variant : null,
                accent: this.language === 'en' ? this.accent : null,
                tier: this.tier,
                content_item_id: this.source === 'plan' ? this.planId : null,
            };
        },

        validate() {
            this.showErrors = true;
            this.error = '';

            if (!this.complete) {
                this.announce(`ينقص: ${this.missing.join('، ')}`);
                return false;
            }

            return true;
        },

        async generate() {
            if (!this.validate()) return;
            await this.send([this.currentItem()]);
        },

        addToBatch() {
            if (!this.validate()) return;

            if (this.batch.length >= this.maxBatch) {
                this.error = `الدفعة تتسع لـ${this.maxBatch} تعليقات. ولّدها أو احذف بعضها.`;
                return;
            }

            const voice = this.selectedVoice;
            this.batch.push({
                ...this.currentItem(),
                key: `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
                labels: {
                    voice: voice?.name,
                    initial: voice?.initial,
                    style: this.styleLabel,
                    language: this.languageSummary,
                    tier: this.tiers[this.tier]?.short,
                },
            });
            writeStore(config.storageKey, this.batch);

            this.showErrors = false;
            this.notice = `أُضيف للدفعة (${this.batch.length}). غيّر الإعدادات وأضف غيره، أو ولّدها كلها.`;
            this.announce(this.notice);
        },

        removeFromBatch(index) {
            this.batch.splice(index, 1);
            writeStore(config.storageKey, this.batch);
        },

        clearBatch() {
            this.batch = [];
            writeStore(config.storageKey, []);
        },

        async generateBatch() {
            if (!this.batch.length) return;

            // الحقول الداخلية للعرض فقط لا تُرسل
            const items = this.batch.map(({ key, labels, ...item }) => item);
            const sent = await this.send(items);

            if (sent) this.clearBatch();
        },

        async send(items) {
            if (this.generating) return false;

            this.generating = true;
            this.error = '';
            this.notice = '';

            try {
                const { status, data } = await request(this.urls.store, { method: 'POST', body: { items } });

                (data.jobs || []).forEach((job) => {
                    this.running.unshift({ ...job, progress: 8, stage: 'في الانتظار' });
                    this.track(job.uuid);
                });

                this.historyOpen = true;
                this.showErrors = false;

                if (status === 207) {
                    this.error = data.message;
                } else {
                    this.notice = items.length > 1
                        ? `بدأ تسجيل ${items.length} تعليقات. تظهر في السجل حين تجهز.`
                        : 'بدأ التسجيل. يظهر التعليق في السجل حين يجهز.';
                }

                this.announce(this.notice || this.error);

                return status !== 207;
            } catch (e) {
                this.error = e.message;
                this.announce(e.message);
                return false;
            } finally {
                this.generating = false;
            }
        },

        async track(uuid) {
            const done = await waitForJob(uuid, (update) => {
                const job = this.running.find((j) => j.uuid === uuid);
                if (!job) return;
                job.progress = Math.max(job.progress, update.progress ?? 0);
                job.stage = update.stage || update.label || job.stage;
            });

            const job = this.running.find((j) => j.uuid === uuid);

            if (done.status === 'timeout') {
                if (job) job.stage = 'يستغرق أطول من المعتاد — يكتمل في الخلفية';
                return;
            }

            this.running = this.running.filter((j) => j.uuid !== uuid);

            if (done.status === 'completed') {
                await this.refreshHistory();
                const id = done.result?.media_ids?.[0];
                const item = this.history.find((h) => h.id === id);
                if (item) this.select(item);
                this.announce('جهز التعليق الصوتي.');
            } else {
                this.error = `${done.error || 'تعذّر تسجيل التعليق.'} أُرجعت نقاطه.`;
                this.refreshHistory();
            }
        },

        async refreshHistory() {
            this.refreshing = true;

            try {
                const { data } = await request(this.urls.history);
                this.history = data.items || [];
                this.balance = data.balance ?? this.balance;

                // مهام جارية بدأت من تبويب آخر
                (data.running || []).forEach((job) => {
                    if (!this.running.some((j) => j.uuid === job.uuid)) {
                        this.running.push({ ...job, progress: 8, stage: 'في الانتظار' });
                        this.track(job.uuid);
                    }
                });

                if (this.player.id && !this.history.some((h) => h.id === this.player.id)) this.closePlayer();
            } catch (e) {
                this.error = e.message;
            } finally {
                this.refreshing = false;
            }
        },

        // =====================================================================
        // المشغّل
        // =====================================================================
        get audio() {
            return this.$refs.audio;
        },

        get activeItem() {
            return this.history.find((h) => h.id === this.player.id) || null;
        },

        select(item) {
            if (this.player.id === item.id) return;

            this.stopSample();
            this.audio.pause();
            this.player = { id: item.id, playing: false, current: 0, duration: item.duration || 0, rate: this.player.rate, peaks: this.peaksCache[item.id] || [] };
            this.audio.src = item.url;
            this.audio.playbackRate = this.player.rate;
            this.loadPeaks(item);
        },

        async toggle(item) {
            if (this.player.id !== item.id) this.select(item);

            if (this.audio.paused) {
                this.stopSample();
                try {
                    await this.audio.play();
                } catch {
                    this.error = 'تعذّر تشغيل الملف في هذا المتصفح. نزّله وشغّله من جهازك.';
                }
            } else {
                this.audio.pause();
            }
        },

        pause() {
            this.audio?.pause();
        },

        closePlayer() {
            this.audio.pause();
            this.audio.removeAttribute('src');
            this.player = { ...this.player, id: null, playing: false, current: 0, peaks: [] };
        },

        onTime() {
            this.player.current = this.audio.currentTime || 0;
            if (Number.isFinite(this.audio.duration)) this.player.duration = this.audio.duration;
        },

        seek(event) {
            const rect = event.currentTarget.getBoundingClientRect();
            const ratio = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
            if (this.player.duration) this.audio.currentTime = ratio * this.player.duration;
        },

        seekBy(seconds) {
            if (!this.player.duration) return;
            this.audio.currentTime = Math.min(this.player.duration, Math.max(0, this.audio.currentTime + seconds));
        },

        setRate(delta) {
            const rate = Math.round(Math.min(2, Math.max(0.5, this.player.rate + delta)) * 100) / 100;
            this.player.rate = rate;
            this.audio.playbackRate = rate;
        },

        get progressRatio() {
            return this.player.duration ? this.player.current / this.player.duration : 0;
        },

        /** موجة حقيقية من الملف نفسه (Web Audio)؛ إن تعذّرت تبقى أعمدة ثابتة. */
        async loadPeaks(item) {
            if (this.peaksCache[item.id]) return;

            const bars = 56;

            try {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                const buffer = await (await fetch(item.url)).arrayBuffer();
                const decoded = await new Ctx().decodeAudioData(buffer);
                const data = decoded.getChannelData(0);
                const step = Math.floor(data.length / bars) || 1;
                const peaks = [];

                for (let i = 0; i < bars; i++) {
                    let max = 0;
                    for (let j = i * step; j < Math.min(data.length, (i + 1) * step); j += 16) {
                        max = Math.max(max, Math.abs(data[j]));
                    }
                    peaks.push(max);
                }

                const top = Math.max(...peaks) || 1;
                this.peaksCache[item.id] = peaks.map((p) => Math.max(0.08, p / top));
            } catch {
                this.peaksCache[item.id] = Array.from({ length: bars }, (_, i) => 0.25 + 0.2 * Math.abs(Math.sin(i * 1.7)));
            }

            if (this.player.id === item.id) this.player.peaks = this.peaksCache[item.id];
        },

        time(seconds) {
            const s = Math.max(0, Math.round(seconds || 0));
            return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
        },

        async pin(item) {
            try {
                const { data } = await request(this.urls.pin.replace('__id__', item.id), { method: 'POST' });
                Object.assign(item, data);
                this.announce(data.pinned ? 'حُفظ التعليق: لن يُحذف تلقائياً.' : `أُلغي الحفظ: يُحذف بعد ${this.retentionDays} يوماً من إنشائه.`);
            } catch (e) {
                this.error = e.message;
            }
        },

        async remove(item) {
            if (!window.confirm('حذف هذا التعليق الصوتي نهائياً؟')) return;

            try {
                await request(this.urls.destroy.replace('__id__', item.id), { method: 'DELETE' });
                if (this.player.id === item.id) this.closePlayer();
                this.history = this.history.filter((h) => h.id !== item.id);
                this.announce('حُذف التعليق.');
            } catch (e) {
                this.error = e.message;
            }
        },

        announce(message) {
            this.announcement = '';
            this.$nextTick(() => { this.announcement = message; });
        },
    }));
}
