<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaFolder extends Model
{
    use BelongsToBrand;

    protected $fillable = ['brand_id', 'name'];

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'folder_id');
    }
}
