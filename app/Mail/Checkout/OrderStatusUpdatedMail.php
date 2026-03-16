<?php

namespace App\Mail\Checkout;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderStatusUpdatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $payload)
    {
    }

    public function build(): self
    {
        return $this
            ->subject(($this->payload['title'] ?? 'Order Update') . ' - AF Home')
            ->view('emails.checkout.status-updated')
            ->with([
                'payload' => $this->payload,
            ]);
    }
}
