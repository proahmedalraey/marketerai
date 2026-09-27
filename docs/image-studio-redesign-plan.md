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
| إصلاح وميض/اهتزاز قوائم الشريط (النموذج، الدقة والجودة، النسبة، الرفع) | **مُصلَح** — 2026-09-26. سببان: (1) لكل قائمة `@click.outside` خاص بها، فعند فتح قائمة والأخرى ما زالت في حركة إخفائها كانت القديمة تُغلق الجديدة فوراً (تظهر وتختفي)؛ (2) تصحيح موضعها كان يُقاس بعد ظهورها ويعيد تشغيل حركة الظهور مرتين (يهتز). الآن `click.outside` واحد على الشريط كله، والموضع يُحسب مرة واحدة قبل الظهور بأبعاد التخطيط (`placePopover`) | `_toolbar.blade.php`, `_*-popover.blade.php` (`data-popover="…"`), `image-studio.js` |
| شريط إعدادات التوليد زجاجي شفاف | **فعلي** — `.studio-dock`: تدرّج شبه شفاف + `backdrop-filter: blur(22px) saturate(170%)` + حافة علوية مضيئة، وحقوله شبه شفافة؛ نسخة داكنة، ورجوع للون صلب بلا دعم الضبابية أو مع `prefers-reduced-transparency`. القوائم فوقه صلبة عمداً (ضبابية الابن داخل أب زجاجي لا ترى ما خارجه) | `resources/css/app.css` |
| الإدخال الصوتي (كان «قريباً») | **فعلي** — Web Speech API داخل المتصفح بالعربية (`ar-SA`)، يكتب في حقل الوصف أثناء الكلام ويضيف لما كُتب قبله؛ رسائل واضحة لرفض الميكروفون/عدم الدعم. ملاحظة: Chrome يرسل الصوت لخدمة Google للتعرّف | `image-studio.js` (`toggleVoice`), `_toolbar.blade.php` |
| إزالة الخلفية (كانت «قريباً») | **فعلي** — مهمة طابور (`mode: remove_background`، `image.remove_bg` = نقطة واحدة) بنموذج `AI_REMOVE_BG_MODEL` (افتراضي GPT Image 2.5 Flare، `background=transparent`)؛ صورة شفافة جديدة بجانب الأصل وفي مجلده، بنسبة الأصل (قصّ يحفظ الشفافية)، ومصغّرة PNG شفافة، وتُعرض فوق نقش شطرنج مع وسم «بلا خلفية». اختُبر حياً من الواجهة: قصّ نظيف والملصق ونصوصه كما هي، ~19ث، ~$0.022 | `ImageGenerationService::dispatchRemoveBackground()`, `ImageStudioController::removeBackground()`, `ImageRatio`, `ImageThumbnail`, `tests/Feature/ImageStudioToolsTest.php` |
| حفظ الصورة كصورة مرجعية أساسية في المنتج (كان «قريباً») | **فعلي** — نافذة منتجات واحدة للصفحة؛ الصورة تُنسخ لصور المنتج (نسخة مستقلة) وتصير `is_reference`، مع احترام حد الصور (`Product::MAX_IMAGES`) | `ImageStudioController::toProduct()`, `_product-picker-modal.blade.php` |
| الحذف والنقل الجماعي (كان «قريباً») | **فعلي** — زر «تحديد» في المعرض (ومن لوحة المجلدات): مربعات تحديد، «تحديد الكل»، نقل لمجلد، وحذف نهائي بتأكيد؛ الخادم يقيّد المعرّفات بصور العلامة فقط | `bulkDestroy()/bulkMove()`, `_gallery.blade.php`, `<x-confirm>` (صار يقبل حقولاً إضافية عبر slot) |
| نشر إلى السوشيال ميديا (كان «قريباً») | **فعلي بحدود صريحة** — قائمة المشاركة في الجهاز (Web Share: إنستغرام، واتساب، سناب… على الجوال)، وعلى الحاسوب نسخ الصورة للحافظة للصقها. لا نشر آلي على حسابات المنصات: لا تكامل مع واجهاتها بعد (§2.7) | `image-studio.js` (`shareAsset`, `prepareShare`) |
| إزالة تكرار رسائل الخطأ | **مُصلَح** — كتلة أخطاء أضيفت في 2026-09-25 كرّرت ما يعرضه `partials.flash` أصلاً | `studio.blade.php` |
| تبويب «من خطة المحتوى» بتصميم المرجع، و«المعرض» صار «الاستوديو» | **فعلي** — 2026-09-26. شهر واحد من الخطة (`in_plan` + `planned_for`) بأسهم تنقّل (`?tab=plan&plan_month=`)؛ بطاقة لكل محتوى: وسم النوع («محتوى قيمي/تسويقي» من `templates.*.short`) والشكل والمنصة والتاريخ، وكل الشرائح «الشريحة N: …» مع صورتها إن وُلّدت، واسم المنتج. **إصلاح**: زر «توليد الصور» القديم كان يُرسل بلا `stage` فيرفضه الخادم ولا يولّد شيئاً. الآن: «توليد صور الكاروسيل» (الغلاف أولاً) ← «أكمل N شرائح» (+ «غلاف آخر») ← «الصور جاهزة»، والتوليد يعيد إلى «الاستوديو» (`return=studio`) حيث يظهر التقدم والصور، وصور الشرائح تُعرض بنص شريحتها. شريط التوليد يختفي في تبويب الخطة. اختُبر حياً: غلاف 4:5 بملصق المنتج سليماً | `_plan-tab.blade.php`, `ImageStudioController::index()/carousel()`, `ContentItem::kindLabel()`, `image-studio.js` (`switchTab`), `tests/Feature/ImageStudioPlanTabTest.php` |
| نافذة «نظام الكاروسيل» (تعديل الكاروسيل) | **فعلي** — 2026-09-27. من قائمة الثلاث نقاط لصور الشرائح في «الاستوديو» ومن بطاقات «من خطة المحتوى». شريط الشرائح (إضافة/حذف، حد 3–10)، معاينة يُحرَّر النص فيها مباشرة (B/I، ألوان، سحب للنقل، مقبضا العرض، دائرة حجم الخط، حذف/إظهار النص)، صورة كل شريحة (تشغيل/إطفاء + وصفها قابل للتعديل + «أعد كتابة الوصف» بالذكاء عبر مُحسِّن الوصف + «أعد إنشاء الصورة» مهمة طابور تُستطلع داخل النافذة)، جودة الصور، التاريخ على الشرائح، ستة أشكال (كوفي عصري، تحريري، جريء داكن، هادئ، تراثي، حيوي)، تراجع/إعادة، «ابدأ من جديد»، «حفظ» و«حفظ كنسخة جديدة» (صور منسوخة الملفات). التعديل مجاني ويعيد فحص الصدق. الرسم مشترك مع صفحة المحتوى (`carousel-render.js`) فتنزيلاتها بنفس التصميم. التصميم يُحفظ في `body.design` و`slides[].layout/image` (قيم محصورة في `ContentSchema::layout`) | `CarouselEditing`, `ImageStudioController::carouselData/saveCarousel/copyCarousel`, `carousel-editor.js`, `carousel-render.js`, `_carousel-editor-modal.blade.php`, `tests/Feature/CarouselEditorTest.php` |
| **ثغرة عزل العلامات (موجودة قبل هذا العمل)** | **مُصلَحة** — 2026-09-27. ربط النماذج بالمسار (`SubstituteBindings`) كان يعمل قبل الوسيط `brand.ready` الذي يضبط `CurrentBrand`، فالنطاق العام `BelongsToBrand` يأتي فارغاً في كل طلب إنتاج: حساب آخر يفتح محتوى علامة أخرى ويعدّله ويحذف صورها بمعرّفاتها. الاختبارات كانت تخفيها لأن العملية الواحدة تحمل براند الطلب السابق. الآن `prependToPriorityList(before: SubstituteBindings)` | `bootstrap/app.php`, `tests/Feature/BrandIsolationBindingTest.php` |
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

### 2.2 حذف جماعي — **مُنفَّذ (2026-09-26)**

وضع «تحديد» في المعرض مع حذف نهائي ونقل جماعي لمجلد (انظر §1). `POST /studio/media/bulk-destroy` و`bulk-move` يقبلان `ids[]` ويُسقط النطاق العام `BelongsToBrand` أي معرّف لعلامة أخرى.

### 2.5 إزالة الخلفية — **مُنفَّذ (2026-09-26)**

بنموذج صور يعلن `background=transparent` عبر OpenRouter (لا مكتبة محلية ولا remove.bg). النموذج يعيد رسم الصورة بخلفية شفافة؛ على عبوات الملصقات التي جرّبناها بقيت النصوص والشعار كما هي، لكنه توليد لا قصّ بكسلي، فقد تختلف تفاصيل دقيقة جداً. يتطلب أن يكون مزود الصور OpenRouter؛ بغيره يظهر البند معطّلاً بسببه.

### 2.6 حفظ الصورة كمرجع أساسي للمنتج — **مُنفَّذ (2026-09-26)**

`POST /studio/media/{asset}/to-product` ينسخ الملف إلى `brands/{id}/products/` كـ`ProductImage` ويجعله `is_reference` (استعلاما تحديث كما في `ProductController::applyReferenceImage`).

### 2.7 نشر إلى السوشيال ميديا — **مشاركة عبر الجهاز مُنفَّذة؛ النشر الآلي مؤجَّل**

- **المنفَّذ (2026-09-26)**: البند يفتح قائمة المشاركة في الجهاز بملف الصورة (Web Share)، أو ينسخها للحافظة حيث لا تدعمها المتصفحات.
- **المؤجَّل**: نشر مباشر على حسابات التاجر (Meta Graph API لإنستغرام/فيسبوك، TikTok، X، Snapchat) — يحتاج تطبيقات مطوّر معتمدة وOAuth لكل منصة وتخزين رموز الوصول، ثم جدولة النشر من الخطة الشهرية. لا تُعرض أي عبارة توحي بنشر آلي قبل ذلك.

### 2.9 صوت→نص وتحسين البرومبت — **مُنفَّذان (2026-09-26)**

- **صوت→نص**: Web Speech API داخل المتصفح (انظر §1). يعمل في Chrome وEdge وSafari؛ Firefox لا يدعمه فيظهر سبب ذلك عند الضغط.
- **تحسين البرومبت**: منفَّذ عبر مهمة طابور مصغّرة كما اقتُرح هنا (انظر الصف في §1). قرار التسعير مفتوح: مجاني الآن؛ الكلفة الفعلية ~$0.001–0.004 للطلب.
