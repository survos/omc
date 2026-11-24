<?php

declare(strict_types=1);

namespace Survos\Omc\Model;

/** OMC 2.8 Asset; nested values are available through value(). */
final readonly class Asset extends Entity
{
    public function __construct(\stdClass $data)
    {
        parent::__construct($data);
    }
}
