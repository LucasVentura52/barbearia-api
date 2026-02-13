<?php

namespace App\Mail;

use App\Models\Appointment;
use App\Support\AppointmentMailData;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AppointmentStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public $clientName;
    public $staffName;
    public $date;
    public $timeRange;
    public $services;
    public $total;
    public $statusLabel;
    public $cancelReason;

    public function __construct(Appointment $appointment)
    {
        $data = AppointmentMailData::build($appointment);

        $this->clientName = $data['clientName'];
        $this->staffName = $data['staffName'];
        $this->date = $data['date'];
        $this->timeRange = $data['timeRange'];
        $this->services = $data['services'];
        $this->total = $data['total'];
        $this->statusLabel = $data['statusLabel'];
        $this->cancelReason = $data['cancelReason'];
    }

    public function build()
    {
        return $this->subject('Status do agendamento atualizado')
            ->view('emails.appointments.status');
    }
}
