<?php

namespace App\Services\Fulfilment;

use App\Abstracts\AbstractCrudService;
use App\Models\LoginEvent;
use App\Services\Concerns\MatchesMobileNumbers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** "Customer logins" page: append-only, newest first, cursor paginated. */
final class LoginEventAdminService extends AbstractCrudService
{
    use MatchesMobileNumbers;

    protected function modelClass(): string
    {
        return LoginEvent::class;
    }

    protected function paginationMode(): string
    {
        return self::CURSOR;
    }

    protected function sortable(): array
    {
        return ['id' => 'id', 'created_at' => 'created_at'];
    }

    protected function filterable(): array
    {
        return [
            'user_id' => 'user_id',
            'date_from' => fn (Builder $q, $v) => $q->where('created_at', '>=', $v . ' 00:00:00'),
            'date_to' => fn (Builder $q, $v) => $q->where('created_at', '<=', $v . ' 23:59:59'),
        ];
    }

    protected function with(): array
    {
        return ['user:id,name'];
    }

    protected function applySearch(Builder $builder, ?string $term): void
    {
        $term = trim((string) $term);
        if ($term !== '') {
            $builder->where(fn (Builder $q) => $this->orWhereMobile($q, 'mobile', $term));
        }
    }

    public function store(array $data): Model
    {
        throw new LogicException('Login events are written by the sign-in flow only.');
    }

    public function update(int|string|Model $target, array $data): Model
    {
        throw new LogicException('Login events are append-only.');
    }

    public function destroy(int|string|Model $target): void
    {
        throw new LogicException('Login events are append-only.');
    }
}
