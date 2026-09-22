/* ==========================================================================
   لوحة «إنتاجاتي»: كل عمليات الذكاء الاصطناعي في مكان واحد.

   العملية تبدأ في صفحة وتنتهي والتاجر في غيرها. لذلك تُستطلع الجارية هنا
   وتتحدث بطاقتها في مكانها، بلا تحديث للصفحة يضيّع ما يكتبه.

   الإخفاء تفضيل عرض لهذا المتصفح: لا يحذف المهمة ولا يخص الخادم.
   ========================================================================== */

const DISMISSED_KEY = 'operations.dismissed';

export default function registerOperations(Alpine) {
    Alpine.data('operationsCenter', (initial = [], config = {}) => ({
        ops: initial,
        icons: config.icons || {},
        isOpen: false,
        tab: 'all',
        expanded: null,
        dismissed: [],
        watched: [],

        init() {
            this.restore();
            this.watchRunning();
        },

        // ---------- التصفية ----------
        get visible() {
            return this.ops.filter((op) => ! this.dismissed.includes(op.uuid));
        },

        get running() {
            return this.visible.filter((op) => op.state === 'running');
        },

        get done() {
            return this.visible.filter((op) => op.state === 'done');
        },

        get failed() {
            return this.visible.filter((op) => op.state === 'failed');
        },

        /** «فشلت» تبويب لا يظهر إلا حين يكون فيه شيء. */
        get tabs() {
            return [
                { key: 'all', label: 'الكل', count: this.visible.length },
                { key: 'running', label: 'جارية', count: this.running.length },
                { key: 'done', label: 'مكتملة', count: this.done.length },
                ...(this.failed.length ? [{ key: 'failed', label: 'فشلت', count: this.failed.length }] : []),
            ];
        },

        get shown() {
            return { running: this.running, done: this.done, failed: this.failed }[this.tab] ?? this.visible;
        },

        get badge() {
            return this.running.length || this.visible.length;
        },

        get emptyMessage() {
            if (this.tab === 'running') return 'لا عملية جارية الآن.';
            if (this.tab === 'failed') return 'لا عملية فاشلة.';

            return this.ops.length ? 'لا عمليات في هذا التبويب.' : 'ما بدأت أي عملية بعد.';
        },

        // ---------- اللوحة ----------
        toggle() {
            this.isOpen ? this.close() : this.open();
        },

        open() {
            this.isOpen = true;
            document.documentElement.classList.add('overflow-hidden');
            this.$nextTick(() => this.$refs.close?.focus());
        },

        close() {
            if (! this.isOpen) return;

            this.isOpen = false;
            document.documentElement.classList.remove('overflow-hidden');
        },

        expand(uuid) {
            this.expanded = this.expanded === uuid ? null : uuid;
        },

        // ---------- الحالة ----------
        stateLabel(op) {
            return { running: 'جارية', done: 'مكتملة', failed: 'فشلت' }[op.state] ?? op.state;
        },

        stateClass(op) {
            return { running: 'chip-info', done: 'chip-success', failed: 'chip-danger' }[op.state] ?? 'chip-neutral';
        },

        /**
         * المهام الجارية تُستطلع حتى تنتهي.
         * انتهاء المهلة ليس فشلاً: المهمة قد تكون في الطابور ونقاطها محجوزة.
         */
        watchRunning() {
            this.ops.filter((op) => op.state === 'running' && ! this.watched.includes(op.uuid)).forEach((op) => {
                this.watched.push(op.uuid);

                window.pollJob(op.uuid, {
                    onUpdate: (data) => {
                        op.progress = Math.max(op.progress, data.progress ?? 0);
                    },
                    onDone: (data) => {
                        if (data.status === 'timeout') return;

                        op.progress = 100;
                        op.state = ['failed', 'cancelled'].includes(data.status) ? 'failed' : 'done';
                        op.error = data.error ?? op.error;
                        op.credits = data.credits_charged ?? op.credits;

                        const ids = data.result?.content_item_ids ?? [];

                        if (ids.length) {
                            op.succeeded = ids.length;
                            op.resultUrl ??= `${config.writeUrl}?focus=${ids[0]}`;
                        }
                    },
                });
            });
        },

        // ---------- الإخفاء ----------
        dismiss(uuid) {
            this.dismissed = [...this.dismissed, uuid];
            this.persist();
        },

        restoreAll() {
            this.dismissed = [];
            this.persist();
        },

        persist() {
            try {
                // ما عاد في القائمة لا يُحفظ: وإلا نما المخزون بلا سقف
                const live = this.ops.map((op) => op.uuid);

                localStorage.setItem(DISMISSED_KEY, JSON.stringify(this.dismissed.filter((uuid) => live.includes(uuid))));
            } catch {
                // تصفح خاص أو تخزين محجوب: الإخفاء يبقى ما بقيت الصفحة
            }
        },

        restore() {
            try {
                const saved = JSON.parse(localStorage.getItem(DISMISSED_KEY) || '[]');

                this.dismissed = Array.isArray(saved) ? saved : [];
            } catch {
                this.dismissed = [];
            }
        },
    }));
}
