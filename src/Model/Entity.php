<?php

declare(strict_types=1);

namespace Survos\Omc\Model;

use Survos\Omc\Validation\OmcDocument;

/** Immutable, lossless OMC value. Null, absent fields and empty objects stay distinct. */
#[OmcDocument]
readonly class Entity implements \JsonSerializable
{
    private string $json;
    public string $entityType;
    /** @var list<Identifier> */
    public array $identifier;
    public ?string $name;
    public ?string $description;

    public function __construct(\stdClass $data)
    {
        $this->json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->entityType = $data->entityType;
        $this->identifier = array_map(Identifier::fromObject(...), $data->identifier);
        $this->name = $data->name ?? null;
        $this->description = $data->description ?? null;
    }

    public function value(string $field): mixed
    {
        return $this->jsonSerialize()->{$field} ?? null;
    }

    public function has(string $field): bool
    {
        return property_exists($this->jsonSerialize(), $field);
    }

    public function jsonSerialize(): \stdClass
    {
        return json_decode($this->json, flags: JSON_THROW_ON_ERROR);
    }

    public function key(): string
    {
        return $this->identifier[0]->key();
    }

    public function label(): string
    {
        return $this->value('characterName')?->scriptName ?? $this->name ?? $this->value('sceneName')?->fullName ?? $this->identifier[0]->identifierValue;
    }
}
