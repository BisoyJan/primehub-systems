<?php

namespace App\Events;

use App\Models\ItConcern;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever an IT concern changes (edit, status/priority update,
 * resolution, cancellation or deletion) so open ticket pages can refresh.
 */
class ItConcernUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  'updated'|'resolved'|'cancelled'|'deleted'  $action
     */
    public function __construct(
        public ItConcern $concern,
        public User $actor,
        public string $action = 'updated',
    ) {}

    /**
     * Per-ticket channel for anyone allowed to view the concern (owner, IT)
     * plus the IT-wide channel so the concerns list can refresh.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("it-concern.{$this->concern->id}"),
            new PrivateChannel('it-concerns'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'concern.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->concern->id,
            'action' => $this->action,
            'status' => $this->concern->status,
            'priority' => $this->concern->priority,
            'updated_by' => [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
            ],
            'at' => now()->toIso8601String(),
        ];
    }
}
