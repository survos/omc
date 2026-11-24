<?php

declare(strict_types=1);

namespace Survos\Omc\Storage;

use Survos\Omc\Catalog;
use Survos\Omc\Schema;

/** One portable, replaceable catalog snapshot. OMC JSON remains authoritative. */
final class SqliteCatalog
{
    public const int FORMAT_VERSION = 1;
    private const int APPLICATION_ID = 0x4F4D4301;
    private \PDO $db;

    public function __construct(string $path)
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException('OMC SQLite storage requires ext-pdo_sqlite.');
        }
        $this->db = new \PDO('sqlite:'.$path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout = 5000');
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->db->beginTransaction();
        try {
            $app = (int) $this->db->query('PRAGMA application_id')->fetchColumn();
            $version = (int) $this->db->query('PRAGMA user_version')->fetchColumn();
            $tables = (int) $this->db->query("SELECT count(*) FROM sqlite_master WHERE name NOT LIKE 'sqlite_%'")->fetchColumn();
            if (0 === $app && 0 === $version && 0 === $tables) {
                $this->initialize();
            } elseif (self::APPLICATION_ID !== $app || self::FORMAT_VERSION !== $version) {
                throw new \RuntimeException('Not a supported OMC SQLite snapshot.');
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Validate first, then atomically replace source and all derived indexes. */
    public function importJson(string $json): void
    {
        $catalog = new Catalog($json);
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM omc_relationship; DELETE FROM omc_identifier; DELETE FROM omc_entity; DELETE FROM omc_catalog');
            $this->db->prepare('INSERT INTO omc_catalog VALUES (1, ?, ?, ?, ?, ?)')->execute([
                $json, hash('sha256', $json), Schema::VERSION, Schema::UPSTREAM_COMMIT, gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            $entityInsert = $this->db->prepare('INSERT INTO omc_entity VALUES (?, ?, ?, ?)');
            $idInsert = $this->db->prepare('INSERT INTO omc_identifier VALUES (?, ?, ?, ?)');
            foreach ($catalog->entities() as $ordinal => $entity) {
                $entityInsert->execute([$entity->key(), $ordinal, $entity->entityType, json_encode($entity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
                foreach ($entity->identifier as $id) {
                    $idInsert->execute([$id->identifierScope, $id->identifierValue, $entity->key(), $id->key()]);
                }
            }
            $edgeInsert = $this->db->prepare('INSERT INTO omc_relationship VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($catalog->relationships() as $ordinal => $edge) {
                $edgeInsert->execute([$ordinal, $edge['source'], $edge['path'], $edge['target']->identifierScope, $edge['target']->identifierValue, $edge['resolved'], null === $edge['resolved'] ? 'unresolved' : 'resolved']);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Returns the exact imported bytes, including whitespace and unknown custom data. */
    public function exportJson(): string
    {
        $row = $this->db->query('SELECT source_json, source_sha256 FROM omc_catalog WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
        if (false === $row) {
            throw new \LogicException('No catalog has been imported.');
        }
        if (!hash_equals($row['source_sha256'], hash('sha256', $row['source_json']))) {
            throw new \RuntimeException('OMC snapshot source checksum mismatch.');
        }

        return $row['source_json'];
    }

    public function catalog(): Catalog
    {
        return new Catalog($this->exportJson());
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        $row = $this->db->query('SELECT source_sha256, schema_version, schema_commit, imported_at FROM omc_catalog WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
        if (false === $row) {
            throw new \LogicException('No catalog has been imported.');
        }

        return ['format_version' => self::FORMAT_VERSION] + $row;
    }

    private function initialize(): void
    {
        $this->db->exec(<<<'SQL'
CREATE TABLE omc_catalog (
    id INTEGER PRIMARY KEY CHECK (id = 1), source_json TEXT NOT NULL,
    source_sha256 TEXT NOT NULL, schema_version TEXT NOT NULL,
    schema_commit TEXT NOT NULL, imported_at TEXT NOT NULL
);
CREATE TABLE omc_entity (
    entity_key TEXT PRIMARY KEY NOT NULL, ordinal INTEGER NOT NULL UNIQUE,
    entity_type TEXT NOT NULL, entity_json TEXT NOT NULL
);
CREATE INDEX omc_entity_type ON omc_entity(entity_type);
CREATE TABLE omc_identifier (
    identifier_scope TEXT NOT NULL, identifier_value TEXT NOT NULL,
    entity_key TEXT NOT NULL REFERENCES omc_entity(entity_key),
    identifier_key TEXT NOT NULL UNIQUE,
    PRIMARY KEY (identifier_scope, identifier_value)
);
CREATE TABLE omc_relationship (
    ordinal INTEGER PRIMARY KEY, source_entity_key TEXT NOT NULL REFERENCES omc_entity(entity_key),
    reference_path TEXT NOT NULL, target_scope TEXT NOT NULL, target_value TEXT NOT NULL,
    resolved_entity_key TEXT REFERENCES omc_entity(entity_key),
    resolution TEXT NOT NULL CHECK (
        (resolution = 'resolved' AND resolved_entity_key IS NOT NULL) OR
        (resolution = 'unresolved' AND resolved_entity_key IS NULL)
    )
);
CREATE INDEX omc_relationship_source ON omc_relationship(source_entity_key);
CREATE INDEX omc_relationship_target ON omc_relationship(target_scope, target_value);
SQL);
        $this->db->exec('PRAGMA application_id = '.self::APPLICATION_ID);
        $this->db->exec('PRAGMA user_version = '.self::FORMAT_VERSION);
    }
}
