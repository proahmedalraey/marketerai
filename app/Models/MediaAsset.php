<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use App\Support\ImageThumbnail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MediaAsset extends Model
{
    use BelongsToBrand;

    protected $fillable = [
        'brand_id', 'content_item_id', 'generation_job_id', 'folder_id', 'is_pinned', 'kind',
        'disk', 'path', 'mime', 'bytes', 'width', 'height',
        'prompt', 'seed', 'meta', 'slide_index',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array', 'is_pinned' => 'boolean'];
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function generationJob(): BelongsTo
    {
        return $this->belongsTo(GenerationJob::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /** رابط مصغّرة الشبكة، أو الأصل إن لم تُنشأ لها مصغّرة (قديمة/صغيرة أصلاً). */
    public function thumbUrl(): string
    {
        $thumb = $this->meta['thumb'] ?? null;

        return $thumb ? Storage::disk($this->disk)->url($thumb) : $this->url();
    }

    /**
     * يكتب مصغّرة بجوار الأصل ويعيد مسارها لتُسجَّل في meta، أو null إن لم تلزم/تعذّرت.
     * فشلها لا يُسقط التوليد: تبقى الشبكة تعرض الأصل.
     */
    public static function putThumbnail(string $disk, string $originalPath, string $contents, bool $keepAlpha = false): ?string
    {
        $thumb = ImageThumbnail::make($contents, keepAlpha: $keepAlpha);

        if (! $thumb) {
            return null;
        }

        $path = ImageThumbnail::pathFor($originalPath, $keepAlpha ? 'png' : 'jpg');

        return Storage::disk($disk)->put($path, $thumb['contents']) ? $path : null;
    }

    /** صورة بلا خلفية: المعرض يعرضها فوق نقش الشطرنج ليظهر أنها شفافة. */
    public function isTransparent(): bool
    {
        return ($this->meta['background'] ?? null) === 'transparent';
    }

    /** يمسح الأصل ومصغّرته من التخزين (السجل يُحذف بشكل منفصل). */
    public function deleteFiles(): void
    {
        $disk = Storage::disk($this->disk);

        $disk->delete(array_filter([$this->path, $this->meta['thumb'] ?? null]));
    }
}
