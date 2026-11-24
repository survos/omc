<?php

declare(strict_types=1);

namespace Survos\Omc\Model;

final readonly class CharacterProfile
{
    public ?PhysicalCharacteristics $physicalCharacteristics;
    public ?string $gender;
    public ?string $genderPronoun;
    /** @var list<\stdClass>|null */
    public ?array $background;

    public function __construct(\stdClass $data)
    {
        $this->physicalCharacteristics = isset($data->physicalCharacteristics) ? new PhysicalCharacteristics($data->physicalCharacteristics) : null;
        $this->gender = $data->gender->gender ?? null;
        $this->genderPronoun = $data->gender->genderPronoun ?? null;
        $this->background = isset($data->background) ? json_decode(json_encode($data->background, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR) : null;
    }
}
