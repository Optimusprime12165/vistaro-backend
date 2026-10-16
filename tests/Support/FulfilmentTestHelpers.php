<?php

namespace Tests\Support;

use App\Contracts\DeliveryProviderInterface;
use App\Enums\AdminRole;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Testing\TestResponse;

trait FulfilmentTestHelpers
{
    use CatalogTestHelpers;

    protected SpyDeliveryProvider $courier;

    protected function useSpyCourier(): void
    {
        $this->courier = new SpyDeliveryProvider();
        $this->app->instance(DeliveryProviderInterface::class, $this->courier);
    }

    /** A real order placed through the customer API. @return array{0:Order,1:Product,2:User} */
    protected function newOrder(int $qty = 2, string $method = 'cod', array $stock = ['M' => 10]): array
    {
        [$user, $address] = $this->shopper();
        $p = $this->makeProduct($stock, ['price' => '1000.00', 'mrp' => '1200.00']);
        $this->addToCart($user, $p, array_key_first($stock), $qty);
        $id = $this->placeOrder($user, $address, $method)->assertCreated()->json('data.id');

        return [Order::findOrFail($id), $p, $user];
    }

    protected function move(int $orderId, string $status, array $extra = [], AdminRole $role = AdminRole::SuperAdmin): TestResponse
    {
        return $this->actingAsAdmin($role)->postJson("/api/v1/admin/orders/{$orderId}/transition", ['status' => $status] + $extra);
    }

    /** confirm -> processing -> packed (all must succeed) */
    protected function pack(int $orderId): void
    {
        foreach (['confirmed', 'processing', 'packed'] as $s) {
            $this->move($orderId, $s)->assertOk();
        }
    }

    protected function pickup(int $orderId, AdminRole $role = AdminRole::SuperAdmin): TestResponse
    {
        return $this->actingAsAdmin($role)->postJson("/api/v1/admin/orders/{$orderId}/pickup");
    }

    /** Takes an order all the way to "pickup requested" (shipment booked) */
    protected function booked(int $orderId): Shipment
    {
        $this->pack($orderId);
        $this->pickup($orderId)->assertCreated();

        return Shipment::where('order_id', $orderId)->firstOrFail();
    }

    protected function shipped(int $orderId): Shipment
    {
        $s = $this->booked($orderId);
        $this->move($orderId, 'shipped')->assertOk();

        return $s->refresh();
    }

    protected function delivered(int $orderId): Shipment
    {
        $s = $this->shipped($orderId);
        $this->move($orderId, 'out_for_delivery')->assertOk();
        $this->move($orderId, 'delivered')->assertOk();

        return $s->refresh();
    }

    protected function statusOf(int $orderId): string
    {
        return Order::findOrFail($orderId)->status->value;
    }

    /** POST a courier webhook signed with the shared secret. $events: [['id','awb','status','at','location','description']] */
    protected function webhook(array $events, ?string $signature = null, string $provider = 'mock', ?string $rawBody = null): TestResponse
    {
        config(['vistaro.shipping.webhook_secret' => 'test-webhook-secret']);
        $body = $rawBody ?? json_encode(['events' => $events]);
        $sig = $signature ?? hash_hmac('sha256', $body, 'test-webhook-secret');

        return $this->guest()->call('POST', "/api/v1/webhooks/delivery/{$provider}", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SIGNATURE' => $sig,
        ], $body);
    }

    protected function event(string $id, string $awb, string $status, ?string $at = null): array
    {
        return ['id' => $id, 'awb' => $awb, 'status' => $status, 'at' => $at ?? now()->toIso8601String(), 'location' => 'Jaipur hub', 'description' => "Courier says {$status}"];
    }
}
