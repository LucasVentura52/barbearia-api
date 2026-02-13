<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StaffWorkingHour;
use App\Models\User;
use Illuminate\Http\Request;

class StaffWorkingHourController extends Controller
{
    public function index(Request $request)
    {
        $staffId = $this->resolveStaffId($request);

        $query = StaffWorkingHour::query()->where('staff_user_id', $staffId);

        if ($request->filled('weekday')) {
            $query->where('weekday', (int) $request->input('weekday'));
        }

        return response()->json($query->orderBy('weekday')->orderBy('start_time')->get());
    }

    public function store(Request $request)
    {
        $staffId = $this->resolveStaffId($request);

        $data = $this->validatePayload($request);

        if ($this->overlapsExisting($staffId, $data['weekday'], $data['start_time'], $data['end_time'])) {
            return response()->json(['message' => 'Working hours overlap'], 422);
        }

        $workingHour = StaffWorkingHour::create([
            'staff_user_id' => $staffId,
            'weekday' => $data['weekday'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
        ]);

        return response()->json($workingHour, 201);
    }

    public function update(Request $request, StaffWorkingHour $workingHour)
    {
        $this->ensureCanEdit($request, $workingHour);

        $data = $this->validatePayload($request);

        $staffId = $workingHour->staff_user_id;

        if ($this->overlapsExisting($staffId, $data['weekday'], $data['start_time'], $data['end_time'], $workingHour->id)) {
            return response()->json(['message' => 'Working hours overlap'], 422);
        }

        $workingHour->update([
            'weekday' => $data['weekday'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
        ]);

        return response()->json($workingHour);
    }

    public function destroy(Request $request, StaffWorkingHour $workingHour)
    {
        $this->ensureCanEdit($request, $workingHour);

        $workingHour->delete();

        return response()->json(['message' => 'Working hour deleted']);
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

    private function ensureCanEdit(Request $request, StaffWorkingHour $workingHour): void
    {
        $companyRole = $request->user()->companyRole($this->currentCompanyId());
        $user = $request->user();

        if ($companyRole === User::ROLE_STAFF && $workingHour->staff_user_id !== $user->id) {
            abort(403, 'Forbidden');
        }
    }

    private function validatePayload(Request $request): array
    {
        $data = $request->validate([
            'weekday' => ['required', 'integer', 'min:0', 'max:6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
        ]);

        if ($data['start_time'] >= $data['end_time']) {
            abort(422, 'start_time must be before end_time');
        }

        return $data;
    }

    private function overlapsExisting(int $staffId, int $weekday, string $start, string $end, ?int $ignoreId = null): bool
    {
        $query = StaffWorkingHour::query()
            ->where('staff_user_id', $staffId)
            ->where('weekday', $weekday)
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start);

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists();
    }
}
