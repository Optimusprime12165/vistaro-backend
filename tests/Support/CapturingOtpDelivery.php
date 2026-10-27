<?php

namespace Tests\Support;

use App\Contracts\OtpDeliveryInterface;

/**
 * Test double for the OTP transport: records every delivered code so tests never depend on logs or on the
 * fixed-code testing switch. `to` is the normalised mobile (+91...) or the lower-cased email.
 */
final class CapturingOtpDelivery implements OtpDeliveryInterface
{
    /** @var list<array{to:string,otp:string,purpose:string}> */
    public array $sent = [];

    public function send(string $mobile, string $otp, string $purpose): void
    {
        $this->sent[] = ['to' => $mobile, 'otp' => $otp, 'purpose' => $purpose];
    }

    /** @return list<array{to:string,otp:string,purpose:string}> */
    public function matching(?string $to = null, ?string $purpose = null): array
    {
        return array_values(array_filter(
            $this->sent,
            fn (array $m) => ($to === null || $m['to'] === $to) && ($purpose === null || $m['purpose'] === $purpose),
        ));
    }

    public function count(?string $to = null, ?string $purpose = null): int
    {
        return count($this->matching($to, $purpose));
    }

    /** The most recently delivered code (optionally for one recipient / purpose). Fails loudly when nothing was sent. */
    public function lastCode(?string $to = null, ?string $purpose = null): string
    {
        $m = $this->matching($to, $purpose);
        if ($m === []) {
            throw new \RuntimeException('No OTP was delivered' . ($to ? " to {$to}" : '') . ($purpose ? " for {$purpose}" : '') . '.');
        }

        return end($m)['otp'];
    }
}
