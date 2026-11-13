<?php

namespace Modules\BLLeadCreation\Mail;

use Modules\BLLeadCreation\Models\BLLeadCreation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public BLLeadCreation $BLLeadCreation,
        public string $otp
    ) {}

    public function build()
    {
        return $this
            ->subject('Your Lendsystem Login OTP')
            ->view('emails.OtpMail');
    }
}