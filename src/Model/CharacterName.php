<?php

declare(strict_types=1);

namespace Survos\Omc\Model;

/** Common name fields; the enclosing Entity retains the complete upstream value. */
final readonly class CharacterName
{
    public ?string $fullName;
    public ?string $firstGivenName;
    public ?string $scriptName;

    public function __construct(\stdClass $data)
    {
        $this->fullName = $data->fullName ?? null;
        $this->firstGivenName = $data->firstGivenName ?? null;
        $this->scriptName = $data->scriptName ?? null;
    }
}
