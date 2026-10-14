<?php

namespace App\Contracts;

use App\DTOs\ListQuery;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;

/**
 * Common signature for plain CRUD resources (categories, sub-categories, ...).
 * Workflows that are NOT CRUD (OTP, login, checkout, stock reservation, shipping) must not implement this.
 */
interface CrudInterface
{
    /** Paginated at the database level. LengthAwarePaginator extends Paginator. */
    public function index(ListQuery $query): Paginator|CursorPaginator;

    public function show(int|string $id): Model;

    /** @param array<string,mixed> $data validated data only */
    public function store(array $data): Model;

    /** @param array<string,mixed> $data validated data only */
    public function update(int|string|Model $target, array $data): Model;

    public function destroy(int|string|Model $target): void;
}
