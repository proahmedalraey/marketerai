<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledPost extends Model
{
    use BelongsToBrand;

    protected $fillable = [
        'brand_id', 'content_item_id', 'social_account_id', 'platform', 'caption_override', 'mode',
        'privacy', 'scheduled_at', 'status', 'attempts', 'platform_post_id', 'permalink',
        'response', 'error', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'response' => 'array',
            'status' => PostStatus::class,
        ];
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Queued->value)
            ->where('scheduled_at', '<=', now());
    }
}
