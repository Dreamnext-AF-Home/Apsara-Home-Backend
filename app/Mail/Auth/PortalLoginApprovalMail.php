<?php

namespace App\Mail\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PortalLoginApprovalMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $portalLabel,
        public string $email,
        public string $device,
        public string $platform,
        public string $browser,
        public string $location,
        public string $ipAddress,
        public string $approveUrl,
        public string $denyUrl,
        public string $expiresInMinutes = '10',
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject(sprintf('%s New Device Sign-in Attempt', $this->portalLabel))
            ->view('emails.auth.portal-login-approval')
            ->with([
                'portalLabel' => $this->portalLabel,
                'email' => $this->email,
                'device' => $this->device,
                'platform' => $this->platform,
                'browser' => $this->browser,
                'location' => $this->location,
                'ipAddress' => $this->ipAddress,
                'approveUrl' => $this->approveUrl,
                'denyUrl' => $this->denyUrl,
                'expiresInMinutes' => $this->expiresInMinutes,
            ]);
    }
}
