<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class KanbanAttendanceSummary extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $adminName,
        public string $attendanceDate,
        public float $attendanceCredit,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Rekap kehadiran Kanban hari ini',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.kanban-attendance-summary',
            with: [
                'adminName' => $this->adminName,
                'attendanceDate' => $this->attendanceDate,
                'attendanceCredit' => $this->attendanceCredit,
            ],
        );
    }
}
