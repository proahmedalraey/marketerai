# خطة: استوديو الصور — ما بُني الآن وما يُبنى لاحقاً

> السياق: إعادة تصميم `/studio` لمطابقة 12 لقطة شاشة مرجعية (نمط Freepik/Ideogram) · الحالة: **معتمدة بقرارات جلسة 2026-09-22، والمرحلة الثانية جارية بنداً بنداً منذ 2026-09-23**

---

## 0. القرار المعتمد

المستخدم أجاب على 4 أسئلة توضيحية قبل التنفيذ (راجع سجل المحادثة). الخلاصة:

1. **الشكل/التخطيط يُبنى الآن بالكامل.** كل عنصر في الصور المرجعية موجود بصرياً في الواجهة الجديدة.
2. **قائمة اختيار النموذج كانت شكلية عند القرار الأول، وأصبحت فعلية بتاريخ 2026-09-25** (توجيه عبر OpenRouter — §2.1).
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
| توجيه فعلي بين النماذج الخمسة عبر OpenRouter (§2.1) | **فعلي** — 2026-09-25 (اختُبر حياً بخمسة نماذج). الاختيار يُرسَل مع الطلب ويُسجَّل في `meta.model`؛ حين مزود الصور غير OpenRouter تُعرض «شريحة قراءة فقط» بالنموذج المُعدّ بدل قائمة مضلِّلة | `StudioModels`, `config/ai.php` (`studio_models`), `OpenRouterImageProvider`, `_toolbar/_model-popover.blade.php`, `tests/Feature/ImageStudioModelsTest.php` |
| قدرات كل نموذج حيّة من OpenRouter (دقات/جودات/نسب) | **فعلي** — الدقة أو الجودة غير المدعومة تُعطَّل في الواجهة وتُرفض في الخادم **قبل حجز النقاط**؛ تبديل النموذج يضبط الاختيار تلقائياً مع تنبيه | `StudioModels::capabilities()/unsupported()`, `image-studio.js` (`selectModel`, `normalizeForModel`), `_quality-popover.blade.php` |
| نسبة غير مدعومة: تُطلب أقرب نسبة ثم تُقصّ من المنتصف | **فعلي** — `meta.cropped_from`؛ الأبعاد المسجّلة = أبعاد الملف الفعلية (كانت تُسجَّل 3840 لصورة 1024) | `ImageRatio::crop()`, `ImageGenerationService::run()` |
| حفظ آخر اختيارات المستخدم (نموذج/دقة/جودة/نسبة/عدد/برومبت) | **فعلي** — localStorage؛ يعلوه `old()` عند رجوع الخادم بخطأ | `image-studio.js` (`persist`, `restore`) |
| النقاط الكسرية: إصلاح فقدانها في التسوية والاسترجاع | **مُصلَح** — 2026-09-25: `(int)` على `credits_held` كانت تُسجّل 0 مخصوم وتُرجع 0 عند الفشل؛ اختبارات دفتر النقاط | 9 ملفات (Jobs/Services)، `JobSummary`, `tests/Feature/ImageStudioCreditsTest.php` |
| إعدادات `/settings/ai` لم تكن تُطبَّق في طلب الويب (الواجهة ترى `.env` والعامل يرى المنصة) | **مُصلَح** — كان اختيار النموذج يختفي رغم ضبط OpenRouter | `StudioModels::provider()` (يستدعي `AiSettings::apply`)، اختبار `platform_settings_override_env_for_the_web_request` |
| توليد الصور بالتوازي (`Http::pool`) مع تحمّل الفشل الجزئي | **فعلي** — 4 صور في ~14ث بدل ~50ث؛ فشل صورة لا يُسقط ما نجح (تُرجع الخدمة نقاط الناقص فقط) | `OpenRouterImageProvider::generateMany()`, `OpenRouterProviderTest` |
| مصغّرات المعرض (480px JPEG ≈ 20–50KB بدل 1–2MB) | **فعلي** — تُنشأ عند التوليد والرفع؛ `php artisan media:thumbnails` للقديمة؛ تُمسح مع الحذف | `ImageThumbnail`, `MediaAsset::thumbUrl()/putThumbnail()/deleteFiles()`, `MediaThumbnailsCommand`, `tests/Feature/ImageStudioThumbnailTest.php` |
| «عرض المزيد» في المعرض بدل سقف صامت عند 60 | **فعلي** — `?limit=` يرتفع 60 كل مرة ويحفظ البحث/الفلتر | `ImageStudioController::index()`, `_gallery.blade.php`, `tests/Feature/ImageStudioGalleryTest.php` |
| اسم النموذج والأبعاد الفعلية على بطاقة المعرض والعارض | **فعلي** | `_gallery.blade.php`, `_lightbox.blade.php` |
| هياكل انتظار التوليد بعدد الصور ونسبتها الفعلية | **فعلي** | `studio.blade.php` (`trackedCount/trackedRatio`) |
| القوائم المنبثقة لا تخرج من إطار الشاشة (RTL / الجوال) | **فعلي** | `image-studio.js` (`keepPopoverInView`, `data-popover`) |
| الحفاظ على المنتج عند وجود صورة مرجعية | **مُصلَح** — 2026-09-25: قيد «لا نص ولا شعارات» كان يمحو اسم المنتج وشعاره من العبوة نفسها (essenza / MASTIC). مع مرجع يُرسَل بدله `KEEP_PRODUCT_RULE` (يحفظ الشكل والألوان والنصوص ويغيّر المحيط فقط) والقيد القديم بلا مرجع. اختُبر حياً على Flare وSunburst وNano Banana 2 | `ImageGenerationService::composePrompt()`, `tests/Feature/ImageStudioModelsTest.php` |
| أخطاء الاتصال (DNS/رفض اتصال) | **فعلي** — تُعاد تلقائياً (لم يصل الطلب للمزود فلا دفع مرتين)، والمهلة لا تُعاد، ورسالة واضحة للتاجر بدل «تعذّر التوليد» العامة | `ProviderException::fromConnection()/messageFor()`, `AiManager::withRetries()`, `OpenRouterProviderTest` |
| تنزيل صورة المنتج المرجعية يفشل (403/404) | **فعلي** — رسالة واضحة قبل الاتصال بالمزود بدل إرسال صفحة الخطأ كأنها صورة | `OpenRouterImageProvider::referencePart()` |
| رفض الخادم يظهر في الصفحة (جودة/نموذج/وصف) | **فعلي** — كان يبدو الضغط بلا أثر | `studio.blade.php` |
| تحسين الوصف بالذكاء (زر العصا) | **فعلي** — 2026-09-26. مهمة طابور (`prompt_enhance`) عبر `AiManager` تُستطلع من `/api/jobs/{uuid}`؛ يحفظ قصد التاجر ويضيف الخلفية والإضاءة والزاوية فقط، ولا يصف عبوة المنتج المرجعي. حارس على النتيجة (رقم/ادعاء بمعجم `claims.php`/طلب نص داخل الصورة) يُعيد للنموذج مرة ثم يفشل ويُرجع النقاط بدل تسليم مختلَق. الأصل يُحفظ للتراجع، ولا يُستبدل ما عدّله التاجر أثناء الانتظار. **مجاني افتراضياً** (`credits.costs['prompt.enhance'] = 0` بلا حجز؛ سعّره برفعه ≥ 0.1). القياس الحي: ~6ث/$0.004 بنموذج النص الرئيسي، ~4ث/$0.001 مع `AI_PROMPT_ENHANCE_MODEL=google/gemini-3.8-flash` | `PromptEnhancer`, `EnhancePromptJob`, `ImageStudioController::enhance()` (route `studio.enhance`, throttle 20/د), `image-studio.js` (`enhance`, `undoEnhance`, `fitPrompt`), `_toolbar.blade.php`, `tests/Feature/PromptEnhanceTest.php` |
| **ملاحظة تشغيل**: الأصول تُخدَّم من `public/build` إن غاب `public/hot` (خادم Vite متوقف) | بناء قديم = واجهة بلا `selectModel` ← تعذّر تبديل النموذج. بعد أي تعديل JS/Blade-classes: `npm run dev` أو `npm run build` | `resources/js/image-studio.js` |

صفحة الكاروسيل (`content/show.blade.php`, `ImageStudioController::carousel()`) تبقى على `config('ai.quality_tiers')` القديمة (4 مستويات صحيحة) — لم تُمس عمداً.

---

## 2. المؤجَّل — تفصيل تقني لكل بند

### 2.1 توجيه فعلي بين النماذج — **مُنفَّذ (2026-09-25)** عبر OpenRouter

لم يلزم درايفر Grok: OpenRouter يوفّر النماذج الخمسة بمفتاح واحد (`config('ai.studio_models')` يربط كل مفتاح واجهة بمعرّف OpenRouter). التوجيه فعّال **فقط حين `ai.image_provider = openrouter`**؛ بغيره يُتجاهل الاختيار.

قياسات حقيقية (1K، جودة منخفضة/الوحيدة، 2026-09-25):

| النموذج | الزمن | كلفة المزوّد/صورة | ملاحظات |
|---|---|---|---|
| GPT Image 2.5 Flare | ~13ث | $0.006 | 16:9 ← 1536×864 فعلياً |
| GPT Image 2.5 Sunburst | ~14ث | $0.004 | مثل Flare |
| Grok Imagine | ~6ث | $0.050 | جودة واحدة؛ 4:5 غير مدعومة ← 3:4 ثم قصّ |
| Grok Imagine 2.0 | ~15ث (low) / ~58ث (medium) | $0.040 | 1K/2K، جودتان |
| Nano Banana 2 | ~13ث/صورة | $0.067 | 1K/2K/4K، جودة واحدة؛ **يحجب بالسلامة** عبارة «No text, no letters, no watermark.» فتُستبدل بصيغة موحّدة (`NO_TEXT_RULE`) |

**قرار منتج معلّق**: مصفوفة النقاط لا تعرف النموذج (1 نقطة = 1K متوسطة لكل النماذج) بينما كلفة المزوّد تتفاوت ×17 ($0.004 ← $0.067). إن كان السعر النهائي يجب أن يعكس الكلفة، يلزم معامل نموذج في `image_quality_matrix` (مثلاً ضرب النقاط بمعامل لكل `studio_models`).

### 2.2 حذف جماعي (الجزء المتبقي من "مجلدات المعرض")

المجلدات والتثبيت صارا فعليين (§1). المتبقي فقط:

- **الفجوة**: يحتاج وضع "تحديد متعدد" في الواجهة — checkbox على كل بطاقة عند تفعيل "تحديد للحذف الجماعي"، حالة `selectedIds` مشتركة على مستوى قسم المعرض، وشريط إجراء عائم يظهر عدد المحدد وزر "حذف المحدد".
- **الخطوات**: endpoint `POST /studio/media/bulk-destroy` يقبل `ids[]` (كل عنصر يُتحقق أنه ضمن `media_assets` الخاصة بالبراند — لا يكفي `whereIn('id', $ids)` بلا فلتر brand)، يمسح الملفات (`MediaAsset::deleteFiles()` — يشمل المصغّرة) ثم السجلات، على نمط `ImageStudioController::destroy()` الحالي (حذف فردي فعلي) لكن بحلقة/استعلام دفعة واحدة.

### 2.5 إزالة الخلفية

- **الفجوة**: ميزة جديدة كلياً. يحتاج إما مزوّد ذكاء صور بقناع (remove.bg API، أو نموذج segmentation عبر Gemini/OpenAI)، أو مكتبة معالجة صور محلية. خارج نطاق `AiManager` الحالي (لا `Contracts\ImageProvider` يدعم إزالة خلفية).

### 2.6 حفظ الصورة كمرجع أساسي للمنتج

- **الفجوة الجزئية**: `ProductImage.is_reference` موجود فعلاً (`app/Models/ProductImage.php`)، لكن `MediaAsset` ليس `ProductImage`. الخطوة: controller action يأخذ `media_asset_id` + `product_id`، ينسخ سجل `ProductImage` جديد يشير لنفس disk/path، يضبط `is_reference=true` ويُلغيها عن البقية.

### 2.7 نشر إلى السوشيال ميديا من المعرض

- **الفجوة**: لا تكامل نشر سوشيال ميديا في المشروع إطلاقاً حالياً (`social_accounts`/`scheduled_posts` موجودة كجداول لكن آلية النشر الفعلي غير موجودة في نطاق هذا البحث). ميزة كبيرة مستقلة، تحتاج تخطيطاً منفصلاً بالكامل.

### 2.9 صوت→نص (المتبقي) — وتحسين البرومبت **مُنفَّذ (2026-09-26)**

- **صوت→نص**: يمكن تنفيذه بالكامل من طرف المتصفح (Web Speech API) بلا تغيير خادم — مناسب كخطوة أولى منخفضة الكلفة. الزر لا يزال معطّلاً بوسم «قريباً».
- **تحسين البرومبت**: منفَّذ عبر مهمة طابور مصغّرة كما اقتُرح هنا (انظر الصف في §1). قرار التسعير مفتوح: مجاني الآن؛ الكلفة الفعلية ~$0.001–0.004 للطلب.
