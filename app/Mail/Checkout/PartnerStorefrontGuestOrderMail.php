<?php

namespace App\Mail\Checkout;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PartnerStorefrontGuestOrderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $payload)
    {
    }

    public function build(): self
    {
        $storefrontName = trim((string) ($this->payload['storefront_display_name'] ?? 'Partner Storefront'));

        return $this
            ->subject("New Guest Order from {$storefrontName}")
            ->view('emails.checkout.partner-storefront-guest-order')
            ->with([
                'payload' => $this->payload,
            ]);
    }
}
