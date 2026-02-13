<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $this->currentCompanyId();

        $staff = User::query()
            ->withCompanyRoles($companyId, [User::ROLE_STAFF, User::ROLE_ADMIN])
            ->whereHas('staffProfile', function ($query) {
                $query->where('active', true);
            })
            ->with([
                'companyMemberships' => function ($query) use ($companyId) {
                    $query->where('company_id', $companyId)
                        ->where('active', true);
                },
                'staffProfile',
                'services' => function ($query) use ($companyId) {
                    $query->where('active', true)
                        ->wherePivot('company_id', $companyId)
                        ->orderBy('name');
                },
            ])
            ->orderBy('name')
            ->get()
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'avatar_url' => $user->avatar_url,
                    'role' => optional($user->companyMemberships->first())->role ?: User::ROLE_STAFF,
                    'profile' => $user->staffProfile ? [
                        'bio' => $user->staffProfile->bio,
                        'instagram' => $user->staffProfile->instagram,
                    ] : null,
                    'services' => $user->services->map(function ($service) {
                        return [
                            'id' => $service->id,
                            'name' => $service->name,
                            'duration_minutes' => $service->duration_minutes,
                            'price' => $service->price,
                            'photo_url' => $service->photo_url,
                        ];
                    })->values(),
                ];
            })
            ->values();

        return response()->json($staff);
    }
}
