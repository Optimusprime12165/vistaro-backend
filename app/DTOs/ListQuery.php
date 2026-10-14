<?php

namespace App\DTOs;

/**
 * Normalised, already-validated list parameters passed from a ListRequest to a service.
 * Services never read the HTTP request directly.
 */
final class ListQuery
{
    /**
     * @param array<string,mixed> $filters keys are whitelisted by the service
     */
    public function __construct(
        public readonly ?string $search = null,
        public readonly array $filters = [],
        public readonly ?string $sort = null,
        public readonly ?string $direction = null,
        public readonly int $perPage = 10,
        public readonly ?int $page = null,
        public readonly ?string $cursor = null,
    ) {
    }
}
