<?php

namespace App\Events;

use App\Models\ItConcern;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ItConcernCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ItConcern $concern,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('it-concerns');
    }

    public function broadcastAs(): string
    {
        return 'concern.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->concern->id,
            'priority' => $this->concern->priority,
            'category' => $this->concern->category,
            'station_number' => $this->concern->station_number,
            'site_name' => $this->concern->site?->name,
            'reporter_name' => $this->concern->user?->name,
            'status' => $this->concern->status,
            'link' => route('it-concerns.show', $this->concern->id),
            'at' => now()->toIso8601String(),
        ];
    }
}
