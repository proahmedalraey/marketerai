<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use App\Observers\BrandLogoObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

#[ObservedBy(BrandLogoObserver::class)]
class BrandLogo extends Model
{
    use BelongsToBrand;

    protected $fillable = ['brand_id', 'disk', 'path', 'label', 'is_default', 'sort'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function displayLabel(): string
    {
        return $this->label ?: 'شعار بلا اسم';
    }
}
