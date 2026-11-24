<?php

declare(strict_types=1);

namespace Survos\Omc\Model;

final readonly class Identifier implements \JsonSerializable
{
    public function __construct(public string $identifierScope, public string $identifierValue)
    {
    }

    public static function fromObject(\stdClass $value): self
    {
        return new self($value->identifierScope, $value->identifierValue);
    }

    /** Stable database key; the original scope and value remain separately queryable. */
    public function key(): string
    {
        return hash('sha256', json_encode([$this->identifierScope, $this->identifierValue], JSON_THROW_ON_ERROR));
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
