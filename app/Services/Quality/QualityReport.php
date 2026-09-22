<?php

namespace App\Services\Quality;

/**
 * نتيجة فحص مخرج واحد من النموذج (ملف هوية أو منشور).
 *
 * الخطأ يعني مخرجاً لا يصح أن يصل للمستخدم (رقم مخترع، كلمة ممنوعة).
 * التحذير يعني مخرجاً صالحاً لكنه خالف توجيهاً (طول، تنسيق).
 */
class QualityReport
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    /** @var array<int, array{code: string, severity: string, field: string, message: string}> */
    protected array $issues = [];

    public function add(string $code, string $severity, string $field, string $message): void
    {
        $this->issues[] = compact('code', 'severity', 'field', 'message');
    }

    /** @return array<int, array{code: string, severity: string, field: string, message: string}> */
    public function issues(): array
    {
        return $this->issues;
    }

    public function errors(): array
    {
        return array_values(array_filter($this->issues, fn ($i) => $i['severity'] === self::ERROR));
    }

    public function warnings(): array
    {
        return array_values(array_filter($this->issues, fn ($i) => $i['severity'] === self::WARNING));
    }

    public function passes(): bool
    {
        return $this->errors() === [];
    }

    /** رموز المشاكل فقط — تكفي للمطابقة في الاختبارات. */
    public function codes(): array
    {
        return array_values(array_unique(array_column($this->issues, 'code')));
    }

    public function has(string $code): bool
    {
        return in_array($code, $this->codes(), true);
    }

    /**
     * درجة تقريبية للمقارنة بين النماذج والبرومبتات، لا حكماً مطلقاً:
     * الخطأ يكلّف أكثر من ثلاثة تحذيرات لأن أثره يصل للعميل.
     */
    public function score(): int
    {
        return max(0, 100 - 25 * count($this->errors()) - 8 * count($this->warnings()));
    }
}
