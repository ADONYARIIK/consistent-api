<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Traits;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Checks that a model belongs to another one through its foreign key.
 *
 * Meant for nested routes such as posts/{post}/comments/{comment}: $comment->checkModelCredibility($post,
 * 'post_id') answers 404 when the comment belongs to another post. The trait reads attributes with
 * getAttribute(), so the class using it must be an Eloquent model.
 *
 * @package Adonyarik\ConsistentApi\Traits
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
trait Credibility
{
    /**
     * Tells whether this model's foreign key points to the given model.
     *
     * Compares the attribute with the given model's primary key (getKey()), or with $ownerKey when the
     * foreign key references another column, as strings, so integer and string keys match. A null or
     * empty foreign key is never credible, and neither is a model without a key.
     *
     * @param  Model  $model  Model this one should belong to
     * @param  string  $idAttributeName  Attribute of this model holding the other model's key, such as post_id
     * @param  string|null  $ownerKey  Attribute of $model the foreign key references; null for its primary key
     *
     * @return bool True when the foreign key equals the model's key
     */
    public function isCredible(Model $model, string $idAttributeName, ?string $ownerKey = null): bool
    {
        $relationId = $this->getAttribute($idAttributeName);
        $ownerId = $ownerKey === null ? $model->getKey() : $model->getAttribute($ownerKey);

        return $relationId !== null && $relationId !== '' && $ownerId !== null
            && (string) $ownerId === (string) $relationId;
    }

    /**
     * Aborts the request when this model does not belong to the given one.
     *
     * Calls isCredible() and aborts through abort_if(), which Laravel renders as an HTTP error response.
     *
     * @param  Model  $model  Model this one should belong to
     * @param  string  $idAttributeName  Attribute of this model holding the other model's id, such as post_id
     * @param  int  $errorStatus  HTTP status to abort with
     * @param  string  $errorMessage  Message of the error response
     * @param  string|null  $ownerKey  Attribute of $model the foreign key references; null for its primary key
     *
     * @throws HttpException With $errorStatus when the model is not credible
     *
     * @see Credibility::isCredible()
     */
    public function checkModelCredibility(Model $model, string $idAttributeName, int $errorStatus = 404, string $errorMessage = 'The model is not credible.', ?string $ownerKey = null): void
    {
        abort_if(
            ! $this->isCredible($model, $idAttributeName, $ownerKey),
            $errorStatus,
            $errorMessage
        );
    }
}
