<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\CompanyUser;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class StaffManagementController extends Controller
{
    public function index(Request $request)
    {
        $companyId = (int) $this->currentCompanyId();
        $includeInactive = $request->boolean('include_inactive');

        $query = User::query()
            ->whereHas('companyMemberships', function ($query) use ($companyId, $includeInactive) {
                $query->where('company_id', $companyId)
                    ->whereIn('role', [User::ROLE_STAFF, User::ROLE_ADMIN]);

                if (!$includeInactive) {
                    $query->where('active', true);
                }
            })
            ->with([
                'companyMemberships' => function ($query) use ($companyId) {
                    $query->where('company_id', $companyId);
                },
                'staffProfile',
                'services' => function ($query) use ($companyId) {
                    $query->wherePivot('company_id', $companyId);
                },
            ]);

        if (!$includeInactive) {
            $query->whereHas('staffProfile', function ($q) {
                $q->where('active', true);
            });
        }

        $staff = $query->orderBy('name')->get()->map(function (User $user) use ($companyId) {
            return $this->serializeStaff($user, $companyId);
        })->values();

        return response()->json($staff);
    }

    public function store(Request $request)
    {
        $companyId = (int) $this->currentCompanyId();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['nullable', Rule::in([User::ROLE_STAFF, User::ROLE_ADMIN])],
            'bio' => ['nullable', 'string'],
            'instagram' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer', 'distinct', 'exists:services,id'],
        ]);

        $serviceIds = array_values(array_unique($data['service_ids'] ?? []));
        $this->assertServicesBelongToCompany($serviceIds);

        $role = $data['role'] ?? User::ROLE_STAFF;
        $active = $data['active'] ?? true;

        $user = User::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => $role,
        ]);

        CompanyUser::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'user_id' => $user->id,
            ],
            [
                'role' => $role,
                'active' => $active,
            ]
        );

        StaffProfile::query()->create([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'bio' => $data['bio'] ?? null,
            'instagram' => $data['instagram'] ?? null,
            'active' => $active,
        ]);

        $this->syncServicesForCompany($user, $companyId, $serviceIds);
        $user = $this->loadStaffRelations($user, $companyId);

        return response()->json($this->serializeStaff($user, $companyId), 201);
    }

    public function update(Request $request, User $user)
    {
        $companyId = (int) $this->currentCompanyId();

        $membership = $this->membershipForUser($user->id, $companyId);
        if (!$membership || !in_array($membership->role, [User::ROLE_STAFF, User::ROLE_ADMIN], true)) {
            return response()->json(['message' => 'Staff not found'], 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['sometimes', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($user->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:6'],
            'role' => ['nullable', Rule::in([User::ROLE_STAFF, User::ROLE_ADMIN])],
            'bio' => ['nullable', 'string'],
            'instagram' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer', 'distinct', 'exists:services,id'],
        ]);

        $serviceIds = null;
        if (array_key_exists('service_ids', $data)) {
            $serviceIds = array_values(array_unique($data['service_ids'] ?? []));
            $this->assertServicesBelongToCompany($serviceIds);
        }

        $user->fill([
            'name' => $data['name'] ?? $user->name,
            'phone' => $data['phone'] ?? $user->phone,
            'email' => array_key_exists('email', $data) ? $data['email'] : $user->email,
            'role' => $data['role'] ?? $membership->role,
        ]);

        if (!empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        if (array_key_exists('role', $data)) {
            $membership->role = $data['role'];
        }

        if (array_key_exists('active', $data)) {
            $membership->active = $data['active'];
        }

        $membership->save();

        $profile = StaffProfile::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->first();

        if (!$profile) {
            $profile = new StaffProfile([
                'company_id' => $companyId,
                'user_id' => $user->id,
                'active' => $membership->active,
            ]);
        }

        if (array_key_exists('bio', $data)) {
            $profile->bio = $data['bio'];
        }

        if (array_key_exists('instagram', $data)) {
            $profile->instagram = $data['instagram'];
        }

        if (array_key_exists('active', $data)) {
            $profile->active = $data['active'];
        }

        $profile->save();

        if (array_key_exists('service_ids', $data)) {
            $this->syncServicesForCompany($user, $companyId, $serviceIds ?: []);
        }

        $user = $this->loadStaffRelations($user, $companyId);

        return response()->json($this->serializeStaff($user, $companyId));
    }

    public function destroy(User $user)
    {
        $companyId = (int) $this->currentCompanyId();

        $membership = $this->membershipForUser($user->id, $companyId);
        if (!$membership || !in_array($membership->role, [User::ROLE_STAFF, User::ROLE_ADMIN], true)) {
            return response()->json(['message' => 'Staff not found'], 404);
        }

        $hasAppointments = Appointment::query()
            ->where('staff_user_id', $user->id)
            ->exists();

        if ($hasAppointments) {
            return response()->json(['message' => 'Staff has appointments and cannot be deleted. Deactivate instead.'], 422);
        }

        $this->syncServicesForCompany($user, $companyId, []);

        StaffProfile::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->delete();

        CompanyUser::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->delete();

        return response()->json(['message' => 'Staff deleted']);
    }

    private function serializeStaff(User $user, int $companyId): array
    {
        $membership = $user->companyMemberships->first();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'role' => $membership ? $membership->role : ($user->companyRole($companyId) ?: User::ROLE_STAFF),
            'avatar_url' => $user->avatar_url,
            'profile' => $user->staffProfile ? [
                'bio' => $user->staffProfile->bio,
                'instagram' => $user->staffProfile->instagram,
                'active' => $user->staffProfile->active,
            ] : [
                'bio' => null,
                'instagram' => null,
                'active' => false,
            ],
            'services' => $user->services->map(function (Service $service) {
                return [
                    'id' => $service->id,
                    'name' => $service->name,
                    'duration_minutes' => $service->duration_minutes,
                    'price' => $service->price,
                    'active' => $service->active,
                ];
            })->values(),
        ];
    }

    private function loadStaffRelations(User $user, int $companyId): User
    {
        $user->load([
            'companyMemberships' => function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            },
            'staffProfile',
            'services' => function ($query) use ($companyId) {
                $query->wherePivot('company_id', $companyId);
            },
        ]);

        return $user;
    }

    private function membershipForUser(int $userId, int $companyId): ?CompanyUser
    {
        return CompanyUser::query()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->first();
    }

    private function syncServicesForCompany(User $user, int $companyId, array $serviceIds): void
    {
        $user->services()
            ->newPivotStatement()
            ->where('staff_user_id', $user->id)
            ->where('company_id', $companyId)
            ->delete();

        if (empty($serviceIds)) {
            return;
        }

        $attachPayload = [];
        foreach ($serviceIds as $serviceId) {
            $attachPayload[$serviceId] = ['company_id' => $companyId];
        }

        $user->services()->attach($attachPayload);
    }

    private function assertServicesBelongToCompany(array $serviceIds): void
    {
        if (empty($serviceIds)) {
            return;
        }

        $count = Service::query()->whereIn('id', $serviceIds)->count();
        if ($count !== count($serviceIds)) {
            abort(422, 'service_ids contain services outside the selected company');
        }
    }
}
