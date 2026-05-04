<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CartAdded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $customerId;
    public $product;
    public $cartItem;
    public $totalCartItems;

    /**
     * Create a new event instance.
     */
    public function __construct(int $customerId, $product, $cartItem, int $totalCartItems)
    {
        $this->customerId = $customerId;
        $this->product = $product;
        $this->cartItem = $cartItem;
        $this->totalCartItems = $totalCartItems;
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
        return 'cart.added';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'type' => 'cart_added',
            'product' => [
                'id' => $this->product->pd_id,
                'name' => $this->product->pd_name,
                'image' => $this->product->pd_image,
                'price' => $this->cartItem->crt_unit_price,
                'quantity' => $this->cartItem->crt_quantity,
            ],
            'cart_item' => $this->cartItem,
            'total_cart_items' => $this->totalCartItems,
            'message' => "{$this->product->pd_name} added to your cart",
            'timestamp' => now()->toISOString(),
        ];
    }
}
