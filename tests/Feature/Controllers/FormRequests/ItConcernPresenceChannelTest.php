<?php

namespace Tests\Feature\Controllers\FormRequests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ItConcernPresenceChannelTest extends TestCase
{
    use RefreshDatabase;

    private function presenceChannelCallback(): callable
    {
        $callback = Broadcast::getChannels()->get('it-concerns.presence');

        $this->assertIsCallable($callback, 'it-concerns.presence channel is not registered');

        return $callback;
    }

    #[Test]
    public function it_returns_member_payload_for_users_who_can_resolve_concerns(): void
    {
        $user = User::factory()->create(['role' => 'IT', 'is_approved' => true]);

        $member = $this->presenceChannelCallback()($user);

        $this->assertSame([
            'id' => $user->id,
            'name' => $user->name,
            'avatar_url' => $user->avatar_url,
        ], $member);
    }

    #[Test]
    public function it_denies_users_without_resolve_permission(): void
    {
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $utility = User::factory()->create(['role' => 'Utility', 'is_approved' => true]);

        $callback = $this->presenceChannelCallback();

        $this->assertNull($callback($agent));
        $this->assertNull($callback($utility));
    }
}
