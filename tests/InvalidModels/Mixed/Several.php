<?php

declare(strict_types=1);

namespace Tests\InvalidModels\Mixed;

use Adonyarik\ConsistentApi\Models\ConsistentModel;

/**
 * An interface declared before the model in the same file must not hide the model.
 */
interface SeveralContract {}

class Several extends ConsistentModel implements SeveralContract {}
