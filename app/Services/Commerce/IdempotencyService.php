<?php

namespace App\Services\Commerce;

use App\Exceptions\ApiException;
use App\Models\IdempotencyKey;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Makes "place order" safe to retry. The idempotency row is created first (autocommit INSERT IGNORE, race-safe via the
 * unique key), then locked inside the SAME transaction that does the work and stores the response, so:
 *  - a retry after success replays the stored response and creates nothing,
 *  - a concurrent duplicate waits on the row lock and then replays,
 *  - a failure rolls everything back (the row stays "processing", the client may retry, even with a corrected payload),
 *  - the same key with a DIFFERENT payload after a success is refused (422).
 */
final class IdempotencyService
{
    /**
     * @param Closure():array{status:int,body:array<string,mixed>,order_id?:int} $work
     * @return array{status:int,body:array<string,mixed>,replayed:bool}
     */
    public function run(User $user, string $scope, string $key, string $payloadHash, Closure $work): array
    {
        $expires = fn () => now()->addHours((int) config('vistaro.orders.idempotency_ttl_hours'));
        $identity = ['user_id' => $user->id, 'scope' => $scope, 'idempotency_key' => $key];

        IdempotencyKey::query()->insertOrIgnore($identity + [
            'request_hash' => $payloadHash, 'state' => 'processing', 'expires_at' => $expires(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::transaction(function () use ($identity, $payloadHash, $work, $expires) {
            $row = IdempotencyKey::query()->where($identity)->lockForUpdate()->firstOrFail();

            if ($row->expires_at->isPast()) {                                   // an old key may be reused
                $row->forceFill([
                    'state' => 'processing', 'request_hash' => $payloadHash, 'response_code' => null,
                    'response_body' => null, 'order_id' => null, 'expires_at' => $expires(),
                ])->save();
            } elseif ($row->state === 'completed') {
                if (! hash_equals($row->request_hash, $payloadHash)) {
                    throw new ApiException('This Idempotency-Key was already used with a different request.', 422, [
                        'idempotency_key' => ['Use a new Idempotency-Key for a different request.'],
                    ]);
                }

                return ['status' => (int) $row->response_code, 'body' => $row->response_body, 'replayed' => true];
            } elseif (! hash_equals($row->request_hash, $payloadHash)) {
                $row->forceFill(['request_hash' => $payloadHash])->save();      // earlier attempt failed and left no order: adopt the new payload
            }

            $result = $work();

            $row->forceFill([
                'state' => 'completed', 'response_code' => $result['status'], 'response_body' => $result['body'], 'order_id' => $result['order_id'] ?? null,
            ])->save();

            return ['status' => $result['status'], 'body' => $result['body'], 'replayed' => false];
        }, 3);
    }
}
