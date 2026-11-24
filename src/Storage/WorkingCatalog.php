<?php

declare(strict_types=1);

namespace Survos\Omc\Storage;

use Survos\Omc\Catalog;
use Survos\Omc\Model\Identifier;
use Survos\Omc\Schema;

/** Editable relational working copy for the rph.* /1 OMC extension profile. */
final class WorkingCatalog
{
    private \PDO $db;
    private bool $editing = false;
    private array $changes = [];
    private const int APPLICATION_ID = 0x4F4D4357;
    public const int FORMAT_VERSION = 1;

    public function __construct(string $path)
    {
        $this->db = new \PDO('sqlite:'.$path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');
        $app = (int) $this->db->query('PRAGMA application_id')->fetchColumn();
        $version = (int) $this->db->query('PRAGMA user_version')->fetchColumn();
        if (0 === $app && 0 === $version && 0 === (int) $this->db->query("SELECT count(*) FROM sqlite_master WHERE name NOT LIKE 'sqlite_%'")->fetchColumn()) {
            $this->db->beginTransaction();
            try {
                $this->db->exec(file_get_contents(__DIR__.'/working-catalog.sql'));
                $this->db->exec('PRAGMA application_id='.self::APPLICATION_ID.'; PRAGMA user_version='.self::FORMAT_VERSION);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        } elseif (self::APPLICATION_ID !== $app || self::FORMAT_VERSION !== $version) {
            throw new \RuntimeException('Unsupported OMC working database. Snapshot databases must be imported explicitly.');
        }
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function decode(string $json): mixed
    {
        return json_decode($json, flags: JSON_THROW_ON_ERROR);
    }

    private function insert(string $table, array $values): void
    {
        $this->db->prepare('INSERT INTO '.$table.' ('.implode(',', array_keys($values)).') VALUES ('.implode(',', array_fill(0, count($values), '?')).')')->execute(array_values($values));
    }

    private function query(string $sql, array $args = []): array
    {
        $s = $this->db->prepare($sql);
        $s->execute($args);

        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** One-time migration of named flat OMC entity arrays. Source bytes become provenance only. */
    public function importDocuments(array $documents, string $actor = 'import'): void
    {
        if (!$documents) {
            throw new \InvalidArgumentException('No documents supplied.');
        }
        $this->revise(0, $actor, function () use ($documents): void {
            if ($this->query('SELECT id FROM wc_source')) {
                throw new \LogicException('Working catalogs cannot be overwritten; create a new database for another migration.');
            }
            $all = [];
            foreach ($documents as $name => $json) {
                $data = self::decode($json);
                if (!is_string($name) || !is_array($data)) {
                    throw new \InvalidArgumentException('Expected named OMC entity arrays.');
                }
                new Catalog($json);
                $this->insert('wc_source', ['id' => $name, 'ordinal' => count($this->query('SELECT id FROM wc_source')), 'original_json' => $json, 'sha256' => hash('sha256', $json), 'schema_version' => Schema::VERSION, 'schema_commit' => Schema::UPSTREAM_COMMIT]);
                foreach ($data as $ordinal => $entity) {
                    if (!$entity instanceof \stdClass || !isset($entity->entityType, $entity->identifier)) {
                        throw new \InvalidArgumentException('Only flat entity arrays are supported.');
                    }
                    $key = Identifier::fromObject($entity->identifier[0])->key();
                    $base = clone $entity;
                    unset($base->name, $base->sceneNumber, $base->customData);
                    $this->insert('wc_entity', ['id' => $key, 'source_id' => $name, 'ordinal' => $ordinal, 'entity_type' => $entity->entityType, 'name' => $entity->name ?? null, 'display_number' => $entity->sceneNumber ?? null, 'shape_json' => self::json(array_keys(get_object_vars($entity))), 'extra_json' => self::json($base), 'custom_data_json' => self::json($entity->customData ?? null)]);
                    foreach ($entity->identifier as $i => $id) {
                        $this->insert('wc_identifier', ['scope' => $id->identifierScope, 'value' => $id->identifierValue, 'entity_id' => $key, 'ordinal' => $i]);
                    }
                    $all[$key] = $entity;
                }
            }
            // Validate the combined catalog before resolving references across files.
            new Catalog(self::json(array_values($all)));
            foreach ($all as $key => $entity) {
                foreach ($entity->customData ?? [] as $index => $extension) {
                    if (($extension->domain ?? null) !== 'rph') {
                        continue;
                    }
                    $namespace = $extension->namespace ?? '';
                    if (!in_array($namespace, ['rph.scene/1', 'rph.edit/1', 'rph.shot/1', 'rph.media/1'], true)) {
                        continue;
                    }
                    $value = $extension->value;
                    if (!$value instanceof \stdClass) {
                        throw new \InvalidArgumentException('Expected object extension value.');
                    }
                    $extensionId = hash('sha256', $key.':'.$index);
                    $remaining = clone $value;
                    if ('rph.scene/1' === $namespace) {
                        foreach ($value->elements ?? [] as $ordinal => $element) {
                            $extra = clone $element;
                            foreach (['id', 'type', 'text', 'sequence', 'speaker', 'direction'] as $field) {
                                unset($extra->$field);
                            }
                            $this->insert('wc_element', ['id' => hash('sha256', $key.':'.$element->id), 'extension_id' => $extensionId, 'scene_id' => $key, 'local_id' => $element->id, 'ordinal' => $ordinal, 'kind' => $element->type, 'text' => $element->text, 'sequence' => $element->sequence, 'speaker_id' => isset($element->speaker) ? $this->resolve($element->speaker) : null, 'speaker_json' => self::json($element->speaker ?? null), 'direction_json' => self::json($element->direction ?? null), 'shape_json' => self::json(array_keys(get_object_vars($element))), 'extra_json' => self::json($extra)]);
                        }
                        unset($remaining->elements);
                    } elseif ('rph.edit/1' === $namespace) {
                        foreach (['fps', 'width', 'height'] as $field) {
                            if (!is_int($value->$field ?? null)) {
                                throw new \InvalidArgumentException('Edit dimensions and fps must be integers.');
                            }
                        }
                        foreach ($value->tracks as $ordinal => $track) {
                            if (!is_int($track->durationFrames ?? null)) {
                                throw new \InvalidArgumentException('Track duration must be integer frames.');
                            }
                            $trackId = hash('sha256', $extensionId.':track:'.$ordinal);
                            $extra = clone $track;
                            unset($extra->name, $extra->kind, $extra->durationFrames, $extra->clips);
                            $this->insert('wc_track', ['id' => $trackId, 'extension_id' => $extensionId, 'ordinal' => $ordinal, 'name' => $track->name, 'kind' => $track->kind, 'duration_frames' => $track->durationFrames, 'extra_json' => self::json($extra)]);
                            foreach ($track->clips as $position => $clip) {
                                foreach (['startFrame', 'durationFrames', 'sourceStartFrame'] as $field) {
                                    if (!is_int($clip->$field ?? null)) {
                                        throw new \InvalidArgumentException('Clip timing must be integer frames.');
                                    }
                                }
                                $extra = clone $clip;
                                foreach (['asset', 'name', 'startFrame', 'durationFrames', 'sourceStartFrame', 'presentation'] as $field) {
                                    unset($extra->$field);
                                }
                                $this->insert('wc_clip', ['id' => hash('sha256', $trackId.':clip:'.$position), 'track_id' => $trackId, 'ordinal' => $position, 'asset_id' => $this->resolve($clip->asset), 'asset_ref_json' => self::json($clip->asset), 'name' => $clip->name, 'start_frame' => $clip->startFrame, 'duration_frames' => $clip->durationFrames, 'source_start_frame' => $clip->sourceStartFrame, 'presentation_json' => self::json($clip->presentation), 'extra_json' => self::json($extra)]);
                            }
                        }
                        $this->insert('wc_edit', ['extension_id' => $extensionId, 'fps' => $value->fps, 'width' => $value->width, 'height' => $value->height]);
                        unset($remaining->tracks, $remaining->fps, $remaining->width, $remaining->height);
                    } elseif ('rph.shot/1' === $namespace) {
                        $this->insert('wc_shot', ['entity_id' => $key, 'extension_id' => $extensionId, 'scene_id' => $this->resolve($value->scene), 'scene_ref_json' => self::json($value->scene), 'element_local_id' => $value->elementId ?? null, 'sequence' => $value->sequence ?? null, 'framing' => $value->framing ?? null, 'start_frame' => $value->startFrame ?? null, 'duration_frames' => $value->durationFrames ?? null, 'selected_asset_id' => isset($value->media) ? $this->resolve($value->media) : null, 'media_ref_json' => self::json($value->media ?? null)]);
                        foreach (['scene', 'elementId', 'sequence', 'framing', 'startFrame', 'durationFrames', 'media'] as $field) {
                            unset($remaining->$field);
                        }
                    } else {
                        $versionId = hash('sha256', $key.':version:1');
                        $this->insert('wc_media', ['entity_id' => $key, 'extension_id' => $extensionId, 'selected_version_id' => $versionId]);
                        $this->insert('wc_asset_version', ['id' => $versionId, 'asset_id' => $key, 'version_number' => 1, 'url' => $value->url, 'kind' => $value->kind, 'provenance_json' => self::json((object) ['imported' => true, 'extras' => $value->extras ?? null, 'renderer' => $value->renderer ?? null]), 'created_revision' => 1]);
                        unset($remaining->url, $remaining->kind);
                    }
                    $this->insert('wc_extension', ['id' => $extensionId, 'entity_id' => $key, 'ordinal' => $index, 'namespace' => $namespace, 'shape_json' => self::json(array_keys(get_object_vars($value))), 'extra_json' => self::json($remaining)]);
                }
            }
            // Remove normalized extension values from entity residuals: there is only one working authority.
            foreach ($this->query('SELECT id, custom_data_json FROM wc_entity') as $row) {
                $custom = self::decode($row['custom_data_json']);
                foreach ($this->query('SELECT ordinal FROM wc_extension WHERE entity_id=?', [$row['id']]) as $ext) {
                    unset($custom[$ext['ordinal']]->value);
                }
                $this->db->prepare('UPDATE wc_entity SET custom_data_json=? WHERE id=?')->execute([self::json($custom), $row['id']]);
            }
            $this->changes[] = ['operation' => 'import', 'documents' => array_keys($documents)];
        });
    }

    private function resolve(\stdClass $reference): string
    {
        $keys = [];
        foreach ($reference->identifier ?? [] as $id) {
            $row = $this->query('SELECT entity_id FROM wc_identifier WHERE scope=? AND value=?', [$id->identifierScope, $id->identifierValue]);
            if (!$row) {
                throw new \InvalidArgumentException('Unresolved working reference: '.$id->identifierScope.':'.$id->identifierValue);
            }
            $keys[] = $row[0]['entity_id'];
        }
        if (1 !== count(array_unique($keys))) {
            throw new \InvalidArgumentException('Empty or conflicting reference identifiers.');
        }

        return $keys[0];
    }

    public function revision(): int
    {
        return (int) $this->db->query('SELECT coalesce(max(id),0) FROM wc_revision')->fetchColumn();
    }

    /** Optimistic concurrency boundary for one MCP/domain action or a batch. */
    public function revise(int $expectedRevision, string $actor, callable $operation): int
    {
        if ($this->editing) {
            throw new \LogicException('Nested revisions are not supported.');
        }
        if ('' === trim($actor)) {
            throw new \InvalidArgumentException('Revision actor is required.');
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if ($this->revision() !== $expectedRevision) {
                throw new \RuntimeException('Stale catalog revision. Reload before editing.');
            }
            $this->editing = true;
            $this->changes = [];
            $operation($this);
            $next = $expectedRevision + 1;
            $this->insert('wc_revision', ['id' => $next, 'actor' => $actor, 'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'changes_json' => self::json($this->changes)]);
            $this->validate();
            $this->db->exec('COMMIT');

            return $next;
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        } finally {
            $this->editing = false;
            $this->changes = [];
        }
    }

    private function change(string $table, string $id, array $values, string $key = 'id'): void
    {
        if (!$this->editing) {
            throw new \LogicException('Mutations require revise().');
        }
        $row = $this->query('SELECT * FROM '.$table.' WHERE '.$key.'=?', [$id]);
        if (!$row) {
            throw new \InvalidArgumentException('Unknown row: '.$id);
        }
        $sets = array_map(static fn ($key) => $key.'=?', array_keys($values));
        $this->db->prepare('UPDATE '.$table.' SET '.implode(',', $sets).' WHERE '.$key.'=?')->execute([...array_values($values), $id]);
        $this->changes[] = ['table' => $table, 'id' => $id, 'before' => array_intersect_key($row[0], $values), 'after' => $values];
    }

    public function updateElement(string $id, string $text, mixed $direction): void
    {
        $this->change('wc_element', $id, ['text' => $text, 'direction_json' => self::json($direction)]);
    }

    public function renameEntity(string $id, string $name): void
    {
        $row = $this->query('SELECT shape_json FROM wc_entity WHERE id=?', [$id]);
        if (!$row) {
            throw new \InvalidArgumentException('Unknown entity.');
        }
        $shape = self::decode($row[0]['shape_json']);
        if (!in_array('name', $shape, true)) {
            $shape[] = 'name';
        }
        $this->change('wc_entity', $id, ['name' => $name, 'shape_json' => self::json($shape)]);
    }

    /** Native OMC payload edits, validated against the pinned schema on commit. */
    public function setEntityField(string $id, string $field, mixed $value): void
    {
        if (in_array($field, ['identifier', 'entityType', 'schemaVersion', 'customData', 'name', 'sceneNumber'], true)) {
            throw new \InvalidArgumentException('Use the dedicated identity, name, scene or extension operation.');
        }
        $row = $this->query('SELECT extra_json FROM wc_entity WHERE id=?', [$id]);
        if (!$row) {
            throw new \InvalidArgumentException('Unknown entity.');
        }
        $extra = self::decode($row[0]['extra_json']);
        $extra->$field = $value;
        $this->change('wc_entity', $id, ['extra_json' => self::json($extra)]);
    }

    public function renumberScene(string $id, string $displayNumber): void
    {
        $row = $this->query("SELECT shape_json FROM wc_entity WHERE id=? AND entity_type='NarrativeScene'", [$id]);
        if (!$row) {
            throw new \InvalidArgumentException('Unknown scene.');
        }
        $shape = self::decode($row[0]['shape_json']);
        if (!in_array('sceneNumber', $shape, true)) {
            $shape[] = 'sceneNumber';
        }
        $this->change('wc_entity', $id, ['display_number' => $displayNumber, 'shape_json' => self::json($shape)]);
    }

    public function retimeClip(string $id, int $startFrame, int $durationFrames, int $sourceStartFrame): void
    {
        $this->change('wc_clip', $id, ['start_frame' => $startFrame, 'duration_frames' => $durationFrames, 'source_start_frame' => $sourceStartFrame]);
    }

    /** A clip's selected media is explicit; changing it never changes sibling clips. */
    public function selectClipAsset(string $clipId, string $assetId): void
    {
        if (!$this->query('SELECT entity_id FROM wc_media WHERE entity_id=?', [$assetId])) {
            throw new \InvalidArgumentException('Selection must be a playable media asset.');
        }
        $ids = $this->query('SELECT scope, value FROM wc_identifier WHERE entity_id=? ORDER BY ordinal', [$assetId]);
        $reference = (object) ['identifier' => array_map(static fn ($id) => (object) ['identifierScope' => $id['scope'], 'identifierValue' => $id['value']], $ids)];
        $this->change('wc_clip', $clipId, ['asset_id' => $assetId, 'asset_ref_json' => self::json($reference)]);
    }

    /** Register an immutable candidate. Selection is a separate explicit operation. */
    public function addAssetVersion(string $assetId, string $url, string $kind, \stdClass $provenance): string
    {
        if (!$this->editing) {
            throw new \LogicException('Mutations require revise().');
        }
        if (!$this->query('SELECT entity_id FROM wc_media WHERE entity_id=?', [$assetId])) {
            throw new \InvalidArgumentException('Unknown media asset.');
        }
        if ('' === trim($url)) {
            throw new \InvalidArgumentException('Media URL cannot be empty.');
        }
        $number = (int) $this->query('SELECT coalesce(max(version_number),0)+1 AS n FROM wc_asset_version WHERE asset_id=?', [$assetId])[0]['n'];
        $id = bin2hex(random_bytes(16));
        $this->insert('wc_asset_version', ['id' => $id, 'asset_id' => $assetId, 'version_number' => $number, 'url' => $url, 'kind' => $kind, 'provenance_json' => self::json($provenance), 'created_revision' => $this->revision() + 1]);
        $this->changes[] = ['operation' => 'addAssetVersion', 'asset_id' => $assetId, 'version_id' => $id];

        return $id;
    }

    public function selectAssetVersion(string $assetId, string $versionId): void
    {
        if (!$this->query('SELECT id FROM wc_asset_version WHERE id=? AND asset_id=?', [$versionId, $assetId])) {
            throw new \InvalidArgumentException('Version belongs to another asset or does not exist.');
        }
        $this->change('wc_media', $assetId, ['selected_version_id' => $versionId], 'entity_id');
    }

    public function updateClipName(string $id, string $name): void
    {
        $this->change('wc_clip', $id, ['name' => $name]);
    }

    /** Selected take metadata only; URL/kind selection remains versioned separately. */
    public function setMediaMetadata(string $assetId, string $field, mixed $value): void
    {
        if (!in_array($field, ['speech', 'durationSeconds', 'credit', 'poster', 'renderer'], true)) {
            throw new \InvalidArgumentException('Unsupported media metadata field.');
        }
        $rows = $this->query('SELECT e.* FROM wc_extension e JOIN wc_media m ON m.extension_id=e.id WHERE m.entity_id=?', [$assetId]);
        if (!$rows) {
            throw new \InvalidArgumentException('Unknown media.');
        }
        $extra = self::decode($rows[0]['extra_json']);
        $extra->$field = $value;
        $this->change('wc_extension', $rows[0]['id'], ['extra_json' => self::json($extra)]);
    }

    /** Replace the renderer-specific recipe, preserving relational shot fields. */
    public function setShotRecipe(string $shotId, string $field, mixed $recipe): void
    {
        if (!in_array($field, ['storyboard', 'motionLayers', 'sourceMedia', 'castBindings', 'generation', 'promptTemplate', 'motion'], true)) {
            throw new \InvalidArgumentException('Unknown recipe field.');
        }
        $rows = $this->query('SELECT e.* FROM wc_extension e JOIN wc_shot s ON s.extension_id=e.id WHERE s.entity_id=?', [$shotId]);
        if (!$rows) {
            throw new \InvalidArgumentException('Unknown shot.');
        }
        $extra = self::decode($rows[0]['extra_json']);
        $extra->$field = $recipe;
        $this->change('wc_extension', $rows[0]['id'], ['extra_json' => self::json($extra)]);
    }

    /** Read normalized rows without granting callers arbitrary SQL mutation access. */
    public function rows(string $kind): array
    {
        if (!in_array($kind, ['entity', 'identifier', 'element', 'extension', 'edit', 'track', 'clip', 'shot', 'media', 'asset_version', 'reference', 'revision', 'source'], true)) {
            throw new \InvalidArgumentException('Unknown working table.');
        }

        return $this->query('SELECT * FROM wc_'.$kind);
    }

    /** Reconstruct from current relational values, never from wc_source.original_json. */
    public function exportDocuments(): array
    {
        if ($this->editing) {
            return $this->buildDocuments();
        }
        $this->db->beginTransaction();
        try {
            $documents = $this->buildDocuments();
            $this->db->commit();

            return $documents;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function buildDocuments(): array
    {
        $documents = [];
        foreach ($this->query('SELECT id FROM wc_source ORDER BY ordinal') as $source) {
            $entities = [];
            foreach ($this->query('SELECT * FROM wc_entity WHERE source_id=? ORDER BY ordinal', [$source['id']]) as $row) {
                $entity = self::decode($row['extra_json']);
                $shape = self::decode($row['shape_json']);
                if (in_array('name', $shape, true)) {
                    $entity->name = $row['name'];
                }
                if (in_array('sceneNumber', $shape, true)) {
                    $entity->sceneNumber = $row['display_number'];
                }
                if (in_array('customData', $shape, true)) {
                    $entity->customData = self::decode($row['custom_data_json']);
                }
                foreach ($this->query('SELECT * FROM wc_extension WHERE entity_id=? ORDER BY ordinal', [$row['id']]) as $ext) {
                    $entity->customData[$ext['ordinal']]->value = $this->extensionValue($ext);
                }
                $entities[] = $entity;
            }
            $documents[$source['id']] = self::json($entities);
        }

        return $documents;
    }

    private function overlay(array $row, array $fields): \stdClass
    {
        $value = self::decode($row['extra_json']);
        $shape = isset($row['shape_json']) ? self::decode($row['shape_json']) : array_keys($fields);
        foreach ($fields as $key => $field) {
            if (in_array($key, $shape, true)) {
                $value->$key = $field;
            }
        }

        return $value;
    }

    private function extensionValue(array $ext): \stdClass
    {
        $id = $ext['id'];
        $fields = [];
        if ('rph.scene/1' === $ext['namespace']) {
            $fields['elements'] = array_map(fn ($r) => $this->overlay($r, ['id' => $r['local_id'], 'type' => $r['kind'], 'text' => $r['text'], 'sequence' => $r['sequence'], 'speaker' => self::decode($r['speaker_json']), 'direction' => self::decode($r['direction_json'])]), $this->query('SELECT * FROM wc_element WHERE extension_id=? ORDER BY ordinal', [$id]));
        } elseif ('rph.edit/1' === $ext['namespace']) {
            $edit = $this->query('SELECT * FROM wc_edit WHERE extension_id=?', [$id])[0];
            $fields = ['fps' => $edit['fps'], 'width' => $edit['width'], 'height' => $edit['height'], 'tracks' => []];
            foreach ($this->query('SELECT * FROM wc_track WHERE extension_id=? ORDER BY ordinal', [$id]) as $track) {
                $clips = array_map(fn ($r) => $this->overlay($r, ['asset' => self::decode($r['asset_ref_json']), 'name' => $r['name'], 'startFrame' => $r['start_frame'], 'durationFrames' => $r['duration_frames'], 'sourceStartFrame' => $r['source_start_frame'], 'presentation' => self::decode($r['presentation_json'])]), $this->query('SELECT * FROM wc_clip WHERE track_id=? ORDER BY ordinal', [$track['id']]));
                $fields['tracks'][] = $this->overlay($track, ['name' => $track['name'], 'kind' => $track['kind'], 'durationFrames' => $track['duration_frames'], 'clips' => $clips]);
            }
        } elseif ('rph.shot/1' === $ext['namespace']) {
            $r = $this->query('SELECT * FROM wc_shot WHERE extension_id=?', [$id])[0];
            $fields = ['scene' => self::decode($r['scene_ref_json']), 'elementId' => $r['element_local_id'], 'sequence' => $r['sequence'], 'framing' => $r['framing'], 'startFrame' => $r['start_frame'], 'durationFrames' => $r['duration_frames'], 'media' => self::decode($r['media_ref_json'])];
        } elseif ('rph.media/1' === $ext['namespace']) {
            $r = $this->query('SELECT v.* FROM wc_media m JOIN wc_asset_version v ON v.id=m.selected_version_id WHERE m.extension_id=?', [$id])[0];
            $fields = ['url' => $r['url'], 'kind' => $r['kind']];
        }

        return $this->overlay($ext, $fields);
    }

    /** Application read view; no filesystem reads after migration. */
    public function entities(?string $type = null): array
    {
        $entities = [];
        foreach ($this->exportDocuments() as $json) {
            foreach (self::decode($json) as $entity) {
                if (null === $type || $entity->entityType === $type) {
                    $entities[] = $entity;
                }
            }
        }

        return $entities;
    }

    private function validate(): void
    {
        $entities = $this->entities();
        new Catalog(self::json($entities));
        $this->db->exec('DELETE FROM wc_reference');
        foreach ($entities as $entity) {
            $source = $this->resolve($entity);
            $walk = function (mixed $node, string $path, bool $root = false) use (&$walk, $source): void {
                if (!$root && $node instanceof \stdClass && isset($node->identifier)) {
                    if (isset($node->entityType)) {
                        throw new \InvalidArgumentException('Working import requires flat entities; embedded entity definitions must be flattened explicitly.');
                    }
                    $target = $this->resolve($node);
                    $this->insert('wc_reference', ['source_id' => $source, 'path' => $path, 'target_id' => $target, 'reference_json' => self::json($node)]);

                    return;
                }
                if (is_object($node) || is_array($node)) {
                    foreach ($node as $k => $v) {
                        if ('identifier' === $k || 'extras' === $k) {
                            continue;
                        }
                        // Only RPH extension values have a reference contract here; other customData is opaque.
                        if ('customData' === $k) {
                            foreach ($v ?? [] as $index => $custom) {
                                if (($custom->domain ?? null) === 'rph') {
                                    $walk($custom->value, $path.'/customData/'.$index.'/value');
                                }
                            }
                            continue;
                        }
                        $walk($v, $path.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $k));
                    }
                }
            };
            $walk($entity, '', true);
        }
        foreach ($this->query('SELECT * FROM wc_track') as $track) {
            $cursor = 0;
            foreach ($this->query('SELECT * FROM wc_clip WHERE track_id=? ORDER BY ordinal', [$track['id']]) as $clip) {
                if ($clip['start_frame'] < $cursor || $clip['start_frame'] + $clip['duration_frames'] > $track['duration_frames']) {
                    throw new \InvalidArgumentException('Overlapping clips or clip beyond track duration.');
                }
                if (!$this->query('SELECT entity_id FROM wc_media WHERE entity_id=?', [$clip['asset_id']])) {
                    throw new \InvalidArgumentException('Clip does not reference playable media.');
                }
                $cursor = $clip['start_frame'] + $clip['duration_frames'];
            }
        }
        foreach ($this->query("SELECT e.id FROM wc_element e JOIN wc_entity s ON s.id=e.scene_id LEFT JOIN wc_entity c ON c.id=e.speaker_id WHERE s.entity_type!='NarrativeScene' OR (e.speaker_id IS NOT NULL AND c.entity_type!='Character')") as $invalid) {
            throw new \InvalidArgumentException('Elements require a narrative scene and Character speaker.');
        }
        foreach ($this->query("SELECT s.entity_id FROM wc_shot s JOIN wc_entity e ON e.id=s.scene_id WHERE e.entity_type!='NarrativeScene'") as $invalid) {
            throw new \InvalidArgumentException('Shot requires a narrative scene.');
        }
        foreach ($this->query('SELECT * FROM wc_shot WHERE element_local_id IS NOT NULL') as $shot) {
            if (!$this->query('SELECT id FROM wc_element WHERE scene_id=? AND local_id=?', [$shot['scene_id'], $shot['element_local_id']])) {
                throw new \InvalidArgumentException('Shot references an unknown scene element.');
            }
        }
        if ($this->query('PRAGMA foreign_key_check')) {
            throw new \InvalidArgumentException('Working catalog foreign key violation.');
        }
    }
}
