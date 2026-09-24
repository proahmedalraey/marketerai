# خطة: استوديو الصور — ما بُني الآن وما يُبنى لاحقاً

> السياق: إعادة تصميم `/studio` لمطابقة 12 لقطة شاشة مرجعية (نمط Freepik/Ideogram) · الحالة: **معتمدة بقرارات جلسة 2026-09-22، والمرحلة الثانية جارية بنداً بنداً منذ 2026-09-23**

---

## 0. القرار المعتمد

المستخدم أجاب على 4 أسئلة توضيحية قبل التنفيذ (راجع سجل المحادثة). الخلاصة:

1. **الشكل/التخطيط يُبنى الآن بالكامل.** كل عنصر في الصور المرجعية موجود بصرياً في الواجهة الجديدة.
2. **قائمة اختيار النموذج شكلية.** لا توجيه فعلي لمزوّد مختلف.
3. **مصفوفة النقاط الكسرية (دقة × جودة) فعلية بالكامل.** تغيير حقيقي في نظام النقاط، منفَّذ في هذه الجلسة (انظر §1 أدناه — منفَّذ لا مؤجَّل).
4. **المعرض (مجلدات/تثبيت/حذف جماعي) وإجراءات كل صورة شكلية.** كل عنصر معطّل بوسم "قريباً" في الواجهة الحالية.

هذا المستند يوثّق **فقط** العناصر المؤجَّلة (§2) — القسم §1 توثيق مرجعي لما نُفِّذ فعلياً في هذه الجلسة، حتى تُفهم نقطة الانطلاق عند بناء أي بند من §2.

---

## 1. ما نُفِّذ فعلياً في هذه الجلسة (مرجع سريع)

| البند | الحالة | الملفات الرئيسية |
|---|---|---|
| مصفوفة نقاط دقة×جودة (15 تركيبة، كسرية) | **فعلي** | `config/ai.php` (`image_quality_matrix`)، `config/credits.php` |
| أعمدة النقاط `decimal(10,1)` بدل صحيحة | **فعلي** | هجرة `2026_01_10_000100_convert_credit_columns_to_decimal.php` |
| `CreditService` بحساب كسري (float) | **فعلي** | `app/Services/Credits/CreditService.php` |
| 13 نسبة أبعاد (بدل 5) | **فعلي** | `config/ai.php` (`aspect_ratios`) |
| اختيار صورة سابقة من المعرض كمرجع (`reference_asset_id`) | **فعلي** — كشف آلية كانت داخلية فقط للكاروسيل | `ImageStudioController::store()`, `ImageGenerationService::dispatch()` |
| بحث/فرز حقيقيان في المعرض | **فعلي** (استعلام GET بسيط) | `ImageStudioController::index()` |
| نموذج ذكاء واحد نشط عالمياً (كما كان) | **بلا تغيير** | `config('ai.image_provider')`, `AiManager` |
| إعادة التوليد (§2.4 سابقاً) | **فعلي** — 2026-09-23 | `ImageStudioController::regenerate()`, route `studio.regenerate`, `tests/Feature/ImageStudioRegenerateTest.php` |
| رفع صورة مرجعية من الجهاز (§2.8 سابقاً) | **فعلي** — 2026-09-23، JSON فوري بلا نقاط ولا توليد، منفصل عن معرض "توليد" (`generation_job_id IS NULL`) | `ImageStudioController::upload()`, route `studio.uploads`, `resources/js/image-studio.js` (`uploadReference`), `tests/Feature/ImageStudioUploadTest.php` |
| حذف صورة — **حذف فوري نهائي** (قرار المستخدم 2026-09-23، لا حذف ناعم) | **فعلي** — 2026-09-23 | `ImageStudioController::destroy()`, route `studio.media.destroy`, `<x-confirm>` في `_context-menu.blade.php`, `tests/Feature/ImageStudioDeleteTest.php` |
| "المعرض" / "من خطة المحتوى" كتبويبين بدل قسمين متتاليين | **فعلي** — 2026-09-23، تعديل واجهة بطلب المستخدم | `resources/views/content/studio.blade.php` |
| تصحيح: لوحة "الكل" كانت تفتح أسفل الصفحة (bug) | **مُصلَح** — 2026-09-23، `top-full` كان نسبياً لـ`<section>` الكامل لا لزر الفتح | `_gallery.blade.php`, `_folders-panel.blade.php` |
| بحث داخل قائمة "من المنتجات" في لوحة الرفع | **فعلي** — 2026-09-23، فلترة Alpine محلية | `_upload-popover.blade.php` |
| منزلق حجم شبكة المعرض | **فعلي** — 2026-09-23، تفضيل عرض محلي بحت (`gridCols`) | `_gallery.blade.php` |
| مجلدات المعرض + تثبيت (§2.2 سابقاً — جزء منه) | **فعلي** — 2026-09-23: إنشاء/حذف مجلد، نقل صورة إليه، فلترة المعرض بمجلد أو بالمثبتة، تثبيت/إلغاء عبر العارض الكامل (JSON فوري بلا إعادة تحميل). **الحذف الجماعي لا يزال مؤجَّلاً** (يحتاج وضع تحديد متعدد منفصلاً) | هجرتا `2026_01_11_*`, `MediaFolder`, `MediaFolderController`, `ImageStudioController::move()/pin()`, `_folders-panel.blade.php`, `tests/Feature/ImageStudioFoldersTest.php` |

صفحة الكاروسيل (`content/show.blade.php`, `ImageStudioController::carousel()`) تبقى على `config('ai.quality_tiers')` القديمة (4 مستويات صحيحة) — لم تُمس عمداً.

---

## 2. المؤجَّل — تفصيل تقني لكل بند

### 2.1 توجيه فعلي بين النماذج (Nano Banana 2 / GPT Image Sunburst / Flare / Grok Imagine / Grok Imagine 2.0)

- **الفجوة**: `AiManager::image(?string $provider)` يقبل بالفعل اسم مزوّد فردي، لكن `ImageGenerationService::run()` لا يمرّره (`app/Services/Media/ImageGenerationService.php:73`، الاستدعاء بلا وسيط `$provider`). `ImageRequest` DTO لا يحمل حقل نموذج/مزوّد إطلاقاً.
- **Grok/xAI**: لا يوجد درايفر إطلاقاً. يلزم `app/Services/AI/Drivers/GrokImageProvider.php` يطبّق `App\Services\AI\Contracts\ImageProvider`، وإضافة `'grok' => ['driver' => 'grok', ...]` في `config/ai.php['providers']`.
- **GPT Image Sunburst مقابل Flare**: OpenAI لا يميّز رسمياً بين نسختين بهذين الاسمين اليوم — يحتاج قرار منتج: هل هما إعداد باراميترات مختلف لنفس `gpt-image-1` (مثال: `quality=hd` مقابل `standard` في OpenAI's API)، أم اسمان تسويقيان فقط لنفس السلوك؟
- **الخطوات**: (1) حقل `provider`/`model` في `ImageRequest` و`config('ai.studio_models')` (موجود فعلاً كعرض فقط) يُربط بمفتاح مزوّد حقيقي، (2) تمرير الاختيار من `ImageStudioController::store()` عبر `ImageGenerationService::dispatch()` إلى `AiManager::generateImage($request, $job, $provider)`، (3) بناء درايفر Grok، (4) تحديث `ModelCatalog.php` إن لزم عرض نماذج Grok الحية في إعدادات المنصة.

### 2.2 حذف جماعي (الجزء المتبقي من "مجلدات المعرض")

المجلدات والتثبيت صارا فعليين (§1). المتبقي فقط:

- **الفجوة**: يحتاج وضع "تحديد متعدد" في الواجهة — checkbox على كل بطاقة عند تفعيل "تحديد للحذف الجماعي"، حالة `selectedIds` مشتركة على مستوى قسم المعرض، وشريط إجراء عائم يظهر عدد المحدد وزر "حذف المحدد".
- **الخطوات**: endpoint `POST /studio/media/bulk-destroy` يقبل `ids[]` (كل عنصر يُتحقق أنه ضمن `media_assets` الخاصة بالبراند — لا يكفي `whereIn('id', $ids)` بلا فلتر brand)، يمسح الملفات من التخزين ثم السجلات، على نمط `ImageStudioController::destroy()` الحالي (حذف فردي فعلي) لكن بحلقة/استعلام دفعة واحدة.

### 2.5 إزالة الخلفية

- **الفجوة**: ميزة جديدة كلياً. يحتاج إما مزوّد ذكاء صور بقناع (remove.bg API، أو نموذج segmentation عبر Gemini/OpenAI)، أو مكتبة معالجة صور محلية. خارج نطاق `AiManager` الحالي (لا `Contracts\ImageProvider` يدعم إزالة خلفية).

### 2.6 حفظ الصورة كمرجع أساسي للمنتج

- **الفجوة الجزئية**: `ProductImage.is_reference` موجود فعلاً (`app/Models/ProductImage.php`)، لكن `MediaAsset` ليس `ProductImage`. الخطوة: controller action يأخذ `media_asset_id` + `product_id`، ينسخ سجل `ProductImage` جديد يشير لنفس disk/path، يضبط `is_reference=true` ويُلغيها عن البقية.

### 2.7 نشر إلى السوشيال ميديا من المعرض

- **الفجوة**: لا تكامل نشر سوشيال ميديا في المشروع إطلاقاً حالياً (`social_accounts`/`scheduled_posts` موجودة كجداول لكن آلية النشر الفعلي غير موجودة في نطاق هذا البحث). ميزة كبيرة مستقلة، تحتاج تخطيطاً منفصلاً بالكامل.

### 2.9 صوت→نص وتحسين البرومبت بالذكاء

- **صوت→نص**: يمكن تنفيذه بالكامل من طرف المتصفح (Web Speech API) بلا تغيير خادم — مناسب كخطوة أولى منخفضة الكلفة.
- **تحسين البرومبت**: يتطلب استدعاء نموذج نص، وقاعدة المشروع الملزمة (`CLAUDE.md` §1) تمنع استدعاء نموذج داخل طلب HTTP — يحتاج تدفق Job+Queue مصغّر (حجز نقطة رمزية أو بلا نقاط، استطلاع سريع) بدل الشكل الفوري في الصور المرجعية.
