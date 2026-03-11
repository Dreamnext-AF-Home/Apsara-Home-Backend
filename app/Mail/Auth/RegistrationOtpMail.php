<?php

namespace App\Mail\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RegistrationOtpMail extends Mailable 
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otp,
        public string $email
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Your AF Home Verification Code')
            ->view('emails.auth.registration-otp')
            ->with([
                'otp' => $this->otp,
                'email' => $this->email,
            ]);
    }
}