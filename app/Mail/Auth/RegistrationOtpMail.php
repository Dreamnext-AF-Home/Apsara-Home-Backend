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
        public string $email,
        public ?string $brandName = null
    ) {}

    public function build(): self
    {
        $subjectBrand = trim((string) ($this->brandName ?? 'AF Home'));

        return $this
            ->subject("Your {$subjectBrand} Verification Code")
            ->view('emails.auth.registration-otp')
            ->with([
                'otp' => $this->otp,
                'email' => $this->email,
                'brand_name' => $subjectBrand,
            ]);
    }
}
