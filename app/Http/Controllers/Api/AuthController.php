<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $company = $this->currentCompany();
        if (!$company) {
            return response()->json(['message' => 'Company context not found'], 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => User::ROLE_CLIENT,
        ]);

        CompanyUser::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'user_id' => $user->id,
            ],
            [
                'role' => User::ROLE_CLIENT,
                'active' => true,
            ]
        );

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->serializeUserForCompany($user, $company->id),
        ], 201);
    }

    public function login(Request $request)
    {
        $company = $this->currentCompany();
        if (!$company) {
            return response()->json(['message' => 'Company context not found'], 422);
        }

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('phone', $data['phone'])->first();

        if (!$user || !$user->password || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Credenciais inválidas'], 422);
        }

        if ($user->role !== User::ROLE_SUPER_ADMIN) {
            $membership = CompanyUser::query()
                ->where('company_id', $company->id)
                ->where('user_id', $user->id)
                ->where('active', true)
                ->first();

            if (!$membership) {
                return response()->json(['message' => 'Credenciais inválidas'], 422);
            }
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->serializeUserForCompany($user, $company->id),
        ]);
    }

    public function me(Request $request)
    {
        $companyId = $this->currentCompanyId();

        return response()->json($this->serializeUserForCompany($request->user(), $companyId));
    }

    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return response()->json(['message' => 'Logged out']);
    }

    private function serializeUserForCompany(User $user, ?int $companyId): array
    {
        $payload = $user->toArray();
        $companyRole = $user->companyRole($companyId);

        $payload['global_role'] = $payload['role'] ?? null;
        $payload['role'] = $companyRole ?: ($payload['role'] ?? User::ROLE_CLIENT);

        $company = $this->currentCompany();
        $payload['company'] = $company ? [
            'id' => $company->id,
            'name' => $company->name,
            'slug' => $company->slug,
        ] : null;

        return $payload;
    }
}
