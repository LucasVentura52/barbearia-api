<?php

namespace App\Support;

use App\Models\Appointment;
use Carbon\Carbon;

class AppointmentMailData
{
    public static function build(Appointment $appointment): array
    {
        $appointment->loadMissing(['client', 'staff', 'services']);

        $timezone = config('app.timezone') ?: 'UTC';
        $start = $appointment->start_at instanceof Carbon
            ? $appointment->start_at->copy()
            : Carbon::parse($appointment->start_at);
        $end = $appointment->end_at instanceof Carbon
            ? $appointment->end_at->copy()
            : Carbon::parse($appointment->end_at);

        $start->timezone($timezone);
        $end->timezone($timezone);

        $clientName = $appointment->client ? $appointment->client->name : null;
        $staffName = $appointment->staff ? $appointment->staff->name : null;
        $services = $appointment->services
            ? $appointment->services->pluck('name')->filter()->values()->all()
            : [];

        return [
            'clientName' => $clientName ?: 'Cliente',
            'staffName' => $staffName ?: 'Barbeiro',
            'date' => $start->format('d/m/Y'),
            'timeRange' => $start->format('H:i') . ' - ' . $end->format('H:i'),
            'services' => $services,
            'total' => number_format((float) $appointment->total_price, 2, ',', '.'),
            'statusLabel' => self::statusLabel($appointment->status),
            'cancelReason' => $appointment->cancel_reason,
        ];
    }

    public static function statusLabel(?string $status): string
    {
        switch ($status) {
            case 'scheduled':
                return 'Agendado';
            case 'done':
                return 'Finalizado';
            case 'no_show':
                return 'Não compareceu';
            case 'canceled':
                return 'Cancelado';
            default:
                return 'Agendado';
        }
    }
}
