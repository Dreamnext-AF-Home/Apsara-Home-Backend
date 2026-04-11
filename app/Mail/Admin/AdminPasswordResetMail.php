<?php

namespace App\Mail\Admin;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $email,
        public string $resetUrl,
        public string $expiresAt,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('Reset your AF Home Admin Portal password')
            ->view('emails.admin.admin-password-reset');
    }
}
