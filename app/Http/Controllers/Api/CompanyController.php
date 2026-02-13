<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        $companies = Company::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'active']);

        $currentCompany = $this->currentCompany();

        return response()->json([
            'current' => $currentCompany ? [
                'id' => $currentCompany->id,
                'name' => $currentCompany->name,
                'slug' => $currentCompany->slug,
            ] : null,
            'companies' => $companies,
        ]);
    }

    public function my(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'super_admin') {
            $companies = Company::query()
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'active'])
                ->map(function (Company $company) {
                    return [
                        'id' => $company->id,
                        'name' => $company->name,
                        'slug' => $company->slug,
                        'active' => $company->active,
                        'membership' => [
                            'role' => 'super_admin',
                            'active' => true,
                        ],
                    ];
                })
                ->values();

            return response()->json([
                'current' => $this->currentCompany() ? [
                    'id' => $this->currentCompany()->id,
                    'name' => $this->currentCompany()->name,
                    'slug' => $this->currentCompany()->slug,
                ] : null,
                'companies' => $companies,
            ]);
        }

        $companies = Company::query()
            ->join('company_user', 'company_user.company_id', '=', 'companies.id')
            ->where('company_user.user_id', $user->id)
            ->where('company_user.active', true)
            ->select([
                'companies.id',
                'companies.name',
                'companies.slug',
                'companies.active',
                'company_user.role as membership_role',
                'company_user.active as membership_active',
            ])
            ->orderBy('companies.name')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'slug' => $row->slug,
                    'active' => (bool) $row->active,
                    'membership' => [
                        'role' => $row->membership_role,
                        'active' => (bool) $row->membership_active,
                    ],
                ];
            })
            ->values();

        return response()->json([
            'current' => $this->currentCompany() ? [
                'id' => $this->currentCompany()->id,
                'name' => $this->currentCompany()->name,
                'slug' => $this->currentCompany()->slug,
            ] : null,
            'companies' => $companies,
        ]);
    }
}
