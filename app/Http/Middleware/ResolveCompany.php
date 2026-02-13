<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ResolveCompany
{
    public function handle(Request $request, Closure $next)
    {
        $company = $this->resolveCompany($request);

        if (!$company) {
            return response()->json(['message' => 'Company context not found'], 422);
        }

        app(TenantContext::class)->setCompany($company);
        $request->attributes->set('company', $company);

        $user = $request->user('sanctum');
        $isSuperAdmin = $user && $user->role === 'super_admin';
        if ($user) {
            if ($isSuperAdmin) {
                return $next($request);
            }

            $isMember = CompanyUser::query()
                ->where('company_id', $company->id)
                ->where('user_id', $user->id)
                ->where('active', true)
                ->exists();

            if (!$isMember) {
                return response()->json(['message' => 'Forbidden for selected company'], 403);
            }
        }

        return $next($request);
    }

    private function resolveCompany(Request $request): ?Company
    {
        $headerSlug = $request->header('X-Company-Slug');
        $querySlug = $request->query('company_slug');
        $companySlug = trim((string) ($headerSlug ?: $querySlug));

        $headerCompanyId = $request->header('X-Company-Id');
        $queryCompanyId = $request->query('company_id');
        $companyId = (int) ($headerCompanyId ?: $queryCompanyId ?: 0);

        if ($companySlug !== '') {
            $company = Company::query()
                ->where('slug', Str::slug($companySlug))
                ->where('active', true)
                ->first();

            if ($company) {
                return $company;
            }
        }

        if ($companyId > 0) {
            $company = Company::query()
                ->whereKey($companyId)
                ->where('active', true)
                ->first();

            if ($company) {
                return $company;
            }
        }

        $user = $request->user('sanctum');
        if ($user) {
            $companies = Company::query()
                ->join('company_user', 'company_user.company_id', '=', 'companies.id')
                ->where('company_user.user_id', $user->id)
                ->where('company_user.active', true)
                ->where('companies.active', true)
                ->select('companies.*')
                ->orderBy('companies.id')
                ->get();

            if ($companies->count() === 1) {
                return $companies->first();
            }

            if ($companies->count() > 1) {
                $defaultSlug = Str::slug(env('DEFAULT_COMPANY_SLUG', 'default')) ?: 'default';
                foreach ($companies as $candidate) {
                    if ($candidate->slug === $defaultSlug) {
                        return $candidate;
                    }
                }

                return $companies->first();
            }
        }

        $defaultSlug = Str::slug(env('DEFAULT_COMPANY_SLUG', 'default')) ?: 'default';

        $defaultCompany = Company::query()
            ->where('slug', $defaultSlug)
            ->where('active', true)
            ->first();

        if ($defaultCompany) {
            return $defaultCompany;
        }

        return Company::query()
            ->where('active', true)
            ->orderBy('id')
            ->first();
    }
}
