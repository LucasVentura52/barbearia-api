<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Support\AppointmentMailer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffAppointmentController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $this->currentCompanyId();
        $user = $request->user();
        $companyRole = $user->companyRole($companyId);
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $staffId = $data['staff_id'] ?? null;

        if ($companyRole === User::ROLE_STAFF) {
            $staffId = $user->id;
        } elseif ($staffId) {
            $isStaffInCompany = User::query()
                ->withCompanyRoles($companyId, [User::ROLE_STAFF, User::ROLE_ADMIN])
                ->whereKey($staffId)
                ->exists();

            if (!$isStaffInCompany) {
                return response()->json(['message' => 'staff_id must belong to a staff user'], 422);
            }
        }

        if (!empty($data['date'])) {
            $date = Carbon::parse($data['date'])->startOfDay();
            $dayStart = $date->copy();
            $dayEnd = $date->copy()->addDay();
        } else {
            if (empty($data['from']) || empty($data['to'])) {
                return response()->json(['message' => 'date or from/to is required'], 422);
            }
            $dayStart = Carbon::parse($data['from'])->startOfDay();
            $dayEnd = Carbon::parse($data['to'])->addDay()->startOfDay();
        }

        $appointments = Appointment::query()
            ->when($staffId, function ($query) use ($staffId) {
                $query->where('staff_user_id', $staffId);
            })
            ->where('start_at', '>=', $dayStart)
            ->where('start_at', '<', $dayEnd)
            ->with([
                'client:id,name,phone,avatar_url',
                'staff:id,name,avatar_url',
                'services:id,name,duration_minutes,price,photo_url',
            ])
            ->orderBy('start_at')
            ->get();

        return response()->json($appointments);
    }

    public function cancel(Request $request, Appointment $appointment)
    {
        $companyRole = $request->user()->companyRole($this->currentCompanyId());
        $user = $request->user();

        if ($companyRole === User::ROLE_STAFF && $appointment->staff_user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($appointment->status !== 'scheduled') {
            return response()->json(['message' => 'Appointment cannot be canceled'], 422);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $appointment->status = 'canceled';
        $appointment->cancel_reason = $data['reason'];
        $appointment->canceled_by = 'staff';
        $appointment->save();

        AppointmentMailer::sendStatus($appointment);

        return response()->json(['message' => 'Appointment canceled']);
    }

    public function updateStatus(Request $request, Appointment $appointment)
    {
        $companyRole = $request->user()->companyRole($this->currentCompanyId());
        $user = $request->user();

        if ($companyRole === User::ROLE_STAFF && $appointment->staff_user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($appointment->status !== 'scheduled') {
            return response()->json(['message' => 'Appointment status cannot be changed'], 422);
        }

        $data = $request->validate([
            'status' => ['required', 'in:done,no_show'],
        ]);

        $appointment->status = $data['status'];
        $appointment->save();

        AppointmentMailer::sendStatus($appointment);

        return response()->json($appointment);
    }

    public function update(Request $request, Appointment $appointment)
    {
        $companyId = $this->currentCompanyId();
        $companyRole = $request->user()->companyRole($companyId);
        $user = $request->user();

        if ($companyRole === User::ROLE_STAFF && $appointment->staff_user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($appointment->status !== 'scheduled') {
            return response()->json(['message' => 'Appointment cannot be edited'], 422);
        }

        $data = $request->validate([
            'start_at' => ['required', 'date'],
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'distinct', 'exists:services,id'],
        ]);

        $staff = User::query()
            ->withCompanyRoles($companyId, [User::ROLE_STAFF, User::ROLE_ADMIN])
            ->whereKey($appointment->staff_user_id)
            ->first();

        if (!$staff) {
            return response()->json(['message' => 'Staff not found'], 404);
        }

        $serviceIds = array_values(array_unique($data['service_ids']));

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

        $startAt = Carbon::parse($data['start_at']);
        if ($startAt->isPast()) {
            return response()->json(['message' => 'Start time must be in the future'], 422);
        }

        $duration = (int) $services->sum('duration_minutes');
        $totalPrice = $services->sum('price');
        $endAt = $startAt->copy()->addMinutes($duration);

        if (!$this->fitsWorkingHours($staff->id, $startAt, $endAt)) {
            return response()->json(['message' => 'Outside working hours'], 422);
        }

        if ($this->isDuringTimeOff($staff->id, $startAt, $endAt)) {
            return response()->json(['message' => 'Staff is unavailable'], 422);
        }

        $updated = DB::transaction(function () use ($appointment, $staff, $startAt, $endAt, $services, $totalPrice) {
            User::where('id', $staff->id)->lockForUpdate()->first();

            $conflict = Appointment::query()
                ->where('staff_user_id', $staff->id)
                ->where('id', '!=', $appointment->id)
                ->where('status', 'scheduled')
                ->where(function ($query) use ($startAt, $endAt) {
                    $query->where('start_at', '<', $endAt)
                        ->where('end_at', '>', $startAt);
                })
                ->lockForUpdate()
                ->exists();

            if ($conflict) {
                return null;
            }

            $appointment->update([
                'start_at' => $startAt,
                'end_at' => $endAt,
                'total_price' => $totalPrice,
            ]);

            $appointment->appointmentServices()->delete();

            $appointment->appointmentServices()->createMany(
                $services->map(function (Service $service) {
                    return [
                        'service_id' => $service->id,
                        'price_snapshot' => $service->price,
                        'duration_snapshot' => $service->duration_minutes,
                    ];
                })->all()
            );

            return $appointment;
        });

        if (!$updated) {
            return response()->json(['message' => 'Time slot already booked'], 409);
        }

        $appointment->load([
            'client:id,name,phone,avatar_url',
            'services:id,name,duration_minutes,price,photo_url',
        ]);

        return response()->json($appointment);
    }

    public function destroy(Request $request, Appointment $appointment)
    {
        $companyRole = $request->user()->companyRole($this->currentCompanyId());
        $user = $request->user();

        if ($companyRole === User::ROLE_STAFF && $appointment->staff_user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $appointment->delete();

        return response()->json(['message' => 'Appointment deleted']);
    }

    private function fitsWorkingHours(int $staffId, Carbon $startAt, Carbon $endAt): bool
    {
        $weekday = (int) $startAt->dayOfWeek;

        $hours = \App\Models\StaffWorkingHour::query()
            ->where('staff_user_id', $staffId)
            ->where('weekday', $weekday)
            ->get();

        if ($hours->isEmpty()) {
            return false;
        }

        foreach ($hours as $range) {
            $rangeStart = $startAt->copy()->setTimeFromTimeString($range->start_time);
            $rangeEnd = $startAt->copy()->setTimeFromTimeString($range->end_time);

            if ($startAt->greaterThanOrEqualTo($rangeStart) && $endAt->lessThanOrEqualTo($rangeEnd)) {
                return true;
            }
        }

        return false;
    }

    private function isDuringTimeOff(int $staffId, Carbon $startAt, Carbon $endAt): bool
    {
        return \App\Models\StaffTimeOff::query()
            ->where('staff_user_id', $staffId)
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->exists();
    }
}
