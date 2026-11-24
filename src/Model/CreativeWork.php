<?php

declare(strict_types=1);

namespace Survos\Omc\Model;

/** OMC 2.8 CreativeWork; nested values are available through value(). */
final readonly class CreativeWork extends Entity
{
    public ?string $creativeWorkType;
    public ?string $creativeWorkCategory;
    public string|int|float|null $seasonNumber;

    public function __construct(\stdClass $data)
    {
        parent::__construct($data);
        $this->creativeWorkType = $data->creativeWorkType ?? null;
        $this->creativeWorkCategory = $data->creativeWorkCategory ?? null;
        $this->seasonNumber = $data->seasonNumber ?? null;
    }
}
