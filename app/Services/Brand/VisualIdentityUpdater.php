<?php

namespace App\Services\Brand;

use App\Enums\ColorRole;
use App\Models\Brand;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * كتابة الهوية البصرية.
 *
 * المنطق هنا لا في المتحكم لأن ثلاثة مسارات ستكتب هذه الحقول:
 * اللوحة، ومعالج التسجيل، ولاحقاً استيراد دليل الهوية.
 */
class VisualIdentityUpdater
{
    public function disk(): string
    {
        return config('ai.media_disk', 'public');
    }

    /**
     * حقول النموذج الرئيسي. الأنماط لها مسارها الخاص لأنها تُرفع فور اختيارها.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Brand $brand, array $data): Brand
    {
        $brand->update([
            'colors' => $this->normalizeColors($data['colors'] ?? []),
            'fonts' => $this->normalizeFonts($data['fonts'] ?? []),
            'visual_style' => $this->trimOrNull($data['visual_style'] ?? null),
            'design_summary' => $this->trimOrNull($data['design_summary'] ?? null),
        ]);

        return $brand;
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function addPatterns(Brand $brand, array $files): Brand
    {
        if ($files === []) {
            return $brand;
        }

        $brand->update(['patterns' => $this->appendPatterns($brand, $files)]);

        return $brand;
    }

    /**
     * يحذف نمطاً بموضعه ويعيد الترقيم.
     * الموضع لا المعرّف: الأنماط صور بلا سجل خاص بها.
     */
    public function removePattern(Brand $brand, int $index): Brand
    {
        $patterns = array_values((array) $brand->patterns);

        if (! isset($patterns[$index])) {
            return $brand;
        }

        Storage::disk($this->disk())->delete($patterns[$index]['path'] ?? '');

        unset($patterns[$index]);

        $brand->update(['patterns' => $this->reindex($patterns)]);

        return $brand;
    }

    /** المساحة المتبقية تحت السقف. */
    public function patternSlotsLeft(Brand $brand): int
    {
        return max(0, (int) config('brand.patterns_max', 6) - count((array) $brand->patterns));
    }

    /**
     * الألوان: كائنات لا أكواد.
     * الصف بلا كود لون يُحذف — الاسم وحده لا يرسم شيئاً.
     *
     * @return array<int, array{hex: string, name: ?string, role: string}>
     */
    protected function normalizeColors(array $rows): array
    {
        return collect($rows)
            ->map(function ($row) {
                $hex = $this->normalizeHex((string) ($row['hex'] ?? ''));

                if ($hex === null) {
                    return null;
                }

                return [
                    'hex' => $hex,
                    'name' => $this->trimOrNull($row['name'] ?? null),
                    'role' => (ColorRole::tryFrom((string) ($row['role'] ?? '')) ?? ColorRole::Secondary)->value,
                ];
            })
            ->filter()
            ->take((int) config('brand.colors_max', 8))
            ->values()
            ->all();
    }

    /** #FFF و fff و #FFFFFF كلها تُكتب بصيغة واحدة، وإلا تكرر اللون نفسه بثلاثة أشكال. */
    protected function normalizeHex(string $value): ?string
    {
        $hex = ltrim(trim($value), '#');

        if (! preg_match('/^([0-9a-f]{3}|[0-9a-f]{6})$/i', $hex)) {
            return null;
        }

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.strtoupper($hex);
    }

    /**
     * @return array<string, string>|null
     */
    protected function normalizeFonts(array $fonts): ?array
    {
        $clean = collect(config('brand.font_slots', []))
            ->keys()
            ->mapWithKeys(fn (string $slot) => [$slot => $this->trimOrNull($fonts[$slot] ?? null)])
            ->filter()
            ->all();

        return $clean === [] ? null : $clean;
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array{path: string, sort: int}>
     */
    protected function appendPatterns(Brand $brand, array $files): array
    {
        $patterns = array_values((array) $brand->patterns);
        $slots = $this->patternSlotsLeft($brand);

        foreach (array_slice($files, 0, $slots) as $file) {
            $patterns[] = [
                'path' => $file->store("brands/{$brand->id}/patterns", $this->disk()),
                'sort' => count($patterns),
            ];
        }

        return $this->reindex($patterns);
    }

    /** @return array<int, array{path: string, sort: int}> */
    protected function reindex(array $patterns): array
    {
        return collect($patterns)
            ->values()
            ->map(fn ($pattern, $i) => ['path' => $pattern['path'], 'sort' => $i])
            ->all();
    }

    protected function trimOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
