<?php

namespace App\Mail;

use App\Models\Appointment;
use App\Support\AppointmentMailData;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AppointmentCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $clientName;
    public $staffName;
    public $date;
    public $timeRange;
    public $services;
    public $total;
    public $statusLabel;

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
    }

    public function build()
    {
        return $this->subject('Agendamento realizado')
            ->view('emails.appointments.created');
    }
}
