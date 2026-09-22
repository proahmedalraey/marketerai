<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MediaAsset extends Model
{
    use BelongsToBrand;

    protected $fillable = [
        'brand_id', 'content_item_id', 'generation_job_id', 'kind',
        'disk', 'path', 'mime', 'bytes', 'width', 'height',
        'prompt', 'seed', 'meta', 'slide_index',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function generationJob(): BelongsTo
    {
        return $this->belongsTo(GenerationJob::class);
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }
}
