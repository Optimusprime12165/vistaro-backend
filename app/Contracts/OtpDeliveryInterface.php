<?php

namespace App\Contracts;

/** Transport only. OTP generation, hashing, limits and verification live in OtpService. */
interface OtpDeliveryInterface
{
    /**
     * @param string $mobile  normalised mobile number
     * @param string $otp     plain OTP; implementations must never log it outside local/testing
     * @param string $purpose e.g. "login"
     */
    public function send(string $mobile, string $otp, string $purpose): void;
}
