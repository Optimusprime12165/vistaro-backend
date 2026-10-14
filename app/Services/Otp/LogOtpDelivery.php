<?php

namespace App\Services\Otp;

use App\Contracts\OtpDeliveryInterface;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * DEVELOPMENT-ONLY OTP "delivery": writes the OTP to the application log so developers can log in
 * without an SMS gateway. It refuses to run outside local/testing, so a production deployment that
 * forgot to configure SMS fails loudly instead of leaking OTPs into logs.
 */
final class LogOtpDelivery implements OtpDeliveryInterface
{
    public function send(string $mobile, string $otp, string $purpose): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('No OTP delivery provider is configured for this environment. Set OTP_DELIVERY to a real provider.');
        }

        Log::info("[DEV ONLY] OTP for {$mobile} ({$purpose}): {$otp}");
    }
}
