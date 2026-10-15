<?php

namespace Tests\Unit;

use App\Enums\SettingType;
use App\Exceptions\InvalidSettingException;
use App\Support\SecretDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SettingTypeTest extends TestCase
{
    public static function validValues(): array
    {
        return [
            'string' => [SettingType::String, 'Hello world', 'Hello world', 'Hello world'],
            'string empty' => [SettingType::String, '', '', ''],
            'string keeps leading zero phone' => [SettingType::String, '0141-2345678', '0141-2345678', '0141-2345678'],
            'string keeps plus phone' => [SettingType::String, '+91 98290 00000', '+91 98290 00000', '+91 98290 00000'],
            'integer int' => [SettingType::Integer, 99, '99', 99],
            'integer string' => [SettingType::Integer, '1999', '1999', 1999],
            'integer negative' => [SettingType::Integer, '-5', '-5', -5],
            'integer zero' => [SettingType::Integer, 0, '0', 0],
            'decimal string' => [SettingType::Decimal, '10.50', '10.5', '10.5'],
            'decimal int' => [SettingType::Decimal, 7, '7', '7'],
            'decimal float' => [SettingType::Decimal, 0.1, '0.1', '0.1'],
            'decimal negative zero collapses' => [SettingType::Decimal, '-0.00', '0', '0'],
            'decimal 8dp' => [SettingType::Decimal, '1.12345678', '1.12345678', '1.12345678'],
            'boolean true' => [SettingType::Boolean, true, '1', true],
            'boolean "false"' => [SettingType::Boolean, 'false', '0', false],
            'boolean 1' => [SettingType::Boolean, 1, '1', true],
            'boolean "0"' => [SettingType::Boolean, '0', '0', false],
            'email' => [SettingType::Email, ' help@vistaro.example ', 'help@vistaro.example', 'help@vistaro.example'],
            'url' => [SettingType::Url, 'https://vistaro.example/returns?x=1', 'https://vistaro.example/returns?x=1', 'https://vistaro.example/returns?x=1'],
            'json object text' => [SettingType::Json, '{"a": 1, "b": [true, null]}', '{"a":1,"b":[true,null]}', ['a' => 1, 'b' => [true, null]]],
            'json array value' => [SettingType::Json, ['x' => 'y/z'], '{"x":"y/z"}', ['x' => 'y/z']],
            'json list' => [SettingType::Json, '[1,2,3]', '[1,2,3]', [1, 2, 3]],
            'json unicode kept' => [SettingType::Json, '{"city":"जयपुर"}', '{"city":"जयपुर"}', ['city' => 'जयपुर']],
        ];
    }

    #[DataProvider('validValues')]
    public function test_valid_values_are_normalised_deterministically_and_cast_back(SettingType $type, mixed $input, string $stored, mixed $typed): void
    {
        $this->assertSame($stored, $type->normalize($input));
        $this->assertSame($stored, $type->normalize($stored), 'normalising the canonical form again changes nothing (idempotent)');
        $this->assertSame($typed, $type->cast($stored));
    }

    public static function invalidValues(): array
    {
        return [
            'string array' => [SettingType::String, ['a']],
            'string int' => [SettingType::String, 5],
            'string null' => [SettingType::String, null],
            'string too long' => [SettingType::String, str_repeat('x', 5001)],
            'integer decimal text' => [SettingType::Integer, '12.5'],
            'integer words' => [SettingType::Integer, 'abc'],
            'integer float' => [SettingType::Integer, 12.0],
            'integer leading zero' => [SettingType::Integer, '012'],
            'integer minus zero' => [SettingType::Integer, '-0'],
            'integer exponent' => [SettingType::Integer, '1e3'],
            'integer empty' => [SettingType::Integer, ''],
            'integer bool' => [SettingType::Integer, true],
            'integer overflow' => [SettingType::Integer, '99999999999999999999'],
            'decimal exponent' => [SettingType::Decimal, '1e5'],
            'decimal words' => [SettingType::Decimal, 'ten'],
            'decimal comma' => [SettingType::Decimal, '1,5'],
            'decimal too many places' => [SettingType::Decimal, '1.123456789'],
            'decimal NAN' => [SettingType::Decimal, NAN],
            'decimal INF' => [SettingType::Decimal, INF],
            'decimal array' => [SettingType::Decimal, [1]],
            'boolean maybe' => [SettingType::Boolean, 'maybe'],
            'boolean 2' => [SettingType::Boolean, 2],
            'boolean null' => [SettingType::Boolean, null],
            'boolean yes' => [SettingType::Boolean, 'yes'],
            'email bad' => [SettingType::Email, 'not-an-email'],
            'email array' => [SettingType::Email, ['a@b.co']],
            'url ftp' => [SettingType::Url, 'ftp://example.com/file'],
            'url javascript' => [SettingType::Url, 'javascript:alert(1)'],
            'url bare' => [SettingType::Url, 'example.com'],
            'json invalid' => [SettingType::Json, '{bad json'],
            'json trailing comma' => [SettingType::Json, '{"a":1,}'],
            'json scalar number' => [SettingType::Json, '123'],
            'json scalar string' => [SettingType::Json, '"text"'],
            'json null' => [SettingType::Json, 'null'],
            'json php null' => [SettingType::Json, null],
            'json too deep' => [SettingType::Json, str_repeat('[', 40) . str_repeat(']', 40)],
            'json too large' => [SettingType::Json, '{"a":"' . str_repeat('x', 70000) . '"}'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_values_are_rejected(SettingType $type, mixed $input): void
    {
        $this->expectException(InvalidSettingException::class);
        $type->normalize($input);
    }

    public function test_only_the_seven_documented_types_exist(): void
    {
        $this->assertSame(['string', 'integer', 'decimal', 'boolean', 'email', 'url', 'json'], SettingType::values());
        $this->assertNull(SettingType::tryFrom('float'));
        $this->assertNull(SettingType::tryFrom('secret'));
    }

    public function test_corrupt_stored_values_are_refused_on_cast(): void
    {
        foreach ([[SettingType::Integer, 'abc'], [SettingType::Boolean, 'yes'], [SettingType::Json, '{broken'], [SettingType::Json, '5']] as [$type, $stored]) {
            try {
                $type->cast($stored);
                $this->fail("{$type->value} should refuse stored '{$stored}'");
            } catch (InvalidSettingException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public static function secrets(): array
    {
        return [
            'private key' => ["-----BEGIN RSA PRIVATE KEY-----\nMIIE...", true],
            'aws key' => ['AKIAIOSFODNN7EXAMPLE', true],
            'stripe style' => ['sk_live_abcdefghijklmnop', true],
            'laravel app key' => ['base64:' . base64_encode(random_bytes(32)), true],
            'jwt' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.abcdefghijk', true],
            'url with credentials' => ['mysql://root:hunter2@db.internal/app', true],
            'github token' => ['ghp_' . str_repeat('a', 30), true],
            'bearer' => ['Bearer abcdefghijklmnopqrstuvwxyz012345', true],
            'nested json key' => [['smtp' => ['password' => 'x']], true],
            'nested json value' => [['a' => ['b' => 'AKIAIOSFODNN7EXAMPLE']], true],
            'ordinary text' => ['Free shipping on orders above Rs 1999', false],
            'public url' => ['https://vistaro.example/help', false],
            'phone' => ['+91 98290 00000', false],
            'normal json' => [['hours' => '10-8', 'city' => 'Jaipur'], false],
            'publishable key is not a secret' => ['pk_live_abcdefghijklmnop', false],
        ];
    }

    #[DataProvider('secrets')]
    public function test_secret_detector_flags_credential_shaped_values(mixed $value, bool $secret): void
    {
        $this->assertSame($secret, SecretDetector::valueLooksSecret($value));
    }

    public function test_secret_detector_flags_secret_named_keys(): void
    {
        foreach (['db_password', 'payment.gateway_secret', 'api_key', 'store.apikey', 'auth.access_token', 'smtp.credentials', 'app_key', 'private_key_pem', 'x.client_secret'] as $k) {
            $this->assertTrue(SecretDetector::keyLooksSecret($k), $k);
        }
        foreach (['shipping.flat_fee', 'store.support_email', 'orders.return_window_days', 'store.name', 'store.phone'] as $k) {
            $this->assertFalse(SecretDetector::keyLooksSecret($k), $k);
        }
    }
}
