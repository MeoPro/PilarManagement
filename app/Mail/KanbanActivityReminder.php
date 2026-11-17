<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class KanbanActivityReminder extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $adminNames
     */
    public function __construct(
        public bool $ownerAlert,
        public array $adminNames,
        public string $notificationDate,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->ownerAlert
                ? 'Peringatan admin belum memperbarui Kanban'
                : 'Pengingat perubahan Kanban hari ini',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.kanban-activity-reminder',
            with: [
                'ownerAlert' => $this->ownerAlert,
                'adminNames' => $this->adminNames,
                'notificationDate' => $this->notificationDate,
            ],
        );
    }
}
