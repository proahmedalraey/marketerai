<?php

namespace App\Models;

use App\Enums\JobStatus;
use App\Models\Concerns\BelongsToBrand;
use App\Support\JobStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class GenerationJob extends Model
{
    use BelongsToBrand;

    protected $fillable = [
        'uuid', 'brand_id', 'user_id', 'type', 'provider', 'model', 'status',
        'payload', 'result', 'error', 'credits_held', 'credits_charged', 'attempts',
        'parent_id', 'children_total', 'children_done', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'credits_held' => 'decimal:1',
            'credits_charged' => 'decimal:1',
            'status' => JobStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (GenerationJob $job) => $job->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    /** ما كتبته هذه المهمة: تعرضه لوحة «إنتاجاتي» وتفتحه من زر «عرض النتائج». */
    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class, 'generation_job_id');
    }

    /** آخر العمليات لهذه العلامة. الأبناء لا تُعرض وحدها: أمّها تمثّلها. */
    public function scopeRecent($query, int $limit = 20)
    {
        return $query->whereNull('parent_id')->with('contentItems')->latest()->limit($limit);
    }

    public function markProcessing(): void
    {
        $this->update([
            'status' => JobStatus::Processing,
            'started_at' => now(),
            'attempts' => $this->attempts + 1,
        ]);
    }

    public function markCompleted(array $result = [], ?string $status = null): void
    {
        $this->update([
            'status' => $status ? JobStatus::from($status) : JobStatus::Completed,
            'result' => $result,
            'finished_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => JobStatus::Failed,
            'error' => Str::limit($error, 2000),
            'finished_at' => now(),
        ]);
    }

    public function progress(): int
    {
        if ($this->children_total === 0) {
            if ($this->status->isFinished()) {
                return 100;
            }

            // المرحلة الفعلية تعطي تقدماً أصدق من رقم ثابت، وتتحرك الشريطة كلما تقدمت المهمة
            return $this->status === JobStatus::Processing ? (JobStage::progress($this) ?? 50) : 5;
        }

        return (int) round(($this->children_done / max($this->children_total, 1)) * 100);
    }
}
