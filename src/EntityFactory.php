<?php

declare(strict_types=1);

namespace Survos\Omc;

use Survos\Omc\Model\Entity;

final readonly class EntityFactory
{
    public function __construct(private Schema $schema = new Schema())
    {
    }

    public function fromJson(string $json): Entity
    {
        $data = json_decode($json, flags: JSON_THROW_ON_ERROR);
        $this->schema->assertValid($data);
        if (!$data instanceof \stdClass || !isset($data->entityType)) {
            throw new \InvalidArgumentException('Expected a single OMC entity.');
        }

        return self::hydrate($data);
    }

    /** Hydrate already schema-validated data, including embedded entities. */
    public static function hydrate(\stdClass $data): Entity
    {
        $class = __NAMESPACE__.'\\Model\\'.$data->entityType;

        return class_exists($class) && is_subclass_of($class, Entity::class) ? new $class($data) : new Entity($data);
    }
}
