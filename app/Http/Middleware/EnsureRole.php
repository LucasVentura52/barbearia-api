<?php

namespace App\Http\Middleware;

use App\Models\CompanyUser;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (empty($roles)) {
            return $next($request);
        }

        if ($user->role === 'super_admin') {
            if (in_array('super_admin', $roles, true) || in_array('admin', $roles, true)) {
                return $next($request);
            }
        }

        $role = $user->role;
        $companyId = app(TenantContext::class)->id();
        if ($companyId) {
            $companyRole = CompanyUser::query()
                ->where('company_id', $companyId)
                ->where('user_id', $user->id)
                ->where('active', true)
                ->value('role');

            if (!$companyRole) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $role = $companyRole;
        }

        if (!in_array($role, $roles, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
