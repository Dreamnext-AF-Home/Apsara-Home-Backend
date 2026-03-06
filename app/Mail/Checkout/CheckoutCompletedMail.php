<?php

namespace App\Mail\Checkout;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CheckoutCompletedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $payload)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('Purchase Order Confirmation - AF Home')
            ->view('emails.checkout.completed')
            ->with([
                'payload' => $this->payload,
            ]);
    }
}
