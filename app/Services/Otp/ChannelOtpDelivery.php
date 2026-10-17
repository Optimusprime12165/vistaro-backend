<?php

namespace App\Services\Otp;

use App\Contracts\OtpDeliveryInterface;

/** Routes an OTP to the right transport: an identifier containing "@" is an email, anything else is a mobile number. */
final class ChannelOtpDelivery implements OtpDeliveryInterface
{
    public function __construct(private readonly OtpDeliveryInterface $sms, private readonly OtpDeliveryInterface $email)
    {
    }

    public function send(string $mobile, string $otp, string $purpose): void
    {
        ($this->isEmail($mobile) ? $this->email : $this->sms)->send($mobile, $otp, $purpose);
    }

    private function isEmail(string $id): bool
    {
        return str_contains($id, '@');
    }
}
