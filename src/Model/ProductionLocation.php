<?php

declare(strict_types=1);

namespace Survos\Omc\Model;

/** OMC 2.8 ProductionLocation; nested values are available through value(). */
final readonly class ProductionLocation extends Entity
{
    public ?string $locationType;

    public function __construct(\stdClass $data)
    {
        parent::__construct($data);
        $this->locationType = $data->locationType ?? null;
    }
}
