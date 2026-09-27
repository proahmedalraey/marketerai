<?php

namespace App\Support;

use App\Enums\JobStatus;
use App\Models\GenerationJob;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * المرحلة الحالية لمهمة تعمل: «يكتب… يفحص… يراجع…» بدل «جارية» فقط.
 *
 * كل مهمة توليد سلسلة طلبات متتابعة (كتابة ← فحص ← [تصحيح] ← مراجعة لغوية ← حفظ)،
 * تستغرق معاً 10 إلى 30 ثانية. من يرى «جارية» طوال هذه المدة لا يعرف أهي تعمل أم علقت.
 *
 * المرحلة حالة عابرة تخص مهمة تعمل الآن، فتُحفظ في الكاش لا في جدول المهام:
 * لا ترحيل ولا كتابة إضافية في قاعدة البيانات، وتزول وحدها بعد ساعة.
 * يعيّنها AiManager من نوع العملية، فلا تحتاج أي خدمة أن تعرف بها.
 */
class JobStage
{
    /** المفتاح ⇒ النص المعروض ونسبة التقدم التقريبية عنده */
    public const STAGES = [
        'writing' => ['label' => 'يكتب النص', 'progress' => 30],
        'checking' => ['label' => 'يفحص الأرقام والادعاءات', 'progress' => 55],
        'fixing' => ['label' => 'يصحّح ما وجده الفحص', 'progress' => 65],
        'proofreading' => ['label' => 'يراجع الإملاء واللغة', 'progress' => 80],
        'drawing' => ['label' => 'يرسم الصورة', 'progress' => 50],
        'enhancing' => ['label' => 'يحسّن الوصف', 'progress' => 50],
        'scripting' => ['label' => 'يجهّز النص للإلقاء', 'progress' => 50],
        'voicing' => ['label' => 'يسجّل التعليق الصوتي', 'progress' => 45],
        'saving' => ['label' => 'يحفظ النتيجة', 'progress' => 92],
    ];

    /** المرحلة التي تبدأ عند بدء طلب من هذا النوع */
    public static function forOperation(string $operation): ?string
    {
        return match (true) {
            $operation === 'content.correction' => 'fixing',
            $operation === 'content.proofread' => 'proofreading',
            $operation === 'prompt.enhance' => 'enhancing',
            $operation === 'voice.script' => 'scripting',
            $operation === 'voice.speech' => 'voicing',
            str_starts_with($operation, 'image') => 'drawing',
            str_starts_with($operation, 'content.'),
            str_starts_with($operation, 'brand.'),
            str_starts_with($operation, 'product.') => 'writing',
            default => null,
        };
    }

    /** المرحلة التي تلي طلباً ناجحاً من هذا النوع (ما يعمله النظام بعده) */
    public static function after(string $operation): ?string
    {
        return match (true) {
            $operation === 'content.proofread',
            $operation === 'voice.speech' => 'saving',
            // بعد الكتابة والتصحيح تمر النسخة ببوابة الصدق قبل أن تُدقَّق
            str_starts_with($operation, 'content.') => 'checking',
            str_starts_with($operation, 'brand.'),
            str_starts_with($operation, 'product.') => 'saving',
            default => null,
        };
    }

    public static function set(?GenerationJob $job, ?string $stage): void
    {
        if ($job === null || $stage === null) {
            return;
        }

        try {
            Cache::put(static::key($job), $stage, 3600);
        } catch (Throwable) {
            // المرحلة تحسين للعرض: لا تُسقط توليداً
        }
    }

    /** مفتاح المرحلة الحالية، أو null إن لم تعمل المهمة أو لم تُعيَّن مرحلة. */
    public static function current(GenerationJob $job): ?string
    {
        if ($job->status !== JobStatus::Processing) {
            return null;
        }

        try {
            $stage = Cache::get(static::key($job));
        } catch (Throwable) {
            return null;
        }

        return isset(self::STAGES[$stage]) ? $stage : null;
    }

    /** النص المعروض للتاجر: المرحلة إن عُرفت، وإلا حالة المهمة العامة. */
    public static function label(GenerationJob $job): ?string
    {
        if ($job->status->isFinished()) {
            return null;
        }

        if ($stage = static::current($job)) {
            return self::STAGES[$stage]['label'];
        }

        return $job->status === JobStatus::Queued ? 'في الانتظار' : 'قيد التنفيذ';
    }

    public static function progress(GenerationJob $job): ?int
    {
        $stage = static::current($job);

        return $stage ? self::STAGES[$stage]['progress'] : null;
    }

    protected static function key(GenerationJob $job): string
    {
        return 'job.stage.'.$job->id;
    }
}
