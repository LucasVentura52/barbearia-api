<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Product;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\StaffService;
use App\Models\StaffTimeOff;
use App\Models\StaffWorkingHour;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class CompanyManagementController extends Controller
{
    public function companiesIndex()
    {
        $companies = Company::query()
            ->orderBy('name')
            ->get()
            ->map(function (Company $company) {
                return $this->serializeCompany($company);
            })
            ->values();

        return response()->json($companies);
    }

    public function companiesStore(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:160', 'unique:companies,slug'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $company = Company::query()->create([
            'name' => $data['name'],
            'slug' => Str::slug($data['slug']),
            'active' => $data['active'] ?? true,
        ]);

        return response()->json($this->serializeCompany($company), 201);
    }

    public function companiesUpdate(Request $request, Company $company)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'slug' => ['sometimes', 'string', 'max:160', Rule::unique('companies', 'slug')->ignore($company->id)],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $data)) {
            $company->name = $data['name'];
        }

        if (array_key_exists('slug', $data)) {
            $company->slug = Str::slug($data['slug']);
        }

        if (array_key_exists('active', $data)) {
            $company->active = $data['active'];
        }

        $company->save();

        return response()->json($this->serializeCompany($company));
    }

    public function companiesDestroy(Company $company)
    {
        $hasData = CompanyUser::query()->where('company_id', $company->id)->exists()
            || Service::withoutGlobalScopes()->where('company_id', $company->id)->exists()
            || Product::withoutGlobalScopes()->where('company_id', $company->id)->exists()
            || Appointment::withoutGlobalScopes()->where('company_id', $company->id)->exists();

        if ($hasData) {
            return response()->json([
                'message' => 'Company has related data and cannot be deleted. Deactivate instead.',
            ], 422);
        }

        $company->delete();

        return response()->json(['message' => 'Company deleted']);
    }

    public function usersIndex(Request $request)
    {
        $term = trim((string) $request->query('search', ''));
        $limit = min(200, max(1, (int) $request->query('limit', 50)));

        $query = User::query()->orderBy('name');

        if ($term !== '') {
            $query->where(function ($subQuery) use ($term) {
                $subQuery
                    ->where('name', 'ilike', '%' . $term . '%')
                    ->orWhere('phone', 'ilike', '%' . $term . '%')
                    ->orWhere('email', 'ilike', '%' . $term . '%');
            });
        }

        $users = $query->limit($limit)->get(['id', 'name', 'phone', 'email', 'role', 'created_at']);

        return response()->json($users);
    }

    public function usersStore(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['nullable', Rule::in([
                User::ROLE_CLIENT,
                User::ROLE_STAFF,
                User::ROLE_ADMIN,
                User::ROLE_SUPER_ADMIN,
            ])],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => $data['role'] ?? User::ROLE_CLIENT,
        ]);

        return response()->json($user, 201);
    }

    public function membershipsIndex(Company $company)
    {
        $memberships = CompanyUser::query()
            ->where('company_id', $company->id)
            ->with('user:id,name,phone,email,role,avatar_url')
            ->orderByDesc('active')
            ->orderBy('id')
            ->get()
            ->map(function (CompanyUser $membership) {
                return $this->serializeMembership($membership);
            })
            ->values();

        return response()->json($memberships);
    }

    public function membershipsStore(Request $request, Company $company)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', Rule::in([User::ROLE_CLIENT, User::ROLE_STAFF, User::ROLE_ADMIN])],
            'active' => ['sometimes', 'boolean'],
        ]);

        $membership = CompanyUser::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'user_id' => $data['user_id'],
            ],
            [
                'role' => $data['role'],
                'active' => $data['active'] ?? true,
            ]
        );

        $this->syncStaffDataForMembership($company->id, $membership->user_id, $membership->role, $membership->active);

        $membership->load('user:id,name,phone,email,role,avatar_url');

        return response()->json($this->serializeMembership($membership), 201);
    }

    public function membershipsUpdate(Request $request, Company $company, CompanyUser $membership)
    {
        if ($membership->company_id !== $company->id) {
            return response()->json(['message' => 'Membership not found for company'], 404);
        }

        $data = $request->validate([
            'role' => ['sometimes', Rule::in([User::ROLE_CLIENT, User::ROLE_STAFF, User::ROLE_ADMIN])],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('role', $data)) {
            $membership->role = $data['role'];
        }

        if (array_key_exists('active', $data)) {
            $membership->active = $data['active'];
        }

        $membership->save();

        $this->syncStaffDataForMembership($company->id, $membership->user_id, $membership->role, $membership->active);

        $membership->load('user:id,name,phone,email,role,avatar_url');

        return response()->json($this->serializeMembership($membership));
    }

    public function membershipsDestroy(Company $company, CompanyUser $membership)
    {
        if ($membership->company_id !== $company->id) {
            return response()->json(['message' => 'Membership not found for company'], 404);
        }

        $hasAppointments = Appointment::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where(function ($query) use ($membership) {
                $query
                    ->where('staff_user_id', $membership->user_id)
                    ->orWhere('client_user_id', $membership->user_id);
            })
            ->exists();

        if ($hasAppointments) {
            return response()->json([
                'message' => 'Membership has appointments and cannot be deleted. Deactivate instead.',
            ], 422);
        }

        $this->removeStaffDataForMembership($company->id, $membership->user_id);
        $membership->delete();

        return response()->json(['message' => 'Membership deleted']);
    }

    private function syncStaffDataForMembership(int $companyId, int $userId, string $role, bool $active): void
    {
        $isStaffRole = in_array($role, [User::ROLE_STAFF, User::ROLE_ADMIN], true);

        if (!$isStaffRole || !$active) {
            $this->removeStaffDataForMembership($companyId, $userId);
            return;
        }

        StaffProfile::withoutGlobalScopes()->updateOrCreate(
            [
                'company_id' => $companyId,
                'user_id' => $userId,
            ],
            [
                'active' => true,
            ]
        );
    }

    private function removeStaffDataForMembership(int $companyId, int $userId): void
    {
        StaffService::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('staff_user_id', $userId)
            ->delete();

        StaffWorkingHour::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('staff_user_id', $userId)
            ->delete();

        StaffTimeOff::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('staff_user_id', $userId)
            ->delete();

        StaffProfile::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->delete();
    }

    private function serializeCompany(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'slug' => $company->slug,
            'active' => (bool) $company->active,
            'created_at' => $company->created_at,
            'updated_at' => $company->updated_at,
        ];
    }

    private function serializeMembership(CompanyUser $membership): array
    {
        return [
            'id' => $membership->id,
            'company_id' => $membership->company_id,
            'user_id' => $membership->user_id,
            'role' => $membership->role,
            'active' => (bool) $membership->active,
            'created_at' => $membership->created_at,
            'updated_at' => $membership->updated_at,
            'user' => $membership->user ? [
                'id' => $membership->user->id,
                'name' => $membership->user->name,
                'phone' => $membership->user->phone,
                'email' => $membership->user->email,
                'global_role' => $membership->user->role,
                'avatar_url' => $membership->user->avatar_url,
            ] : null,
        ];
    }
}
