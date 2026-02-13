<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StaffTimeOff;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class StaffTimeOffController extends Controller
{
    public function index(Request $request)
    {
        $staffId = $this->resolveStaffId($request);

        $query = StaffTimeOff::query()->where('staff_user_id', $staffId);

        if ($request->filled('from')) {
            $query->where('end_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('start_at', '<=', $request->input('to'));
        }

        return response()->json($query->orderByDesc('start_at')->get());
    }

    public function store(Request $request)
    {
        $staffId = $this->resolveStaffId($request);

        $data = $request->validate([
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $startAt = Carbon::parse($data['start_at']);
        $endAt = Carbon::parse($data['end_at']);

        if ($startAt->greaterThanOrEqualTo($endAt)) {
            return response()->json(['message' => 'start_at must be before end_at'], 422);
        }

        $timeOff = StaffTimeOff::create([
            'staff_user_id' => $staffId,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'reason' => $data['reason'] ?? null,
        ]);

        return response()->json($timeOff, 201);
    }

    public function destroy(Request $request, StaffTimeOff $timeOff)
    {
        $companyRole = $request->user()->companyRole($this->currentCompanyId());
        $user = $request->user();

        if ($companyRole === User::ROLE_STAFF && $timeOff->staff_user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $timeOff->delete();

        return response()->json(['message' => 'Time off deleted']);
    }

    private function resolveStaffId(Request $request): int
    {
        $companyId = $this->currentCompanyId();
        $user = $request->user();
        $companyRole = $user->companyRole($companyId);

        if ($companyRole === User::ROLE_STAFF) {
            return $user->id;
        }

        $staffId = $request->input('staff_id');

        if (!$staffId) {
            abort(422, 'staff_id is required for admin');
        }

        $isStaff = User::query()
            ->withCompanyRoles($companyId, [User::ROLE_STAFF, User::ROLE_ADMIN])
            ->whereKey($staffId)
            ->exists();

        if (!$isStaff) {
            abort(422, 'staff_id must belong to a staff user');
        }

        return (int) $staffId;
    }
}
