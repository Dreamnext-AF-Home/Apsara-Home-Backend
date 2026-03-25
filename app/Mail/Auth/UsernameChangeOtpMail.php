<?php

namespace App\Mail\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class UsernameChangeOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otp,
        public string $email
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Your AF Home Username Change Code')
            ->view('emails.auth.username-change-otp')
            ->with([
                'otp' => $this->otp,
                'email' => $this->email,
            ]);
    }
}
