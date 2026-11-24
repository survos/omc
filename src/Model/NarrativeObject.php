<?php

declare(strict_types=1);

namespace Survos\Omc\Model;

/** OMC 2.8 NarrativeObject; nested values are available through value(). */
final readonly class NarrativeObject extends Entity
{
    public ?string $narrativeType;
    public ?string $size;
    public string|int|float|null $quantity;

    public function __construct(\stdClass $data)
    {
        parent::__construct($data);
        $this->narrativeType = $data->narrativeType ?? null;
        $this->size = $data->size ?? null;
        $this->quantity = $data->quantity ?? null;
    }
}
