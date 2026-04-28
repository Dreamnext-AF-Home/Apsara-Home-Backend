<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CustomerAccountDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly int $customerId) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("customer.{$this->customerId}");
    }

    public function broadcastAs(): string
    {
        return 'account.deleted';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => 'Your account has been deleted by an administrator.',
        ];
    }
}
