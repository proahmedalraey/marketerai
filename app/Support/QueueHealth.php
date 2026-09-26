<?php

namespace App\Support;

use App\Enums\JobStatus;
use App\Models\GenerationJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * هل عامل الطابور متوقف؟
 *
 * بلا عامل تبقى المهمة «جارية» إلى الأبد بلا أي رسالة، فيظنها التاجر عطلاً في الذكاء
 * الاصطناعي (حدث فعلاً: مهمتان انتظرتا 3.5 و5 دقائق). العامل الحي يلتقط المهمة الجديدة
 * خلال ثوانٍ، فمهمة بقيت في الانتظار أكثر من نصف دقيقة وليس في الطابور أي مهمة قيد
 * التنفيذ = لا عامل. مع وجود دفعة طويلة تكون هناك مهمة محجوزة دائماً، فلا يُبلَّغ
 * عن عطل وهمي.
 *
 * يعمل مع طابور قاعدة البيانات فقط (الافتراضي هنا)؛ غيره لا نملك عنه إشارة موثوقة.
 */
class QueueHealth
{
    /** أطول انتظار طبيعي قبل أن نشك: العامل الحي يستطلع كل ثلاث ثوانٍ */
    public const STALLED_AFTER_SECONDS = 30;

    /** آخر نشاط للعامل يُعدّ حياً إن كان أحدث من هذا (يملأ الفجوة بين مهمتين متتاليتين) */
    public const HEARTBEAT_SECONDS = 20;

    public const HEARTBEAT_KEY = 'queue.heartbeat';

    /** نتيجة الفحص العام لهذا الطلب: لوحة «إنتاجاتي» تسأل عن عدة مهام معاً */
    protected static ?bool $noWorker = null;

    public static function isStalled(GenerationJob $job): bool
    {
        if ($job->status !== JobStatus::Queued) {
            return false;
        }

        if ($job->created_at->gt(now()->subSeconds(self::STALLED_AFTER_SECONDS))) {
            return false;
        }

        return static::noWorkerRunning();
    }

    public static function noWorkerRunning(): bool
    {
        return static::$noWorker ??= static::detect();
    }

    /** يُستدعى من مستمعي الطابور: كل مهمة تبدأ أو تنتهي دليل على أن عاملاً حياً. */
    public static function beat(): void
    {
        try {
            Cache::put(self::HEARTBEAT_KEY, time(), 3600);
        } catch (Throwable) {
            // النبض تحسين للتشخيص: لا يُسقط مهمة
        }
    }

    public static function forget(): void
    {
        static::$noWorker = null;
    }

    protected static function detect(): bool
    {
        if (config('queue.default') !== 'database') {
            return false;
        }

        try {
            $table = config('queue.connections.database.table', 'jobs');

            if (DB::table($table)->whereNotNull('reserved_at')->exists()) {
                return false;
            }

            $last = (int) Cache::get(self::HEARTBEAT_KEY, 0);

            return time() - $last > self::HEARTBEAT_SECONDS;
        } catch (Throwable) {
            // لا نستطيع الفحص: لا ننذر بلا دليل
            return false;
        }
    }
}
