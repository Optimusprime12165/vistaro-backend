<?php

namespace App\Providers;

use App\Contracts\DeliveryProviderInterface;
use App\Contracts\ImageStorageInterface;
use App\Contracts\OtpDeliveryInterface;
use App\Contracts\PaymentGatewayInterface;
use App\Services\Otp\LogOtpDelivery;
use App\Services\Payment\MockPaymentGateway;
use App\Services\Shipping\MockDeliveryProvider;
use App\Services\Storage\DiskImageStorage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Composition root: the ONLY place that decides which concrete provider backs each abstraction.
 * To add a real SMS / courier / payment provider: write the class, add one `match` arm here, set the .env key.
 */
class VistaroServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OtpDeliveryInterface::class, fn () => match (config('vistaro.otp.delivery')) {
            'log' => new LogOtpDelivery(),
            default => throw new InvalidArgumentException('Unsupported OTP_DELIVERY: ' . config('vistaro.otp.delivery')),
        });

        $this->app->bind(DeliveryProviderInterface::class, fn () => match (config('vistaro.shipping.provider')) {
            'mock' => new MockDeliveryProvider(),
            default => throw new InvalidArgumentException('Unsupported DELIVERY_PROVIDER: ' . config('vistaro.shipping.provider')),
        });

        $this->app->bind(PaymentGatewayInterface::class, fn () => match (config('vistaro.payments.gateway')) {
            'mock' => new MockPaymentGateway(),
            default => throw new InvalidArgumentException('Unsupported PAYMENT_GATEWAY: ' . config('vistaro.payments.gateway')),
        });

        $this->app->bind(ImageStorageInterface::class, fn () => new DiskImageStorage((string) config('vistaro.images.disk')));
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Burst/abuse protection (per-minute). These use the default cache store, so in AWS the cache
     * store MUST be shared (Redis/ElastiCache, or the database driver) or limits apply per Lambda container.
     * The business OTP limits (3/day, 30s/60s cooldowns) are NOT here; they live in the database (OtpService).
     */
    private function configureRateLimiting(): void
    {
        // Request input is attacker-controlled: it may be an array/object, so never cast it blindly to string.
        $str = fn (Request $r, string $key): string => is_string($v = $r->input($key)) ? mb_strtolower(mb_substr($v, 0, 255)) : '';

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->getAuthIdentifier() ?: $r->ip()));

        RateLimiter::for('admin-login', fn (Request $r) => [
            Limit::perMinute(5)->by($str($r, 'email') . '|' . $r->ip()),
            Limit::perMinute(20)->by($r->ip()),
        ]);

        RateLimiter::for('otp-request', fn (Request $r) => [
            Limit::perMinute(5)->by($r->ip()),
            Limit::perMinute(3)->by($str($r, 'mobile') . '|' . $r->ip()),
        ]);

        RateLimiter::for('otp-verify', fn (Request $r) => Limit::perMinute(10)->by($str($r, 'mobile') . '|' . $r->ip()));
    }
}
