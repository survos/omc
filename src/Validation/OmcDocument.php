<?php

declare(strict_types=1);

namespace Survos\Omc\Validation;

use Symfony\Component\Validator\Constraint;

/** Validate the DTO against the pinned MovieLabs schema. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class OmcDocument extends Constraint
{
    #[\Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
