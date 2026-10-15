<?php

namespace App\Services\Concerns;

use App\Support\MobileNumber;
use Illuminate\Database\Eloquent\Builder;

trait MatchesMobileNumbers
{
    /**
     * Mobiles are stored as E.164 (+91...). An admin types "98765 43210": match the typed text as a prefix AND,
     * when it is a valid number, its normalised form exactly (an indexed equality).
     */
    protected function orWhereMobile(Builder $q, string $column, string $term): void
    {
        $q->orWhere($column, 'like', $this->escapeLike($term) . '%');

        if (($normalised = MobileNumber::normalize($term)) !== null) {
            $q->orWhere($column, $normalised);
        }
    }
}
