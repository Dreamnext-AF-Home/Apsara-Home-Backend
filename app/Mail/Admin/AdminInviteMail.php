<?php

namespace App\Mail\Admin;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $email,
        public string $roleLabel,
        public string $setupUrl,
        public string $expiresAt,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('You have been invited to AF Home Admin')
            ->view('emails.admin.admin-invite');
    }
}
