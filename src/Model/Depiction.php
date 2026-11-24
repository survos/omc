<?php

declare(strict_types=1);

namespace Survos\Omc\Model;

/** OMC 2.8 Depiction; nested values are available through value(). */
final readonly class Depiction extends Entity
{
    public ?string $depictionType;

    public function __construct(\stdClass $data)
    {
        parent::__construct($data);
        $this->depictionType = $data->depictionType ?? null;
    }
}
