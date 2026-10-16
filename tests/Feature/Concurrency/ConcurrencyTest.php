<?php

namespace Tests\Feature\Concurrency;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\ProductSize;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\Auth\TokenService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FulfilmentTestHelpers;
use Tests\TestCase;

/**
 * GENUINE concurrency: every scenario starts several separate PHP processes, each with its own database connection, arms
 * them behind a start barrier and releases them at the same instant. Nothing here is simulated with sequential calls.
 *
 * Needs a database with real row locks (MySQL / MariaDB) and committed data, so it does NOT use RefreshDatabase transactions
 * and is SKIPPED on SQLite (where lockForUpdate() is a no-op and an in-memory database cannot be shared between processes).
 * Run:  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=vistaro_test DB_USERNAME=... DB_PASSWORD=... vendor/bin/phpunit --testsuite Feature --filter Concurrency
 */
class ConcurrencyTest extends TestCase
{
    use FulfilmentTestHelpers;

    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Concurrency tests need MySQL/MariaDB (SQLite has no row locks and no shared in-memory DB).');
        }
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is disabled.');
        }
        config(['vistaro.shipping.webhook_secret' => self::SECRET]);
        Artisan::call('migrate:fresh', ['--force' => true]);          // committed data: start clean ...
    }

    protected function tearDown(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Artisan::call('migrate:fresh', ['--force' => true]);      // ... and leave a clean database for the next test class
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------ harness

    /**
     * @param list<array{method:string,uri:string,headers?:array<string,string>,raw_body?:string}> $requests one per process
     * @return array{results:list<array<string,mixed>>,overlapped:bool}
     */
    private function race(array $requests): array
    {
        $dir = sys_get_temp_dir() . '/vistaro-race-' . Str::random(10);
        mkdir($dir);
        $go = "{$dir}/go";
        $procs = [];
        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DELIVERY_WEBHOOK_SECRET' => self::SECRET, 'CACHE_STORE' => 'array']);

        foreach ($requests as $i => $req) {
            $jobFile = "{$dir}/job{$i}.json";
            file_put_contents($jobFile, json_encode($req + ['go' => $go, 'ready' => "{$dir}/ready{$i}"]));
            $p = proc_open([PHP_BINARY, base_path('tests/Support/concurrency_worker.php'), $jobFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $this->assertIsResource($p);
            $procs[$i] = [$p, $pipes];
        }

        $deadline = microtime(true) + 60;                              // wait until EVERY process is booted, connected and waiting
        while (count(glob("{$dir}/ready*")) < count($requests) && microtime(true) < $deadline) {
            usleep(5000);
        }
        $this->assertCount(count($requests), glob("{$dir}/ready*"), 'worker processes failed to start');
        touch($go);                                                    // release them all at once

        $results = [];
        foreach ($procs as $i => [$p, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
            $decoded = json_decode(trim($out), true);
            $this->assertIsArray($decoded, "worker {$i} produced no result. stdout: {$out} stderr: {$err}");
            $results[] = $decoded;
        }
        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);

        // Proof of real overlap: every process had started its request before ANY process had finished.
        $overlapped = max(array_column($results, 'started')) < min(array_column($results, 'finished'));
        $this->assertSame(count($requests), count(array_unique(array_column($results, 'pid'))), 'workers must be distinct processes');
        $this->assertTrue($overlapped, 'The requests did not overlap in time, so this run proved nothing about concurrency.');

        return ['results' => $results, 'overlapped' => $overlapped];
    }

    private function bearerFor(\Illuminate\Database\Eloquent\Model $who, string $ability): array
    {
        return ['Authorization' => 'Bearer ' . app(TokenService::class)->issue($who, $ability, 60, 'race')->plainTextToken];
    }

    /** @param list<array<string,mixed>> $results @return array<int,int> status => how many */
    private function statuses(array $results): array
    {
        $c = array_count_values(array_column($results, 'status'));
        ksort($c);

        return $c;
    }

    private function adminHeaders(): array
    {
        return $this->bearerFor(\App\Models\Admin::factory()->create(['role' => 'staff']), 'admin');
    }

    // ---------------------------------------------------------------- scenarios

    public function test_simultaneous_orders_for_the_last_units_never_oversell(): void
    {
        $product = $this->makeProduct(['M' => 2], ['price' => '1000.00', 'mrp' => '1200.00']);
        $requests = [];
        foreach (range(1, 6) as $n) {                                  // six different customers, one unit each, only two in stock
            [$user, $address] = $this->shopper();
            $this->addToCart($user, $product, 'M', 1)->assertSuccessful();
            $requests[] = [
                'method' => 'POST', 'uri' => '/api/v1/orders',
                'headers' => $this->bearerFor($user, 'customer') + ['Idempotency-Key' => "race-order-{$n}-" . Str::random(8)],
                'raw_body' => json_encode(['address_id' => $address->id, 'payment_method' => 'cod']),
            ];
        }

        $race = $this->race($requests);

        $this->assertSame([201 => 2, 409 => 4], $this->statuses($race['results']), 'exactly the two units in stock may be sold');
        $this->assertSame(2, Order::count());
        $row = ProductSize::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame([0, 2], [(int) $row->quantity, (int) $row->reserved], 'no negative stock, nothing double-reserved');
    }

    public function test_the_same_idempotency_key_sent_concurrently_creates_exactly_one_order(): void
    {
        [$user, $address] = $this->shopper();
        $product = $this->makeProduct(['M' => 10], ['price' => '1000.00', 'mrp' => '1200.00']);
        $this->addToCart($user, $product, 'M', 2)->assertSuccessful();
        $req = [
            'method' => 'POST', 'uri' => '/api/v1/orders',
            'headers' => $this->bearerFor($user, 'customer') + ['Idempotency-Key' => 'race-same-key-0001'],
            'raw_body' => json_encode(['address_id' => $address->id, 'payment_method' => 'cod']),
        ];

        $race = $this->race(array_fill(0, 6, $req));

        $this->assertSame([201 => 6], $this->statuses($race['results']), 'the original and every duplicate answer 201');
        $this->assertCount(1, array_unique(array_map(fn ($r) => $r['body']['data']['id'], $race['results'])), 'all six got the SAME order');
        $this->assertSame(5, count(array_filter(array_column($race['results'], 'replayed'))), 'one real run, five replays');
        $this->assertSame(1, Order::count());
        $this->assertSame(1, \App\Models\OrderAddress::count());
        $this->assertSame(1, \App\Models\IdempotencyKey::where('idempotency_key', 'race-same-key-0001')->count());
        $this->assertSame([8, 2], $this->stock($product, 'M'), 'stock reserved exactly once');
    }

    public function test_simultaneous_cancels_of_one_order_release_the_stock_exactly_once(): void
    {
        [$order, $product, $customer] = $this->newOrder(2);
        $this->assertSame([8, 2], $this->stock($product, 'M'));
        $req = ['method' => 'POST', 'uri' => "/api/v1/orders/{$order->id}/cancel", 'headers' => $this->bearerFor($customer, 'customer'), 'raw_body' => '{}'];

        $race = $this->race(array_fill(0, 5, $req));

        $this->assertSame([200 => 1, 422 => 4], $this->statuses($race['results']));
        $this->assertSame('cancelled', $this->statusOf($order->id));
        $this->assertSame([10, 0], $this->stock($product, 'M'), 'released once: not 12/-2');
        $this->assertSame(1, OrderStatusHistory::where(['order_id' => $order->id, 'to_status' => 'cancelled'])->count());
    }

    public function test_simultaneous_identical_admin_transitions_apply_once(): void
    {
        [$order] = $this->newOrder(1);
        $req = ['method' => 'POST', 'uri' => "/api/v1/admin/orders/{$order->id}/transition", 'headers' => $this->adminHeaders(), 'raw_body' => json_encode(['status' => 'confirmed'])];

        $race = $this->race(array_fill(0, 5, $req));

        $this->assertSame([200 => 1, 422 => 4], $this->statuses($race['results']));
        $this->assertSame('confirmed', $this->statusOf($order->id));
        $this->assertSame(1, OrderStatusHistory::where(['order_id' => $order->id, 'to_status' => 'confirmed'])->count());
    }

    public function test_admin_confirm_racing_customer_cancel_always_ends_consistently(): void
    {
        [$order, $product, $customer] = $this->newOrder(2);
        $requests = [
            ['method' => 'POST', 'uri' => "/api/v1/admin/orders/{$order->id}/transition", 'headers' => $this->adminHeaders(), 'raw_body' => json_encode(['status' => 'confirmed'])],
            ['method' => 'POST', 'uri' => "/api/v1/orders/{$order->id}/cancel", 'headers' => $this->bearerFor($customer, 'customer'), 'raw_body' => '{}'],
        ];

        $race = $this->race($requests);

        // Either order of events is legal (cancel-then-confirm is refused; confirm-then-cancel is allowed) but the end state is not negotiable.
        foreach ($race['results'] as $r) {
            $this->assertContains($r['status'], [200, 422], 'no 5xx / deadlock leaks to the client: ' . json_encode($r['body']));
        }
        $this->assertSame('cancelled', $this->statusOf($order->id));
        $this->assertSame([10, 0], $this->stock($product, 'M'), 'stock released exactly once');
        $this->assertSame(1, OrderStatusHistory::where(['order_id' => $order->id, 'to_status' => 'cancelled'])->count());
    }

    public function test_duplicate_webhook_delivery_is_applied_once(): void
    {
        [$order] = $this->newOrder(1);
        $awb = $this->booked($order->id)->awb;
        $body = json_encode(['events' => [$this->event('evt-dup-1', $awb, 'picked_up', '2026-10-15T10:00:00+00:00')]]);
        $req = [
            'method' => 'POST', 'uri' => '/api/v1/webhooks/delivery/mock',
            'headers' => ['X-Signature' => hash_hmac('sha256', $body, self::SECRET)], 'raw_body' => $body,
        ];

        $race = $this->race(array_fill(0, 8, $req));

        $this->assertSame([200 => 8], $this->statuses($race['results']), 'a duplicate is acknowledged, never an error');
        $applied = array_sum(array_map(fn ($r) => $r['body']['data']['applied'], $race['results']));
        $dups = array_sum(array_map(fn ($r) => $r['body']['data']['duplicates'], $race['results']));
        $this->assertSame([1, 7], [$applied, $dups]);
        $shipment = Shipment::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(1, ShipmentEvent::where(['shipment_id' => $shipment->id, 'external_id' => 'evt-dup-1'])->count());
        $this->assertSame('shipped', $this->statusOf($order->id));
        $this->assertSame(1, OrderStatusHistory::where(['order_id' => $order->id, 'to_status' => 'shipped'])->count());
    }

    public function test_webhook_racing_an_admin_transition_does_not_double_apply(): void
    {
        [$order] = $this->newOrder(1);
        $awb = $this->booked($order->id)->awb;
        $body = json_encode(['events' => [$this->event('evt-race-1', $awb, 'picked_up', '2026-10-15T10:00:00+00:00')]]);
        $requests = [
            ['method' => 'POST', 'uri' => '/api/v1/webhooks/delivery/mock', 'headers' => ['X-Signature' => hash_hmac('sha256', $body, self::SECRET)], 'raw_body' => $body],
            ['method' => 'POST', 'uri' => "/api/v1/admin/orders/{$order->id}/transition", 'headers' => $this->adminHeaders(), 'raw_body' => json_encode(['status' => 'shipped'])],
        ];

        $race = $this->race($requests);

        foreach ($race['results'] as $r) {
            $this->assertContains($r['status'], [200, 422], json_encode($r['body']));
        }
        $this->assertSame('shipped', $this->statusOf($order->id));
        $this->assertSame(1, OrderStatusHistory::where(['order_id' => $order->id, 'to_status' => 'shipped'])->count(), 'shipped is recorded once');
    }
}
