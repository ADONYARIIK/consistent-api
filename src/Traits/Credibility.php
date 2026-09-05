<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Traits;

use Illuminate\Database\Eloquent\Model;

trait Credibility
{
    public function isCredible(Model $model, string $idAttributeName): bool
    {
        $relationId = $this->getAttribute($idAttributeName);

        return $relationId !== null && $relationId !== ''
            && (string) $model->getAttribute('id') === (string) $relationId;
    }

    public function checkModelCredibility(Model $model, string $idAttributeName, int $errorStatus = 404, string $errorMessage = 'The model is not credible.'): void
    {
        abort_if(
            !$this->isCredible($model, $idAttributeName),
            $errorStatus,
            $errorMessage
        );
    }
}
