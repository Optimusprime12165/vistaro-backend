<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ListRequest;

/** Plain paginated list with no filters (tracking events). */
class SimpleListRequest extends ListRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
