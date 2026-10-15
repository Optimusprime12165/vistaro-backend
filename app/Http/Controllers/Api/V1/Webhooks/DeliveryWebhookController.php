<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Contracts\DeliveryProviderInterface;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\ApiController;
use App\Services\Fulfilment\ShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Courier callbacks. No Sanctum: the provider authenticates the request itself (signature on the RAW body, verified inside
 * parseWebhook; an invalid one is HTTP 401). Replays are harmless: events are de-duplicated by (shipment, external id).
 */
class DeliveryWebhookController extends ApiController
{
    public function __construct(private readonly DeliveryProviderInterface $courier, private readonly ShipmentService $shipments)
    {
    }

    public function handle(Request $request, string $provider): JsonResponse
    {
        if ($provider !== $this->courier->name()) {
            throw new ApiException('Resource not found.', 404);
        }

        $events = $this->courier->parseWebhook($request->getContent(), $request->headers->all());

        return $this->ok($this->shipments->ingestWebhook($events), 'Webhook processed.');
    }
}
