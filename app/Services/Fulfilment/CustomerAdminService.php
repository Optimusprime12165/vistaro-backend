<?php

namespace App\Services\Fulfilment;

use App\Abstracts\AbstractCrudService;
use App\Models\User;
use App\Services\Concerns\MatchesMobileNumbers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Read-only customer list for the admin panel (customers manage themselves through OTP login and their profile). */
final class CustomerAdminService extends AbstractCrudService
{
    use MatchesMobileNumbers;

    protected function modelClass(): string
    {
        return User::class;
    }

    protected function sortable(): array
    {
        return ['id' => 'id', 'name' => 'name', 'created_at' => 'created_at', 'last_login_at' => 'last_login_at'];
    }

    protected function filterable(): array
    {
        return ['status' => 'status'];
    }

    protected function newQuery(): Builder
    {
        return parent::newQuery()->withCount('orders');
    }

    protected function applySearch(Builder $builder, ?string $term): void
    {
        $term = trim((string) $term);
        if ($term === '') {
            return;
        }

        $builder->where(function (Builder $q) use ($term) {
            $like = $this->escapeLike($term) . '%';
            $q->where('name', 'like', $like)->orWhere('email', 'like', $like);
            $this->orWhereMobile($q, 'mobile', $term);
        });
    }

    public function store(array $data): Model
    {
        throw new LogicException('Customers are created by OTP sign-in.');
    }

    public function update(int|string|Model $target, array $data): Model
    {
        throw new LogicException('Read-only.');
    }

    public function destroy(int|string|Model $target): void
    {
        throw new LogicException('Customers with orders are never deleted.');
    }
}
