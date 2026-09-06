<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BaseSearchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'perpage' => ['numeric', Rule::in(array_values(config('pagination.per_page')))],
            'paginate' => ['nullable', Rule::in(['true', 'false', '0', '1'])],
            'sort' => ['array', 'nullable'],
            'sort.*' => ['string', 'in:asc,desc', 'nullable'],
            'filter' => ['array', 'nullable'],
            'filter.*' => ['nullable'],
        ];
    }
}
