<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Models\ConsistentModel;

/**
 * Lives outside App\Models, like a module model, and has a factory that does not name it.
 */
class Gadget extends ConsistentModel
{
    protected $table = 'gadgets';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';
}
