<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Carbon\Carbon;

class EmployeeAccessRevoked extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $employee;
    public string $department;
    public Carbon $effectiveDate;
    public string $revokedBy;

    /**
     * Create a new message instance.
     */
    public function __construct(User $employee, string $department, string $revokedBy)
    {
        $this->employee = $employee;
        $this->department = $department;
        $this->effectiveDate = Carbon::now();
        $this->revokedBy = $revokedBy;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Access Revoked – Action Required: ' . $this->employee->name,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.employee-access-revoked',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
