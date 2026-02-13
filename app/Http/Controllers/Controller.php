<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Support\TenantContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected function currentCompany(): ?Company
    {
        return app(TenantContext::class)->company();
    }

    protected function currentCompanyId(): ?int
    {
        return app(TenantContext::class)->id();
    }
}
