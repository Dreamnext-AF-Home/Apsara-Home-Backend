<?php

namespace App\Mail\Supplier;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SupplierPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $supplierName,
        public string $resetUrl,
        public string $expiresAt,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('Reset your AF Home Supplier Portal password')
            ->view('emails.supplier.supplier-password-reset');
    }
}
