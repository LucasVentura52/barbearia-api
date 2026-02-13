<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $this->currentCompanyId();
        $user = $request->user();

        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $date = isset($data['date'])
            ? Carbon::parse($data['date'])
            : Carbon::now();

        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $dayStart->copy()->addDay();

        $staffId = $this->resolveStaffId($user, $companyId, $data['staff_id'] ?? null);

        $appointmentsQuery = Appointment::query()
            ->where('start_at', '>=', $dayStart)
            ->where('start_at', '<', $dayEnd);

        if ($staffId) {
            $appointmentsQuery->where('staff_user_id', $staffId);
        }

        $appointmentsByStatus = (clone $appointmentsQuery)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $totalAppointments = (clone $appointmentsQuery)->count();
        $totalRevenue = (clone $appointmentsQuery)
            ->whereIn('status', ['scheduled', 'done'])
            ->sum('total_price');

        $nextAppointments = Appointment::query()
            ->where('status', 'scheduled')
            ->where('start_at', '>=', Carbon::now())
            ->when($staffId, function ($query) use ($staffId) {
                $query->where('staff_user_id', $staffId);
            })
            ->orderBy('start_at')
            ->limit(5)
            ->with([
                'client:id,name,phone,avatar_url',
                'staff:id,name,avatar_url',
                'services:id,name,duration_minutes,price',
            ])
            ->get();

        $servicesTopQuery = DB::table('appointment_services')
            ->join('appointments', 'appointment_services.appointment_id', '=', 'appointments.id')
            ->join('services', 'appointment_services.service_id', '=', 'services.id')
            ->where('appointments.company_id', $companyId)
            ->where('appointments.start_at', '>=', $dayStart)
            ->where('appointments.start_at', '<', $dayEnd)
            ->whereIn('appointments.status', ['scheduled', 'done']);

        if ($staffId) {
            $servicesTopQuery->where('appointments.staff_user_id', $staffId);
        }

        $topServices = $servicesTopQuery
            ->selectRaw('services.id, services.name, count(*) as total')
            ->groupBy('services.id', 'services.name')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        return response()->json([
            'date' => $dayStart->toDateString(),
            'staff_id' => $staffId,
            'totals' => [
                'appointments_total' => $totalAppointments,
                'appointments_by_status' => $appointmentsByStatus,
                'revenue_total' => (float) $totalRevenue,
            ],
            'next_appointments' => $nextAppointments,
            'top_services' => $topServices,
        ]);
    }

    private function resolveStaffId(User $user, int $companyId, ?int $staffId): ?int
    {
        if ($user->companyRole($companyId) === User::ROLE_STAFF) {
            return $user->id;
        }

        if ($staffId) {
            $isStaffInCompany = User::query()
                ->withCompanyRoles($companyId, [User::ROLE_STAFF, User::ROLE_ADMIN])
                ->whereKey($staffId)
                ->exists();

            if (!$isStaffInCompany) {
                abort(422, 'staff_id must belong to a staff user');
            }

            return $staffId;
        }

        return null;
    }
}
