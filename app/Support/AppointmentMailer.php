<?php

namespace App\Support;

use App\Mail\AppointmentCreatedMail;
use App\Mail\AppointmentStatusMail;
use App\Models\Appointment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AppointmentMailer
{
    public static function sendCreated(Appointment $appointment): void
    {
        $appointment->loadMissing(['client', 'staff', 'services']);
        $email = $appointment->client ? $appointment->client->email : null;

        if (!$email) {
            return;
        }

        try {
            Mail::to($email)->send(new AppointmentCreatedMail($appointment));
        } catch (\Throwable $exception) {
            Log::warning('Failed to send appointment created email.', [
                'appointment_id' => $appointment->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public static function sendStatus(Appointment $appointment): void
    {
        $appointment->loadMissing(['client', 'staff', 'services']);
        $email = $appointment->client ? $appointment->client->email : null;

        if (!$email) {
            return;
        }

        try {
            Mail::to($email)->send(new AppointmentStatusMail($appointment));
        } catch (\Throwable $exception) {
            Log::warning('Failed to send appointment status email.', [
                'appointment_id' => $appointment->id,
                'status' => $appointment->status,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
