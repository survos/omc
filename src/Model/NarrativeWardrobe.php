<?php

declare(strict_types=1);

namespace Survos\Omc\Model;

/** OMC 2.8 NarrativeWardrobe; nested values are available through value(). */
final readonly class NarrativeWardrobe extends Entity
{
    public ?string $narrativeType;

    public function __construct(\stdClass $data)
    {
        parent::__construct($data);
        $this->narrativeType = $data->narrativeType ?? null;
    }
}
