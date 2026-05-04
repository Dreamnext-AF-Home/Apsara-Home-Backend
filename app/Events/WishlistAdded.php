<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WishlistAdded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $customerId;
    public $product;
    public $wishlistItem;
    public $action;

    /**
     * Create a new event instance.
     */
    public function __construct(int $customerId, $product, $wishlistItem, string $action = 'added')
    {
        $this->customerId = $customerId;
        $this->product = $product;
        $this->wishlistItem = $wishlistItem;
        $this->action = $action; // 'added' or 'removed'
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('customer.' . $this->customerId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'wishlist.' . $this->action;
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        $message = $this->action === 'added' 
            ? "{$this->product->pd_name} added to your wishlist"
            : "{$this->product->pd_name} removed from your wishlist";

        return [
            'type' => 'wishlist_' . $this->action,
            'action' => $this->action,
            'product' => [
                'id' => $this->product->pd_id,
                'name' => $this->product->pd_name,
                'image' => $this->product->pd_image,
                'price' => $this->product->pd_price_srp,
            ],
            'wishlist_item' => $this->wishlistItem,
            'message' => $message,
            'timestamp' => now()->toISOString(),
        ];
    }
}
