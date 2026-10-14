<?php

namespace App\Http\Requests;

use App\DTOs\ListQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * Base for every paginated index endpoint.
 *  - per_page: default 10, 20 supported, hard maximum from config (default 100) => 422 above it.
 *  - sort/direction: whitelisted per endpoint.
 * Subclasses add authorize(), sortFields() and filterRules().
 */
abstract class ListRequest extends FormRequest
{
    /** @return list<string> public sort keys accepted by this endpoint */
    protected function sortFields(): array
    {
        return ['id'];
    }

    /** @return array<string,mixed> validation rules for endpoint-specific filters */
    protected function filterRules(): array
    {
        return [];
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:' . config('vistaro.pagination.max')],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:512'],
            'sort' => ['sometimes', 'nullable', 'string', 'in:' . implode(',', $this->sortFields())],
            'direction' => ['sometimes', 'nullable', 'string', 'in:asc,desc'],
        ] + $this->filterRules();
    }

    public function toQuery(): ListQuery
    {
        $v = $this->validated();

        return new ListQuery(
            search: $v['q'] ?? null,
            filters: Arr::only($v, array_keys($this->filterRules())),
            sort: $v['sort'] ?? null,
            direction: $v['direction'] ?? null,
            perPage: (int) ($v['per_page'] ?? config('vistaro.pagination.default')),
            page: isset($v['page']) ? (int) $v['page'] : null,
            cursor: $v['cursor'] ?? null,
        );
    }
}
