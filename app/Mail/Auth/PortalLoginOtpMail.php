<?php

namespace App\Mail\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PortalLoginOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otp,
        public string $email,
        public string $portalLabel,
        public string $expiresInMinutes = '10',
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject(sprintf('%s Login Verification Code', $this->portalLabel))
            ->view('emails.auth.portal-login-otp')
            ->with([
                'otp' => $this->otp,
                'email' => $this->email,
                'portalLabel' => $this->portalLabel,
                'expiresInMinutes' => $this->expiresInMinutes,
            ]);
    }
}
