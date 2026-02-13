<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\StaffTimeOff;
use App\Models\StaffWorkingHour;
use App\Models\User;
use App\Support\AppointmentMailer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $appointments = Appointment::query()
            ->where('client_user_id', $user->id)
            ->with([
                'staff:id,name,avatar_url',
                'services:id,name,duration_minutes,price,photo_url',
            ])
            ->orderByDesc('start_at')
            ->get();

        return response()->json($appointments);
    }

    public function store(Request $request)
    {
        $companyId = $this->currentCompanyId();
        $user = $request->user();

        $data = $request->validate([
            'staff_id' => ['required', 'integer', 'exists:users,id'],
            'start_at' => ['required', 'date'],
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'distinct', 'exists:services,id'],
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

        $appointment = DB::transaction(function () use ($companyId, $staff, $user, $startAt, $endAt, $services, $totalPrice) {
            User::where('id', $staff->id)->lockForUpdate()->first();

            $conflict = Appointment::query()
                ->where('staff_user_id', $staff->id)
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

            $appointment = Appointment::create([
                'company_id' => $companyId,
                'client_user_id' => $user->id,
                'staff_user_id' => $staff->id,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => 'scheduled',
                'total_price' => $totalPrice,
            ]);

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

        if (!$appointment) {
            return response()->json(['message' => 'Time slot already booked'], 409);
        }

        $appointment->load([
            'client:id,name,email',
            'staff:id,name,avatar_url',
            'services:id,name,duration_minutes,price,photo_url',
        ]);

        AppointmentMailer::sendCreated($appointment);

        return response()->json($appointment, 201);
    }

    public function cancel(Request $request, Appointment $appointment)
    {
        $user = $request->user();

        if ($appointment->client_user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($appointment->status !== 'scheduled') {
            return response()->json(['message' => 'Appointment cannot be canceled'], 422);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $appointment->status = 'canceled';
        $appointment->cancel_reason = $data['reason'] ?? null;
        $appointment->canceled_by = 'client';
        $appointment->save();

        return response()->json(['message' => 'Appointment canceled']);
    }

    private function fitsWorkingHours(int $staffId, Carbon $startAt, Carbon $endAt): bool
    {
        $weekday = (int) $startAt->dayOfWeek;

        $hours = StaffWorkingHour::query()
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
        return StaffTimeOff::query()
            ->where('staff_user_id', $staffId)
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->exists();
    }
}
