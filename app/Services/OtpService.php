<?php

namespace App\Services;

use App\Contracts\OtpDeliveryInterface;
use App\Exceptions\ApiException;
use App\Exceptions\TooManyAttemptsException;
use App\Models\OtpThrottle;
use App\Models\OtpVerification;
use App\Support\MobileNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OTP generation, storage, limits and verification. Transport (SMS/log) is OtpDeliveryInterface.
 *
 * Business rules (config/vistaro.php => otp):
 *  - max 3 issuances per normalised mobile per 24h window (the first OTP counts as 1; resend counts too)
 *  - cooldown after issuance #1 = 30 s, after #2 = 60 s; a 4th issuance in the window is refused
 *  - the counter lives in otp_throttles (one row per mobile+purpose), NOT on otp rows, so creating new OTP
 *    rows can never reset it; the window starts at the first issuance and only a fully elapsed window resets it
 *  - all counter changes happen under a row lock inside one transaction => safe across concurrent requests
 *    and multiple Lambda instances (shared database)
 *  - OTPs expire, are single-use, are stored only as an HMAC (never plain), and a newer OTP invalidates older ones
 *  - wrong guesses are counted per OTP; at the limit the OTP is burned and the caller gets HTTP 429
 */
final class OtpService
{
    /** Laravel re-runs a transaction that the database aborted as a deadlock / lock timeout. */
    private const DEADLOCK_RETRIES = 5;

    public function __construct(private readonly OtpDeliveryInterface $delivery)
    {
    }

    /**
     * Issue (or re-issue) an OTP.
     *
     * @return array{expires_in:int,resend_available_in:?int,remaining_issuances:int,max_issuances:int}
     * @throws TooManyAttemptsException|ApiException
     */
    public function issue(string $mobile, string $purpose = 'login', ?string $ip = null, bool $isResend = false): array
    {
        $cfg = config('vistaro.otp');
        $code = str_pad((string) random_int(0, (10 ** $cfg['length']) - 1), $cfg['length'], '0', STR_PAD_LEFT);

        $this->ensureThrottleRow($mobile, $purpose);

        $outcome = DB::transaction(function () use ($mobile, $purpose, $ip, $isResend, $cfg, $code) {
            $now = now();

            // The row already exists (see ensureThrottleRow); lock it. Locking an EXISTING row takes a plain record lock,
            // so parallel requests simply queue up here instead of deadlocking on a gap lock.
            $throttle = OtpThrottle::query()->where(['mobile' => $mobile, 'purpose' => $purpose])->lockForUpdate()->firstOrFail();

            // A fully elapsed window (or a never-used row) starts a new window.
            if ($throttle->issuance_count === 0 || $throttle->window_started_at->getTimestamp() + $cfg['window_seconds'] <= $now->getTimestamp()) {
                $throttle->window_started_at = $now;
                $throttle->issuance_count = 0;
                $throttle->next_allowed_at = null;
            }

            if ($isResend && $throttle->issuance_count === 0) {
                return ['error' => 'no_prior'];
            }

            if ($throttle->issuance_count >= $cfg['max_issuances']) {
                return ['error' => 'limit', 'retry' => $throttle->window_started_at->getTimestamp() + $cfg['window_seconds'] - $now->getTimestamp()];
            }

            if ($throttle->next_allowed_at !== null && $throttle->next_allowed_at->getTimestamp() > $now->getTimestamp()) {
                return ['error' => 'cooldown', 'retry' => $throttle->next_allowed_at->getTimestamp() - $now->getTimestamp(), 'count' => $throttle->issuance_count];
            }

            $throttle->issuance_count++;
            $cooldown = $cfg['cooldowns'][$throttle->issuance_count] ?? null;
            // Timestamps are stored with 1-second resolution, so add one second: the cooldown is then NEVER shorter
            // than configured (it can be up to 1 s longer; clients get the exact remainder in `retry_after`).
            $throttle->next_allowed_at = $cooldown !== null ? Carbon::createFromTimestamp($now->getTimestamp() + 1 + $cooldown) : null;
            $throttle->save();

            // Older OTPs for this mobile become unusable.
            OtpVerification::query()->where(['mobile' => $mobile, 'purpose' => $purpose])
                ->whereNull('verified_at')->whereNull('invalidated_at')->update(['invalidated_at' => $now]);

            $otp = OtpVerification::query()->create([
                'mobile' => $mobile, 'purpose' => $purpose, 'code_hash' => $this->hash($mobile, $purpose, $code),
                'expires_at' => $now->copy()->addSeconds($cfg['ttl_seconds']), 'failed_attempts' => 0, 'ip' => $ip,
            ]);

            return ['ok' => true, 'otp_id' => $otp->id, 'count' => $throttle->issuance_count, 'cooldown' => $cooldown];
        }, self::DEADLOCK_RETRIES);

        if (($outcome['error'] ?? null) === 'no_prior') {
            throw new ApiException('No OTP has been requested for this number yet. Please request one first.', 422, ['mobile' => ['Request an OTP first.']]);
        }
        if (($outcome['error'] ?? null) === 'limit') {
            throw new TooManyAttemptsException('Daily OTP limit reached. Please try again later.', $outcome['retry'], [
                'reason' => 'daily_limit', 'remaining_issuances' => 0,
            ]);
        }
        if (($outcome['error'] ?? null) === 'cooldown') {
            throw new TooManyAttemptsException('Please wait before requesting another OTP.', $outcome['retry'], [
                'reason' => 'cooldown', 'remaining_issuances' => $cfg['max_issuances'] - $outcome['count'],
            ]);
        }

        // Delivery happens AFTER the transaction so no database lock is held during a network call.
        try {
            $this->delivery->send($mobile, $code, $purpose);
        } catch (Throwable $e) {
            $this->refund($mobile, $purpose, $outcome['otp_id']);
            // Never log the OTP or the full number.
            Log::error('OTP delivery failed', ['mobile' => MobileNumber::mask($mobile), 'exception' => $e::class]);
            throw new ApiException('We could not send the OTP right now. Please try again shortly.', 503);
        }

        return [
            'expires_in' => (int) $cfg['ttl_seconds'],
            'resend_available_in' => $outcome['cooldown'],            // null => no further resend in this window
            'remaining_issuances' => $cfg['max_issuances'] - $outcome['count'],
            'max_issuances' => (int) $cfg['max_issuances'],
        ];
    }

    /**
     * Verify and consume an OTP. Throws on any failure; returns normally only on success.
     *
     * @throws ApiException|TooManyAttemptsException
     */
    public function verify(string $mobile, string $code, string $purpose = 'login'): void
    {
        $max = (int) config('vistaro.otp.max_verify_attempts');

        // Failed-attempt counters must be COMMITTED even though the caller gets an error, so the
        // outcome is returned from the transaction and the exception is thrown afterwards.
        $result = DB::transaction(function () use ($mobile, $purpose, $code, $max) {
            $otp = OtpVerification::query()->where(['mobile' => $mobile, 'purpose' => $purpose])
                ->whereNull('verified_at')->whereNull('invalidated_at')
                ->orderByDesc('id')->lockForUpdate()->first();

            if (! $otp || $otp->expires_at->isPast()) {
                return ['status' => 'invalid'];
            }

            if (hash_equals($otp->code_hash, $this->hash($mobile, $purpose, $code))) {
                $otp->forceFill(['verified_at' => now()])->save();
                OtpVerification::query()->where(['mobile' => $mobile, 'purpose' => $purpose])
                    ->whereNull('verified_at')->whereNull('invalidated_at')->update(['invalidated_at' => now()]);

                return ['status' => 'ok'];
            }

            $otp->failed_attempts++;
            if ($otp->failed_attempts >= $max) {
                $otp->invalidated_at = now();          // burned: a new OTP must be requested
                $otp->save();

                return ['status' => 'locked'];
            }
            $otp->save();

            return ['status' => 'wrong', 'remaining' => $max - $otp->failed_attempts];
        }, self::DEADLOCK_RETRIES);

        match ($result['status']) {
            'ok' => null,
            'wrong' => throw new ApiException('The OTP is incorrect.', 422, [
                'otp' => ['The OTP is incorrect.'], 'attempts_remaining' => $result['remaining'],
            ]),
            'locked' => throw new TooManyAttemptsException('Too many incorrect attempts. Please request a new OTP.', $this->secondsUntilNextIssuance($mobile, $purpose), [
                'reason' => 'too_many_attempts',
            ]),
            default => throw new ApiException('The OTP is invalid or has expired.', 422, ['otp' => ['The OTP is invalid or has expired.']]),
        };
    }

    /**
     * Creates the (mobile, purpose) throttle row if missing, OUTSIDE the locking transaction. A plain autocommit
     * INSERT IGNORE holds no locks while it waits, and the unique key makes concurrent creators harmless.
     * (Locking a not-yet-existing row first would take a gap lock and make parallel first requests deadlock.)
     */
    private function ensureThrottleRow(string $mobile, string $purpose): void
    {
        $now = now();
        OtpThrottle::query()->insertOrIgnore([
            'mobile' => $mobile, 'purpose' => $purpose, 'window_started_at' => $now,
            'issuance_count' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** HMAC keyed by the app secret: a leaked table cannot be brute-forced offline without APP_KEY. */
    private function hash(string $mobile, string $purpose, string $code): string
    {
        return hash_hmac('sha256', "{$mobile}|{$purpose}|{$code}", (string) config('app.key'));
    }

    private function refund(string $mobile, string $purpose, int $otpId): void
    {
        DB::transaction(function () use ($mobile, $purpose, $otpId) {
            $t = OtpThrottle::query()->where(['mobile' => $mobile, 'purpose' => $purpose])->lockForUpdate()->first();
            $t?->forceFill(['issuance_count' => max(0, $t->issuance_count - 1), 'next_allowed_at' => null])->save();
            OtpVerification::query()->whereKey($otpId)->update(['invalidated_at' => now()]);
        }, self::DEADLOCK_RETRIES);
    }

    private function secondsUntilNextIssuance(string $mobile, string $purpose): int
    {
        $t = OtpThrottle::query()->where(['mobile' => $mobile, 'purpose' => $purpose])->first();
        if (! $t) {
            return 1;
        }
        $now = now()->getTimestamp();
        $windowEnd = $t->window_started_at->getTimestamp() + (int) config('vistaro.otp.window_seconds');

        if ($t->issuance_count >= (int) config('vistaro.otp.max_issuances')) {
            return max(1, $windowEnd - $now);
        }

        return max(1, ($t->next_allowed_at?->getTimestamp() ?? $now) - $now);
    }
}
