<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Requests;

use Adonyarik\ConsistentApi\Support\RelationFilter;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BaseSearchRequest extends FormRequest
{
    protected string $model;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->baseRules(), $this->filterValueRules());
    }

    protected function baseRules(): array
    {
        return [
            'perpage' => ['numeric', Rule::in(array_values(config('pagination.per_page')))],
            'paginate' => ['nullable', Rule::in(['true', 'false', '0', '1'])],
            'sort' => ['array', 'nullable'],
            'sort.*' => ['string', 'in:asc,desc', 'nullable'],
            'filter' => ['array', 'nullable'],
            'filter.*' => ['nullable', $this->allowedOperatorsRule()],
        ];
    }

    protected function allowedOperatorsRule(): Closure
    {
        $allowedOperators = config('filters.operators', []);

        return function (string $attribute, mixed $value, Closure $fail) use ($allowedOperators): void {
            if (! is_array($value)) {
                return;
            }

            $invalid = array_diff(array_keys($value), $allowedOperators);

            if (! empty($invalid)) {
                $column = str($attribute)->after('filter.')->toString();

                $fail(sprintf(
                    'The filter operators [%s] for "%s" are not enabled.',
                    implode(', ', $invalid),
                    $column
                ));
            }
        };
    }

    protected function filterValueRules(): array
    {
        if (! isset($this->model) || ! class_exists($this->model)) {
            return [];
        }

        $modelInstance = new $this->model;

        $rules = [];

        foreach ($modelInstance->filterRulesMap() as $column => $operators) {
            if ($operators === null || $operators instanceof RelationFilter) {
                continue;
            }

            $rules["filter.$column"] = ['array', 'nullable'];

            foreach ($operators as $operator => $operatorRules) {
                if (empty($operatorRules)) {
                    continue;
                }

                $rules["filter.$column.$operator"] = $operatorRules;
            }
        }

        return $rules;
    }
}
