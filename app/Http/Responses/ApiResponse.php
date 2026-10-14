<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Standard envelopes.
 *   success: { success: true, message, data, meta? }
 *   error:   { success: false, message, errors }
 */
final class ApiResponse
{
    /** @param array<string,mixed> $meta */
    public static function success(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): JsonResponse
    {
        $body = ['success' => true, 'message' => $message, 'data' => $data];

        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    public static function created(mixed $data = null, ?string $message = 'Created.'): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * @param array<string,mixed> $errors
     * @param array<string,string> $headers
     */
    public static function error(string $message, int $status = 400, array $errors = [], array $headers = []): JsonResponse
    {
        return response()->json(
            ['success' => false, 'message' => $message, 'errors' => (object) $errors],
            $status,
            $headers,
        );
    }

    /**
     * Only the already-sliced page is transformed; nothing here loads the rest of the table.
     * Meta shape depends on the pagination strategy actually used:
     *  - paginate()       : current_page, per_page, total, last_page, from, to
     *  - simplePaginate() : current_page, per_page, has_more
     *  - cursorPaginate() : per_page, next_cursor, prev_cursor, has_more
     *
     * @param class-string<JsonResource> $resource
     */
    public static function paginated(AbstractPaginator|AbstractCursorPaginator $page, string $resource, ?string $message = null): JsonResponse
    {
        $data = $resource::collection($page->getCollection())->resolve(request());

        return self::success($data, $message, 200, self::meta($page) + ['links' => self::links($page)]);
    }

    /** @return array<string,mixed> */
    private static function meta(AbstractPaginator|AbstractCursorPaginator $page): array
    {
        return match (true) {
            $page instanceof LengthAwarePaginator => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
            $page instanceof Paginator => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'has_more' => $page->hasMorePages(),
            ],
            $page instanceof CursorPaginator => [
                'per_page' => $page->perPage(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'prev_cursor' => $page->previousCursor()?->encode(),
                'has_more' => $page->hasMorePages(),
            ],
            default => ['per_page' => $page->perPage()],
        };
    }

    /** @return array<string,string|null> */
    private static function links(AbstractPaginator|AbstractCursorPaginator $page): array
    {
        return [
            'next' => $page->nextPageUrl(),
            'prev' => $page->previousPageUrl(),
        ];
    }
}
