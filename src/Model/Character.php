<?php

declare(strict_types=1);

namespace Survos\Omc\Model;

final readonly class Character extends Entity
{
    public ?CharacterName $characterName;
    public ?CharacterProfile $profile;

    public function __construct(\stdClass $data)
    {
        parent::__construct($data);
        $this->characterName = isset($data->characterName) ? new CharacterName($data->characterName) : null;
        $this->profile = isset($data->profile) ? new CharacterProfile($data->profile) : null;
    }
}
