<?php

declare(strict_types=1);

namespace Survos\Omc\Model;

/** OMC 2.8 NarrativeLocation; nested values are available through value(). */
final readonly class NarrativeLocation extends Entity
{
    public ?string $narrativeType;

    public function __construct(\stdClass $data)
    {
        parent::__construct($data);
        $this->narrativeType = $data->narrativeType ?? null;
    }
}
