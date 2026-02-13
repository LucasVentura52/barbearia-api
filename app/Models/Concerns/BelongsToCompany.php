<?php

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToCompany
{
    public static function bootBelongsToCompany()
    {
        static::addGlobalScope('company', function (Builder $builder) {
            if (!app()->bound(TenantContext::class)) {
                return;
            }

            $companyId = app(TenantContext::class)->id();
            if (!$companyId) {
                return;
            }

            $builder->where($builder->getModel()->getTable() . '.company_id', $companyId);
        });

        static::creating(function ($model) {
            if (!app()->bound(TenantContext::class) || !empty($model->company_id)) {
                return;
            }

            $companyId = app(TenantContext::class)->id();
            if ($companyId) {
                $model->company_id = $companyId;
            }
        });
    }
}
