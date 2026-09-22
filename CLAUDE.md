# Marketer Ai

منصة Laravel 12 + Livewire 3 عربية RTL: تحوّل هوية المتجر ومنتجاته لمحتوى تسويقي (نص، كاروسيل، صور، خطة شهرية).
شرح كامل وحديث في [README.md](README.md) — لا تكرره هنا، ارجع له عند الحاجة بدل إعادة اكتشاف البنية بالبحث.

## تشغيل واختبار

```bash
php artisan serve                                                        # خادم الويب
php artisan queue:listen --queue=content,media,default --tries=2 --timeout=600   # إلزامي: queue:listen لا queue:work في التطوير
npm run dev                                                              # Vite
php artisan test                                                         # PHPUnit
php artisan content:eval                                                 # قياس بوابة الصدق
vendor/bin/pint                                                          # تنسيق الكود (PSR-12)
```

بديل تطوير سريع بلا عامل طابور: `QUEUE_CONNECTION=sync` في `.env`.

## قواعد معمارية ملزمة (لا تكسرها)

1. **لا استدعاء نموذج ذكاء داخل طلب HTTP.** كل توليد عبر Job + طابور، يُستطلع من `/api/jobs/{uuid}`.
2. **لا يستدعي أي كود مزوّد ذكاء مباشرة.** كل شيء يمر عبر `App\Services\AI\AiManager`. الدرايفرات في `app/Services/AI/Drivers/` (anthropic, openai, fake).
3. **النقاط تُحجز قبل التنفيذ وتُسوّى بعده** — `app/Services/Credits/`. لا خصم مباشر.
4. **بوابة الصدق (`ContentQualityCheck`) إلزامية بعد كل توليد** — تفحص أي رقم/ادعاء/كود خصم لم يذكره التاجر فعليًا. القواعد في `config/claims.php` و`config/dialects.php`.
5. **جودة المخرجات من `config/content.php` لا من البرومبت الحر.** الأهداف، المنصات، القوالب، والقواعد الممنوعة كلها هناك — تعديله يغيّر كل التوليدات بلا نشر كود.
6. **عزل المستأجر (multi-brand)** عبر `BelongsToBrand` Global Scope في `app/Models/Concerns/`.

## بنية سريعة

```
app/Services/AI/        طبقة النماذج (Contracts, Drivers, Prompts, AiManager)
app/Services/Content/    محرك التوليد + بوابة الصدق (Quality/)
app/Services/Credits/    دفتر النقاط
app/Services/Media/      صور وكاروسيل
app/Services/Brand/      هوية العلامة (Quality/ للفحص)
app/Services/Import/     استيراد من سلة (Adapters/)
app/Jobs/                مهام الطوابير
config/content.php       عقل المنصة: أهداف، منصات، قوالب، قواعد
```

## أثناء التطوير هنا (كفاءة التوكنات)

- **لا تقرأ `docs/*.md` كاملة إلا عند الحاجة الفعلية** — طويلة (24-36 ألف حرف). استخدم Grep على العناوين أو اقرأ نطاق أسطر محدد:
  - [docs/sahal-audit-plan.md](docs/sahal-audit-plan.md): خطة تطبيق تدقيق منافس "سهل AI" — القرارات المعتمدة في §9 (سطر 261).
  - [docs/brand-identity-spec.md](docs/brand-identity-spec.md): مواصفة هوية العلامة وجمع بياناتها — القرارات في §0 (سطر 8) و§0.1 (سطر 26).
- **لا تبحث داخل `vendor/` أو `node_modules/`** (152MB / 43MB) — الإجابة شبه دائمًا في `app/` أو `config/` أو `resources/`.
- **مهمة جديدة غير مرتبطة = محادثة جديدة**, لا إطالة جلسة قديمة محمّلة بسياق سابق غير ذي صلة.
- الذاكرة الدائمة بين المحادثات في `C:\Users\LENOVO\.claude\projects\D--claude-Marketer-Ai\memory\` — راجعها إذا بدت المهمة مرتبطة بقرار سابق (تدقيق سهل، صفحة كتابة المحتوى…) بدل إعادة السؤال.
