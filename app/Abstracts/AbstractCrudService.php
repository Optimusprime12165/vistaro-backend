<?php

namespace App\Abstracts;

use App\Contracts\CrudInterface;
use App\DTOs\ListQuery;
use Closure;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Reusable implementation for ordinary CRUD resources.
 *
 * A concrete service declares WHAT is searchable / filterable / sortable (whitelists) and the
 * base class does the database-level pagination, search, filtering and stable ordering.
 * Override any hook (or any public method) when an entity needs special behaviour.
 *
 * Deliberately NOT used for: OTP, auth, cart, checkout, stock changes, shipping.
 */
abstract class AbstractCrudService implements CrudInterface
{
    public const PAGINATE = 'paginate';          // page numbers + total count
    public const SIMPLE = 'simple';              // next/prev only, no COUNT(*)
    public const CURSOR = 'cursor';              // keyset; for large / append-heavy tables

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    // ---- declarative configuration (override in subclasses) -----------------------------------

    protected function paginationMode(): string
    {
        return self::PAGINATE;
    }

    /** Columns matched by ?q=. Matching is PREFIX ("term%") so B-tree indexes can be used. */
    protected function searchable(): array
    {
        return [];
    }

    /** Use contains-matching ("%term%") for search. Only for small tables: it cannot use a normal index. */
    protected function searchContains(): bool
    {
        return false;
    }

    /**
     * Public sort key => real column. Unknown keys fall back to defaultSort().
     * @return array<string,string>
     */
    protected function sortable(): array
    {
        return ['id' => 'id'];
    }

    /** @return array{0:string,1:string} [publicKey, direction] */
    protected function defaultSort(): array
    {
        return ['id', 'desc'];
    }

    /**
     * Public filter name => column (equality / whereIn for arrays) or Closure(Builder $q, mixed $value): void.
     * @return array<string,string|Closure>
     */
    protected function filterable(): array
    {
        return [];
    }

    /** @return list<string> relations eager-loaded for show() and index(); keep minimal. */
    protected function with(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function columns(): array
    {
        return ['*'];
    }

    // ---- CrudInterface -------------------------------------------------------------------------

    public function index(ListQuery $query): Paginator|CursorPaginator
    {
        $builder = $this->newQuery();
        if ($this->columns() !== ['*']) {
            $builder->select($this->columns());     // explicit column list; default keeps withSum()/withCount() sub-selects intact
        }

        $this->applySearch($builder, $query->search);
        $this->applyFilters($builder, $query->filters);
        $this->applySort($builder, $query->sort, $query->direction);

        return match ($this->paginationMode()) {
            self::SIMPLE => $builder->simplePaginate($query->perPage, ['*'], 'page', $query->page),
            self::CURSOR => $builder->cursorPaginate($query->perPage, ['*'], 'cursor', $query->cursor),
            default => $builder->paginate($query->perPage, ['*'], 'page', $query->page),
        };
    }

    public function show(int|string $id): Model
    {
        return $this->newQuery()->findOrFail($id);
    }

    public function store(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $data = $this->beforeStore($data);
            $model = ($this->modelClass())::query()->create($data);
            $this->afterStore($model, $data);

            return $model->refresh();
        });
    }

    public function update(int|string|Model $target, array $data): Model
    {
        return DB::transaction(function () use ($target, $data) {
            $model = $this->resolve($target);
            $data = $this->beforeUpdate($model, $data);
            $model->fill($data)->save();
            $this->afterUpdate($model, $data);

            return $model->refresh();
        });
    }

    public function destroy(int|string|Model $target): void
    {
        DB::transaction(function () use ($target) {
            $model = $this->resolve($target);
            $this->beforeDestroy($model); // may throw ConflictException to block deletion
            $model->delete();
            $this->afterDestroy($model);
        });
    }

    // ---- lifecycle hooks (run inside the transaction) -----------------------------------------

    /** @return array<string,mixed> */
    protected function beforeStore(array $data): array
    {
        return $data;
    }

    protected function afterStore(Model $model, array $data): void
    {
    }

    /** @return array<string,mixed> */
    protected function beforeUpdate(Model $model, array $data): array
    {
        return $data;
    }

    protected function afterUpdate(Model $model, array $data): void
    {
    }

    protected function beforeDestroy(Model $model): void
    {
    }

    protected function afterDestroy(Model $model): void
    {
    }

    // ---- query building -----------------------------------------------------------------------

    protected function newQuery(): Builder
    {
        return ($this->modelClass())::query()->with($this->with());
    }

    protected function resolve(int|string|Model $target): Model
    {
        return $target instanceof Model ? $target : $this->newQuery()->findOrFail($target);
    }

    protected function applySearch(Builder $builder, ?string $term): void
    {
        $term = $term !== null ? trim($term) : '';
        $columns = $this->searchable();

        if ($term === '' || $columns === []) {
            return;
        }

        $like = $this->escapeLike($term);
        $pattern = $this->searchContains() ? "%{$like}%" : "{$like}%";

        $builder->where(function (Builder $q) use ($columns, $pattern) {
            foreach ($columns as $column) {
                $q->orWhere($column, 'like', $pattern); // bound parameter, never concatenated into SQL
            }
        });
    }

    /** @param array<string,mixed> $filters */
    protected function applyFilters(Builder $builder, array $filters): void
    {
        $allowed = $this->filterable();

        foreach ($filters as $name => $value) {
            if (! isset($allowed[$name]) || $value === null || $value === '') {
                continue; // anything not whitelisted is ignored
            }

            $rule = $allowed[$name];

            if ($rule instanceof Closure) {
                $rule($builder, $value);
            } elseif (is_array($value)) {
                $builder->whereIn($rule, $value);
            } else {
                $builder->where($rule, $value);
            }
        }
    }

    /**
     * Whitelisted sort + primary-key tie-breaker. The tie-breaker makes ordering total, which prevents
     * duplicated/skipped rows across pages and is REQUIRED for correct cursor pagination.
     */
    protected function applySort(Builder $builder, ?string $sort, ?string $direction): void
    {
        [$defaultKey, $defaultDir] = $this->defaultSort();
        $map = $this->sortable();

        $key = ($sort !== null && isset($map[$sort])) ? $sort : $defaultKey;
        $dir = in_array(strtolower((string) $direction), ['asc', 'desc'], true) ? strtolower((string) $direction) : $defaultDir;

        $column = $map[$key] ?? $key;
        $pk = $builder->getModel()->getQualifiedKeyName();

        $builder->orderBy($column, $dir);

        if ($column !== $builder->getModel()->getKeyName() && $column !== $pk) {
            $builder->orderBy($pk, $dir);
        }
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
