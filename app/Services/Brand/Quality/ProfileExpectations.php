<?php

namespace App\Services\Brand\Quality;

use App\Support\Arabic\ArabicText;

/**
 * توقعات حالة تقييم مكتوبة قبل التشغيل: ما يجب أن يظهر في المخرج وما يجب ألا يظهر.
 *
 * ProfileQualityCheck يعرف القواعد العامة (لا رقم ولا رابط من خارج الإجابات)،
 * ولا يعرف ما يخص هذه الحالة: أن متجر الجملة يجب أن يُصنَّف جملة، أو أن
 * «خسارة وزن مضمونة» في ملاحظات النادي يجب ألا تصل للوصف. هذا مكانه.
 *
 * كل توقع: {"any": [كلمات بدائل], "in": نطاق, "label": وصف يظهر في التقرير}.
 * النطاقات: simple، detailed، descriptions (الوصفان)، activity_type، sales_summary،
 * advantages_directives، important_notes، technical (الأربعة)، all.
 */
class ProfileExpectations
{
    public const SCOPES = [
        'simple', 'detailed', 'descriptions', 'activity_type', 'sales_summary',
        'advantages_directives', 'important_notes', 'technical', 'all',
    ];

    /**
     * @param  array{mentions?: array, absent?: array}  $expect
     * @return array<int, array{code: string, severity: string, field: string, message: string}>
     */
    public function check(array $expect, array $draft): array
    {
        $issues = [];

        foreach ((array) ($expect['mentions'] ?? []) as $rule) {
            $scope = $this->scope($rule);

            if ($this->firstHit($this->text($draft, $scope), (array) ($rule['any'] ?? [])) === null) {
                $issues[] = [
                    'code' => 'expected_missing',
                    'severity' => 'warning',
                    'field' => $scope,
                    'message' => ($rule['label'] ?? 'متوقع').': لا يظهر أي من «'.implode('»، «', (array) $rule['any']).'».',
                ];
            }
        }

        foreach ((array) ($expect['absent'] ?? []) as $rule) {
            $scope = $this->scope($rule);

            if (($hit = $this->firstHit($this->text($draft, $scope), (array) ($rule['any'] ?? []))) !== null) {
                $issues[] = [
                    'code' => 'unexpected_mention',
                    'severity' => 'error',
                    'field' => $scope,
                    'message' => ($rule['label'] ?? 'ممنوع في هذه الحالة').": ظهر «{$hit}».",
                ];
            }
        }

        return $issues;
    }

    protected function scope(array $rule): string
    {
        return in_array($rule['in'] ?? null, self::SCOPES, true) ? $rule['in'] : 'descriptions';
    }

    protected function firstHit(string $text, array $words): ?string
    {
        foreach ($words as $word) {
            if (filled($word) && ArabicText::contains($text, (string) $word)) {
                return (string) $word;
            }
        }

        return null;
    }

    protected function text(array $draft, string $scope): string
    {
        $technical = (array) ($draft['technical'] ?? []);
        $notes = implode("\n", (array) ($technical['important_notes'] ?? []));

        $fields = [
            'simple' => (string) ($draft['simple'] ?? ''),
            'detailed' => (string) ($draft['detailed'] ?? ''),
            'activity_type' => (string) ($technical['activity_type'] ?? ''),
            'sales_summary' => (string) ($technical['sales_summary'] ?? ''),
            'advantages_directives' => (string) ($technical['advantages_directives'] ?? ''),
            'important_notes' => $notes,
        ];

        return match ($scope) {
            'descriptions' => $fields['simple']."\n".$fields['detailed'],
            'technical' => implode("\n", array_slice($fields, 2)),
            'all' => implode("\n", $fields),
            default => $fields[$scope],
        };
    }
}
