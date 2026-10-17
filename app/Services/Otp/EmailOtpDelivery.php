<?php

namespace App\Services\Otp;

use App\Contracts\OtpDeliveryInterface;
use Illuminate\Support\Facades\Mail;

/** Sends the OTP by email through whatever MAIL_MAILER is configured (smtp / ses / resend / log ...). */
final class EmailOtpDelivery implements OtpDeliveryInterface
{
    public function send(string $mobile, string $otp, string $purpose): void
    {
        $app = e((string) config('app.name', 'Vistaro'));
        $minutes = max(1, (int) ceil(((int) config('vistaro.otp.ttl_seconds')) / 60));
        $why = $purpose === 'verify_email' ? 'verify your email address' : 'sign in to your account';
        $digits = implode('', array_map(
            fn (string $d) => '<span style="display:inline-block;width:44px;height:56px;line-height:56px;margin:0 3px;background:#12261b;color:#c6f24e;border-radius:12px;font-size:28px;font-weight:700;text-align:center">' . $d . '</span>',
            str_split($otp),
        ));

        $html = <<<HTML
<!doctype html><html><body style="margin:0;padding:24px;background:#dce6d2;font-family:Arial,Helvetica,sans-serif;color:#12261b">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<table role="presentation" width="480" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border-radius:24px;padding:36px">
<tr><td style="font-size:22px;font-weight:700;letter-spacing:-0.5px">{$app}</td></tr>
<tr><td style="padding-top:20px;font-size:16px;line-height:1.5">Use this one-time code to {$why}:</td></tr>
<tr><td align="center" style="padding:24px 0">{$digits}</td></tr>
<tr><td style="font-size:14px;line-height:1.5;color:#4f6357">This code expires in {$minutes} minutes and can be used only once.<br>If you did not request it, you can safely ignore this email. Never share this code with anyone.</td></tr>
</table></td></tr></table></body></html>
HTML;

        Mail::html($html, function ($m) use ($mobile, $app, $otp) {
            $m->to($mobile)->subject("{$otp} is your {$app} verification code");
        });
    }
}
