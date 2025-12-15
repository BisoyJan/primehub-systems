<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeaveRequestTLStatusUpdated extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $leaveRequest;
    public $user;
    public $teamLead;
    public $isApproved;

    /**
     * Create a new message instance.
     */
    public function __construct(LeaveRequest $leaveRequest, User $user, User $teamLead, bool $isApproved)
    {
        $this->leaveRequest = $leaveRequest;
        $this->user = $user;
        $this->teamLead = $teamLead;
        $this->isApproved = $isApproved;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $status = $this->isApproved ? 'Approved' : 'Rejected';
        return new Envelope(
            subject: "Leave Request {$status} by Team Lead",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.leave-request-tl-status',
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
