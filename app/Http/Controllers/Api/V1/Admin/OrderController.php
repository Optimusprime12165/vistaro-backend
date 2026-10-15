<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\AdminOrderIndexRequest;
use App\Http\Requests\Admin\PickupRequest;
use App\Http\Requests\Admin\RefundRequest;
use App\Http\Requests\Admin\SimpleListRequest;
use App\Http\Requests\Admin\TransitionRequest;
use App\Http\Resources\AdminOrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\Fulfilment\AdminOrderService;
use App\Services\Fulfilment\FulfilmentService;
use App\Services\Fulfilment\ShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OrderController extends ApiController
{
    public function __construct(
        private readonly AdminOrderService $orders,
        private readonly FulfilmentService $fulfilment,
        private readonly ShipmentService $shipments,
    ) {
    }

    public function index(AdminOrderIndexRequest $request): JsonResponse
    {
        return ApiResponse::paginated($this->orders->index($request->toQuery()), AdminOrderResource::class);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->ok(AdminOrderResource::make($this->orders->detail($id))->resolve($request));
    }

    /** Confirm, process, pack, ship, out for delivery, delivered, delivery failed, cancel, returned, refund pending. */
    public function transition(TransitionRequest $request, int $id): JsonResponse
    {
        $v = $request->validated();
        $this->fulfilment->transition($id, $request->target(), $request->user(), $v['reason'] ?? null, ['restock' => isset($v['restock']) ? filter_var($v['restock'], FILTER_VALIDATE_BOOLEAN) : null]);

        return $this->ok(AdminOrderResource::make($this->orders->detail($id))->resolve($request), 'Order is now ' . $request->target()->value . '.');
    }

    /** Books the shipment (AWB) and requests the pickup for a packed order. */
    public function pickup(PickupRequest $request, int $id): JsonResponse
    {
        $at = $request->validated('pickup_at');
        $this->shipments->requestPickup($id, $request->user(), $at ? new \DateTimeImmutable($at) : null);

        return $this->created(AdminOrderResource::make($this->orders->detail($id))->resolve($request), 'Pickup requested.');
    }

    /** Manual refund record: moves a refund_pending order to refunded. */
    public function refund(RefundRequest $request, int $id): JsonResponse
    {
        $note = trim(($request->validated('reference') ? 'Ref ' . $request->validated('reference') : 'Refund recorded') . ($request->validated('note') ? ' - ' . $request->validated('note') : ''));
        $this->fulfilment->transition($id, OrderStatus::Refunded, $request->user(), $note);

        return $this->ok(AdminOrderResource::make($this->orders->detail($id))->resolve($request), 'Refund recorded.');
    }

    /** Printable label (HTML for the mock courier). Not JSON: it is meant to be opened and printed. */
    public function label(int $id): Response
    {
        $label = $this->shipments->label(Order::query()->findOrFail($id));

        return response($label->content, 200, [
            'Content-Type' => $label->contentType . '; charset=utf-8',
            'Content-Disposition' => 'inline; filename="label-' . preg_replace('/[^A-Za-z0-9._-]/', '', $label->awb) . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
            'X-Mock-Shipment' => $label->isMock ? 'true' : 'false',
        ]);
    }

    public function tracking(SimpleListRequest $request, int $id): JsonResponse
    {
        $order = Order::query()->findOrFail($id);
        $shipment = Shipment::query()->where(['order_id' => $order->id, 'type' => 'forward'])->first();
        $events = $shipment
            ? ShipmentEvent::query()->where('shipment_id', $shipment->id)->orderByDesc('occurred_at')->orderByDesc('id')->simplePaginate($request->toQuery()->perPage, ['*'], 'page', $request->toQuery()->page)
            : new \Illuminate\Pagination\Paginator([], $request->toQuery()->perPage);

        return ApiResponse::paginated($events, \App\Http\Resources\TrackingEventResource::class);
    }

    /** Pulls the courier's events for this order and applies them (idempotent). */
    public function sync(Request $request, int $id): JsonResponse
    {
        return $this->ok($this->shipments->sync(Order::query()->findOrFail($id)), 'Tracking synchronised.');
    }
}
