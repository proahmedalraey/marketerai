<?php

namespace App\Models\Concerns;

use App\Models\Brand;
use App\Support\CurrentBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * عزل المستأجر.
 *
 * كل نموذج يستخدم هذه السمة يُفلتر تلقائياً على البراند الحالي،
 * ويُملأ brand_id عند الإنشاء. هذا يمنع تسرب بيانات بين البراندات
 * حتى لو نسي المطور شرط where في استعلام جديد.
 *
 * للخروج من النطاق عمداً (لوحة الإدارة، المهام المجدولة):
 *   Model::withoutBrandScope()->...
 */
trait BelongsToBrand
{
    public static function bootBelongsToBrand(): void
    {
        static::addGlobalScope('brand', function (Builder $builder) {
            if ($brandId = CurrentBrand::id()) {
                $builder->where($builder->getModel()->getTable().'.brand_id', $brandId);
            }
        });

        static::creating(function ($model) {
            if (empty($model->brand_id) && ($brandId = CurrentBrand::id())) {
                $model->brand_id = $brandId;
            }
        });
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function scopeWithoutBrandScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('brand');
    }

    public function scopeForBrand(Builder $query, Brand|int $brand): Builder
    {
        return $query->withoutGlobalScope('brand')
            ->where($query->getModel()->getTable().'.brand_id', $brand instanceof Brand ? $brand->id : $brand);
    }
}
