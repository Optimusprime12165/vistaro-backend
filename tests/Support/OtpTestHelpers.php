<?php

namespace Tests\Support;

use App\Contracts\OtpDeliveryInterface;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Real OTP logic with a capturing transport.
 *  - OTP_FIXED_ENABLED is forced OFF (config override) so the testing-only fixed code can never mask a bug.
 *  - The HTTP burst throttles (throttle:otp-request / otp-verify) are switched off: the limits under test (cooldown,
 *    daily issuance, verify attempts) are enforced in the database by OtpService, and the burst throttle would
 *    otherwise answer 429 first and hide them.
 */
trait OtpTestHelpers
{
    protected CapturingOtpDelivery $otp;

    protected function useFakeOtpDelivery(): void
    {
        config(['vistaro.otp.fixed_enabled' => false]);
        $this->otp = new CapturingOtpDelivery();
        $this->app->instance(OtpDeliveryInterface::class, $this->otp);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /** A 6-digit code guaranteed to differ from $code. */
    protected function otherCode(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }
}
