/*
   صفحة «الخطة الشهرية»: التقويم نفسه، البحث، التنقل بين الأشهر والأيام كلها
   GET/POST عادية (لا AJAX) — الحالة هنا تخص فقط مودال الجدولة، مودال الاستوديو،
   والتحديد الجماعي، على نمط productsIndex في app.js.
*/
export default function registerContentCalendar(Alpine) {
    Alpine.data('contentCalendar', (config = {}) => ({
        platforms: config.platforms || {},
        drafts: config.drafts || [],
        pageIds: config.pageIds || [],

        // ---------- مودال الجدولة ----------
        scheduleOpen: false,
        source: 'new',
        contentItemId: '',
        caption: '',
        selectedPlatforms: [],
        activeTab: null,
        overrides: {},
        mode: 'schedule',
        scheduledDate: config.defaultDate || '',
        scheduledTime: '09:00',
        privacy: 'public',

        openSchedule() {
            this.scheduleOpen = true;
        },

        closeSchedule() {
            this.scheduleOpen = false;
        },

        // التبويب النشط يتبع أول منصّة مختارة، ويُفرَّغ حين لا تبقى منصّة
        togglePlatform() {
            this.$nextTick(() => {
                if (this.selectedPlatforms.length && ! this.selectedPlatforms.includes(this.activeTab)) {
                    this.activeTab = this.selectedPlatforms[0];
                }
                if (! this.selectedPlatforms.length) this.activeTab = null;
            });
        },

        chooseExisting(id) {
            const item = this.drafts.find((d) => String(d.id) === String(id));
            if (! item) return;

            this.caption = item.caption || '';

            if (item.platform && ! this.selectedPlatforms.includes(item.platform)) {
                this.selectedPlatforms = [...this.selectedPlatforms, item.platform];
                this.togglePlatform();
            }
        },

        charCount(key) {
            return (this.overrides[key] ?? this.caption ?? '').length;
        },

        // ---------- مودال الاستوديو ----------
        mediaOpen: false,

        openMedia() {
            this.mediaOpen = true;
        },

        closeMedia() {
            this.mediaOpen = false;
        },

        // ---------- التحديد الجماعي في قائمة اليوم ----------
        selected: [],
        confirmingDelete: false,

        get allSelected() {
            return this.pageIds.length > 0 && this.selected.length === this.pageIds.length;
        },

        toggleAll() {
            this.selected = this.allSelected ? [] : [...this.pageIds];
        },

        clearSelection() {
            this.selected = [];
            this.confirmingDelete = false;
        },
    }));
}
