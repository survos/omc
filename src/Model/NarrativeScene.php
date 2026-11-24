<?php

declare(strict_types=1);

namespace Survos\Omc\Model;

/** OMC 2.8 NarrativeScene; nested values are available through value(). */
final readonly class NarrativeScene extends Entity
{
    public ?string $narrativeType;
    public string|int|float|null $sceneNumber;

    public function __construct(\stdClass $data)
    {
        parent::__construct($data);
        $this->narrativeType = $data->narrativeType ?? null;
        $this->sceneNumber = $data->sceneNumber ?? null;
    }
}
