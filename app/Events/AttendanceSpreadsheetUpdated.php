<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AttendanceSpreadsheetUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $type,
        public int $userId,
        public ?string $date,
        public ?int $actorId = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('attendance.spreadsheet');
    }

    public function broadcastAs(): string
    {
        return 'spreadsheet.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'user_id' => $this->userId,
            'date' => $this->date,
            'actor_id' => $this->actorId,
            'at' => now()->toIso8601String(),
        ];
    }
}
