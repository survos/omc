<?php

declare(strict_types=1);

namespace Survos\Omc;

use Survos\Omc\Model\Entity;
use Survos\Omc\Model\Identifier;

/** Validated document plus an index of its entities and OMC reference paths. */
final class Catalog implements \JsonSerializable
{
    private string $json;
    /** @var array<string, Entity> */
    private array $entities = [];
    /** @var array<string, string> Identifier key => canonical entity key. */
    private array $aliases = [];
    /** @var list<array{source: string, path: string, target: Identifier, resolved: ?string}> */
    private array $relationships = [];

    public function __construct(string $json, ?Schema $schema = null)
    {
        $data = json_decode($json, flags: JSON_THROW_ON_ERROR);
        ($schema ?? new Schema())->assertValid($data);
        $this->json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->collect($data);
        foreach ($this->entities as $entity) {
            $this->references($entity->jsonSerialize(), $entity->key(), '', true);
        }
    }

    /** @return list<Entity> */
    public function entities(?string $type = null): array
    {
        return array_values(array_filter($this->entities, static fn (Entity $entity) => null === $type || $entity->entityType === $type));
    }

    public function find(Identifier $id): ?Entity
    {
        return $this->entities[$this->aliases[$id->key()] ?? ''] ?? null;
    }

    /** @return list<array{source: string, path: string, target: Identifier, resolved: ?string}> */
    public function relationships(): array
    {
        return $this->relationships;
    }

    public function jsonSerialize(): mixed
    {
        return json_decode($this->json, flags: JSON_THROW_ON_ERROR);
    }

    private function collect(mixed $node): void
    {
        if ($node instanceof \stdClass && isset($node->entityType, $node->identifier)) {
            $entity = EntityFactory::hydrate($node);
            foreach ($entity->identifier as $id) {
                if (isset($this->aliases[$id->key()])) {
                    $previous = $this->entities[$this->aliases[$id->key()]];
                    if ($previous->jsonSerialize() != $entity->jsonSerialize()) {
                        throw new \InvalidArgumentException('Conflicting definitions for '.$id->identifierScope.':'.$id->identifierValue);
                    }

                    return;
                }
            }
            $this->entities[$entity->key()] = $entity;
            foreach ($entity->identifier as $id) {
                $this->aliases[$id->key()] = $entity->key();
            }
        }
        if (is_array($node) || $node instanceof \stdClass) {
            foreach ($node as $key => $value) {
                // These are arbitrary user data, not OMC entities or references.
                if (in_array($key, ['customData', 'identifier'], true)) {
                    continue;
                }
                $this->collect($value);
            }
        }
    }

    private function references(mixed $node, string $source, string $path, bool $root = false): void
    {
        if (!$root && $node instanceof \stdClass && isset($node->identifier)) {
            foreach ($node->identifier as $raw) {
                $id = Identifier::fromObject($raw);
                $this->relationships[] = ['source' => $source, 'path' => $path, 'target' => $id, 'resolved' => $this->aliases[$id->key()] ?? null];
            }

            return; // Embedded entities have their own outgoing edges.
        }
        if (is_array($node) || $node instanceof \stdClass) {
            foreach ($node as $key => $value) {
                if (in_array($key, ['customData', 'identifier'], true)) {
                    continue;
                }
                $escaped = str_replace(['~', '/'], ['~0', '~1'], (string) $key);
                $this->references($value, $source, $path.'/'.$escaped);
            }
        }
    }
}
