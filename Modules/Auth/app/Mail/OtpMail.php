<?php

namespace Modules\Auth\Mail;

use Modules\UserManagement\Models\User; 
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;


class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $otp
    ) {}

    public function build()
    {
        return $this->subject('Your Lendsystem Login OTP')
            ->view('auth::Emails.LoginOtp');
    }
}
