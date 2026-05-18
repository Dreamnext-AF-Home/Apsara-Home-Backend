<?php

namespace App\Mail\Admin;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmailBlastMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public $subject,
        public $body,
        public $brandName = 'AF Home',
        public $bannerImageBase64 = null,
        public $attachments = []
    ) {}

    public function build(): self
    {
        $mailable = $this
            ->from(config('mail.from.address'), 'AF Home - Affiliate')
            ->subject($this->subject)
            ->view('emails.admin.email-blast')
            ->with([
                'body' => (string) $this->body,
                'banner_image_base64' => $this->bannerImageBase64,
                'brand_name' => $this->brandName,
            ]);

        foreach ($this->attachments as $attachment) {
            if (is_string($attachment) && file_exists($attachment)) {
                $mailable->attach($attachment);
            }
        }

        return $mailable;
    }
}
