<?php

namespace App\Services\AI;

use RuntimeException;

class ProviderException extends RuntimeException
{
    /** أقصى انتظار نقبله داخل الطلب نفسه قبل إعادة المحاولة. أطول منه: نفشل فوراً. */
    public const MAX_INLINE_WAIT_SECONDS = 10;

    public function __construct(
        string $message,
        public readonly string $provider = '',
        public readonly ?int $statusCode = null,
        public readonly bool $retryable = false,
        // الحصة نفدت (يومية أو فوترة): لن تعود قبل ساعات، والإعادة تستنزف ما بقي
        public readonly bool $quotaExhausted = false,
        // ما يطلبه المزود صراحة قبل المحاولة التالية
        public readonly ?int $retryAfterSeconds = null,
        // رسالة للتاجر حين لا يصف رمز الحالة ما حدث (رد مقطوع بحالة 200 مثلاً)
        public readonly ?string $display = null,
    ) {
        parent::__construct($message);
    }

    /**
     * يصنّف الخطأ من حالته ونصه.
     *
     * 429 ليس نوعاً واحداً: حد الدقيقة يزول بعد ثوانٍ فتستحق الإعادة،
     * أما الحصة اليومية أو نفاد الرصيد فكل إعادة طلبٌ ضائع من حصة شحيحة
     * أصلاً — وكانت المهمة الواحدة تُرسل حتى ستة طلبات عليها.
     */
    public static function fromStatus(string $provider, int $status, string $body, ?string $retryAfter = null): self
    {
        $json = json_decode($body, true);
        $json = is_array($json) ? $json : [];

        $exhausted = $status === 429 && static::isQuotaExhausted($body, $json);
        $wait = static::retryAfterSeconds($retryAfter, $json);

        $retryable = match (true) {
            $status >= 500 => true,
            $status === 429 => ! $exhausted && ($wait === null || $wait <= self::MAX_INLINE_WAIT_SECONDS),
            default => false,
        };

        return new self(
            "خطأ من مزود {$provider} (HTTP {$status}): ".mb_substr($body, 0, 300),
            $provider,
            $status,
            $retryable,
            $exhausted,
            $wait,
        );
    }

    /**
     * رسالة تصلح للعرض على صاحب المتجر: بلا JSON ولا مصطلحات مزود.
     * التفاصيل الكاملة تبقى في getMessage() وفي السجل.
     */
    public function userMessage(): string
    {
        return match (true) {
            $this->display !== null => $this->display,
            $this->quotaExhausted => 'نفدت الحصة المتاحة لخدمة الذكاء الاصطناعي حالياً. أُرجعت نقاطك — حاول لاحقاً.',
            $this->statusCode === 429 => 'خدمة الذكاء الاصطناعي مزدحمة الآن. أُرجعت نقاطك — حاول بعد دقيقة.',
            $this->statusCode !== null && $this->statusCode >= 500 => 'خدمة الذكاء الاصطناعي غير متاحة مؤقتاً. أُرجعت نقاطك — حاول بعد قليل.',
            default => 'تعذّر التوليد بسبب خطأ في الطلب. أُرجعت نقاطك.',
        };
    }

    /** نص رسالة مناسب للعرض من أي استثناء، مزوداً كان أو غيره. */
    public static function messageFor(\Throwable $e): string
    {
        return $e instanceof self ? $e->userMessage() : 'تعذّر التوليد. أُرجعت نقاطك — جرّب مجدداً.';
    }

    /**
     * Gemini: quotaId يحوي PerDay · OpenAI: insufficient_quota أو billing ·
     * Anthropic: رصيد غير كافٍ يأتي 400 لا 429 فلا يمر من هنا.
     */
    protected static function isQuotaExhausted(string $body, array $json): bool
    {
        foreach ((array) data_get($json, 'error.details', []) as $detail) {
            foreach ((array) ($detail['violations'] ?? []) as $violation) {
                if (str_contains((string) ($violation['quotaId'] ?? ''), 'PerDay')) {
                    return true;
                }
            }
        }

        $code = (string) data_get($json, 'error.code', '');
        $type = (string) data_get($json, 'error.type', '');

        return in_array('insufficient_quota', [$code, $type], true)
            || str_contains($body, 'PerDay')
            || str_contains($body, 'billing_hard_limit');
    }

    /** ترويسة Retry-After أولاً، ثم retryDelay من تفاصيل Gemini («35.4s»). */
    protected static function retryAfterSeconds(?string $header, array $json): ?int
    {
        if (is_numeric($header)) {
            return (int) ceil((float) $header);
        }

        foreach ((array) data_get($json, 'error.details', []) as $detail) {
            if (isset($detail['retryDelay']) && preg_match('/^([\d.]+)s$/', (string) $detail['retryDelay'], $m)) {
                return (int) ceil((float) $m[1]);
            }
        }

        return null;
    }
}
