<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\StaffTimeOff;
use App\Models\StaffWorkingHour;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $this->currentCompanyId();

        $data = $request->validate([
            'staff_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer', 'distinct', 'exists:services,id'],
            'step_minutes' => ['nullable', 'integer', 'min:5', 'max:60'],
        ]);

        $staff = User::query()
            ->withCompanyRoles($companyId, [User::ROLE_STAFF, User::ROLE_ADMIN])
            ->where('id', $data['staff_id'])
            ->whereHas('staffProfile', function ($query) {
                $query->where('active', true);
            })
            ->first();

        if (!$staff) {
            return response()->json(['message' => 'Staff not available'], 422);
        }

        $duration = $data['duration_minutes'] ?? null;
        $serviceIds = !empty($data['service_ids'])
            ? array_values(array_unique($data['service_ids']))
            : [];

        $services = collect();
        if (!empty($serviceIds)) {
            $services = Service::query()
                ->whereIn('id', $serviceIds)
                ->where('active', true)
                ->get();

            if ($services->count() !== count($serviceIds)) {
                return response()->json(['message' => 'Invalid services'], 422);
            }

            $staffServiceCount = $staff->services()
                ->wherePivot('company_id', $companyId)
                ->whereIn('services.id', $serviceIds)
                ->count();

            if ($staffServiceCount !== count($serviceIds)) {
                return response()->json(['message' => 'Staff does not provide selected services'], 422);
            }

            if (!$duration) {
                $duration = (int) $services->sum('duration_minutes');
            }
        }

        if (!$duration) {
            $duration = 30;
        }

        $step = (int) ($data['step_minutes'] ?? 15);

        $date = Carbon::parse($data['date'])->startOfDay();
        $weekday = (int) $date->dayOfWeek;

        $workingHours = StaffWorkingHour::query()
            ->where('staff_user_id', $staff->id)
            ->where('weekday', $weekday)
            ->get();

        if ($workingHours->isEmpty()) {
            return response()->json([
                'staff_id' => $staff->id,
                'date' => $date->toDateString(),
                'duration_minutes' => $duration,
                'step_minutes' => $step,
                'slots' => [],
            ]);
        }

        $dayStart = $date->copy();
        $dayEnd = $date->copy()->addDay();

        $busy = [];

        $appointments = Appointment::query()
            ->where('staff_user_id', $staff->id)
            ->where('status', 'scheduled')
            ->where('start_at', '<', $dayEnd)
            ->where('end_at', '>', $dayStart)
            ->get(['start_at', 'end_at']);

        foreach ($appointments as $appointment) {
            $busy[] = [$appointment->start_at, $appointment->end_at];
        }

        $timeOff = StaffTimeOff::query()
            ->where('staff_user_id', $staff->id)
            ->where('start_at', '<', $dayEnd)
            ->where('end_at', '>', $dayStart)
            ->get(['start_at', 'end_at']);

        foreach ($timeOff as $off) {
            $busy[] = [$off->start_at, $off->end_at];
        }

        $slots = [];

        foreach ($workingHours as $range) {
            $rangeStart = $date->copy()->setTimeFromTimeString($range->start_time);
            $rangeEnd = $date->copy()->setTimeFromTimeString($range->end_time);

            $cursor = $rangeStart->copy();

            while ($cursor->copy()->addMinutes($duration)->lessThanOrEqualTo($rangeEnd)) {
                $slotStart = $cursor->copy();
                $slotEnd = $slotStart->copy()->addMinutes($duration);

                if (!$this->overlapsBusy($slotStart, $slotEnd, $busy)) {
                    $slots[] = $slotStart->toIso8601String();
                }

                $cursor->addMinutes($step);
            }
        }

        return response()->json([
            'staff_id' => $staff->id,
            'date' => $date->toDateString(),
            'duration_minutes' => $duration,
            'step_minutes' => $step,
            'slots' => $slots,
        ]);
    }

    private function overlapsBusy(Carbon $startAt, Carbon $endAt, array $busy): bool
    {
        foreach ($busy as $range) {
            [$busyStart, $busyEnd] = $range;
            if ($startAt->lt($busyEnd) && $endAt->gt($busyStart)) {
                return true;
            }
        }

        return false;
    }
}
