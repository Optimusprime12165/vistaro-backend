<?php

namespace App\Services\Commerce;

use App\Exceptions\ApiException;
use App\Models\Address;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Customer addresses. Invariant (kept by every write here): a customer with at least one address has EXACTLY ONE default.
 * Every write takes a row lock on the customer first, so two concurrent requests of the same customer are serialised and
 * can never leave zero or two defaults.
 */
final class AddressService
{
    /** @return Collection<int,Address> default first, then newest */
    public function list(User $user): Collection
    {
        return $user->addresses()->orderByDesc('is_default')->orderByDesc('id')->get();
    }

    /** Only the customer's own address; someone else's id is simply "not found". */
    public function find(User $user, int $id): Address
    {
        return $user->addresses()->findOrFail($id);
    }

    /** @param array<string,mixed> $data validated + normalised */
    public function create(User $user, array $data): Address
    {
        return DB::transaction(function () use ($user, $data) {
            $this->lock($user);

            $max = (int) config('vistaro.addresses.max_per_customer');
            if ($user->addresses()->count() >= $max) {
                throw new ApiException("You can save at most {$max} addresses. Delete one first.", 422, ['address' => ["Address limit ({$max}) reached."]]);
            }

            $makeDefault = (bool) ($data['is_default'] ?? false) || ! $user->addresses()->exists();   // the first address is always the default
            unset($data['is_default']);

            $address = $user->addresses()->create($data + ['is_default' => false]);
            if ($makeDefault) {
                $this->promote($user, $address);
            }

            return $address->refresh();
        });
    }

    /** @param array<string,mixed> $data */
    public function update(User $user, Address $address, array $data): Address
    {
        return DB::transaction(function () use ($user, $address, $data) {
            $this->lock($user);
            $address = $user->addresses()->findOrFail($address->id);

            $makeDefault = (bool) ($data['is_default'] ?? false);
            unset($data['is_default']);          // is_default=false is ignored: to move the default, set ANOTHER address as default

            $address->fill($data)->save();
            if ($makeDefault) {
                $this->promote($user, $address);
            }

            return $address->refresh();
        });
    }

    public function setDefault(User $user, Address $address): Address
    {
        return DB::transaction(function () use ($user, $address) {
            $this->lock($user);
            $address = $user->addresses()->findOrFail($address->id);
            $this->promote($user, $address);

            return $address->refresh();
        });
    }

    public function delete(User $user, Address $address): void
    {
        DB::transaction(function () use ($user, $address) {
            $this->lock($user);
            $address = $user->addresses()->findOrFail($address->id);
            $wasDefault = $address->is_default;
            $address->delete();

            if ($wasDefault) {                  // keep the "one default" invariant: promote the most recently added remaining address
                $next = $user->addresses()->orderByDesc('id')->first();
                if ($next) {
                    $this->promote($user, $next);
                }
            }
        });
    }

    private function promote(User $user, Address $address): void
    {
        $user->addresses()->whereKeyNot($address->id)->where('is_default', true)->update(['is_default' => false]);
        $user->addresses()->whereKey($address->id)->update(['is_default' => true]);
    }

    private function lock(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
    }
}
