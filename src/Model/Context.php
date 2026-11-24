<?php

declare(strict_types=1);

namespace Survos\Omc\Model;

/** OMC 2.8 Context; nested values are available through value(). */
final readonly class Context extends Entity
{
    public ?string $contextType;
    public ?string $contextCategory;

    public function __construct(\stdClass $data)
    {
        parent::__construct($data);
        $this->contextType = $data->contextType ?? null;
        $this->contextCategory = $data->contextCategory ?? null;
    }
}
