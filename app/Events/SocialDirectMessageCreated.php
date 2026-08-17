<?php

namespace App\Events;

use App\Models\SocialDirectMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SocialDirectMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SocialDirectMessage $message,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('social.direct-thread.'.$this->message->social_direct_thread_id);
    }

    public function broadcastAs(): string
    {
        return 'social.direct.message.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->message->loadMissing([
            'user:id,first_name,last_name,avatar',
            'attachments',
            'reactions.user:id,first_name,last_name',
            'parent' => fn ($q) => $q->select(['id', 'user_id', 'message', 'deleted_at']),
            'parent.user:id,first_name,last_name',
        ]);

        return [
            'id' => $this->message->id,
            'social_direct_thread_id' => $this->message->social_direct_thread_id,
            'user_id' => $this->message->user_id,
            'parent_id' => $this->message->parent_id,
            'message' => $this->message->message,
            'edited_at' => $this->message->edited_at?->toIso8601String(),
            'user' => $this->message->user,
            'attachments' => $this->message->attachments,
            'reactions' => $this->message->reactions,
            'parent' => $this->message->parent,
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
