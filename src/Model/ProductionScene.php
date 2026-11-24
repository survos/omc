<?php

declare(strict_types=1);

namespace Survos\Omc\Model;

/** OMC 2.8 ProductionScene; nested values are available through value(). */
final readonly class ProductionScene extends Entity
{
    public ?string $sceneHeader;
    public ?string $sceneDescriptor;
    public ?string $sceneNumber;

    public function __construct(\stdClass $data)
    {
        parent::__construct($data);
        $this->sceneHeader = $data->sceneHeader ?? null;
        $this->sceneDescriptor = $data->sceneDescriptor ?? null;
        $this->sceneNumber = $data->sceneNumber ?? null;
    }
}
