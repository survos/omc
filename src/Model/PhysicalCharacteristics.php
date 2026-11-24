<?php

declare(strict_types=1);

namespace Survos\Omc\Model;

final readonly class PhysicalCharacteristics
{
    public ?string $species;
    public ?string $hairColor;
    public ?string $hairLength;
    public ?string $eyeColor;
    // OMC measurement values are preserved, never coerced into guessed units.
    public mixed $height;
    public mixed $weight;

    public function __construct(\stdClass $data)
    {
        $this->species = $data->species ?? null;
        $this->hairColor = $data->hairColor ?? null;
        $this->hairLength = $data->hairLength ?? null;
        $this->eyeColor = $data->eyeColor ?? null;
        $this->height = $data->height ?? null;
        $this->weight = $data->weight ?? null;
    }
}
