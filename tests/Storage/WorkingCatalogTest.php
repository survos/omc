<?php

declare(strict_types=1);

namespace Survos\Omc\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Survos\Omc\Schema;
use Survos\Omc\Storage\WorkingCatalog;

final class WorkingCatalogTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'omc-working-');
    }

    protected function tearDown(): void
    {
        unlink($this->path);
    }

    private static function ref(string $id): object
    {
        return (object) ['identifier' => [(object) ['identifierScope' => 'test', 'identifierValue' => $id]]];
    }

    private static function entity(string $id, string $type, array $fields = [], ?string $namespace = null, array $value = []): object
    {
        $entity = (object) (['schemaVersion' => Schema::VERSION, 'entityType' => $type, 'identifier' => self::ref($id)->identifier] + $fields);
        if (null !== $namespace) {
            $entity->customData = [(object) ['domain' => 'rph', 'namespace' => $namespace, 'value' => (object) $value]];
        }

        return $entity;
    }

    private function documents(): array
    {
        $character = self::entity('character/a', 'Character', ['characterName' => (object) ['scriptName' => 'A']]);
        $scene = self::entity('scene/a', 'NarrativeScene', ['sceneNumber' => '01'], 'rph.scene/1', [
            'elements' => [(object) ['id' => 'line-a', 'type' => 'dialogue', 'text' => 'Hello', 'sequence' => 1, 'speaker' => self::ref('character/a'), 'direction' => (object) ['delivery' => 'quiet'], 'extras' => (object) ['original' => true]]],
            'extras' => (object) ['staging' => (object) [], 'opaque' => []],
        ]);
        $media = self::entity('media/a', 'Asset', [], 'rph.media/1', ['url' => '/a.png', 'kind' => 'image', 'extras' => (object) ['source' => 'test']]);
        $other = self::entity('media/b', 'Asset', [], 'rph.media/1', ['url' => '/b.png', 'kind' => 'image']);
        $shot = self::entity('shot/a', 'Asset', [], 'rph.shot/1', ['scene' => self::ref('scene/a'), 'elementId' => 'line-a', 'sequence' => 1, 'media' => self::ref('media/a'), 'storyboard' => (object) ['layers' => []]]);
        $edit = self::entity('edit', 'Composition', [], 'rph.edit/1', ['fps' => 30, 'width' => 1280, 'height' => 720, 'tracks' => [(object) ['name' => 'Picture', 'kind' => 'Video', 'durationFrames' => 90, 'clips' => [(object) ['asset' => self::ref('media/a'), 'name' => 'A', 'startFrame' => 0, 'durationFrames' => 30, 'sourceStartFrame' => 0, 'presentation' => (object) [], 'extras' => (object) ['metadata' => (object) []]], (object) ['asset' => self::ref('media/b'), 'name' => 'B', 'startFrame' => 40, 'durationFrames' => 30, 'sourceStartFrame' => 0, 'presentation' => []]]]]]);

        return ['story.json' => json_encode([$character, $scene, $shot], JSON_THROW_ON_ERROR), 'media.json' => json_encode([$media, $other, $edit], JSON_THROW_ON_ERROR)];
    }

    private function store(): WorkingCatalog
    {
        $store = new WorkingCatalog($this->path);
        $store->importDocuments($this->documents(), 'test import');

        return $store;
    }

    public function testRelationalRoundtripAndReopen(): void
    {
        $store = $this->store();
        foreach ($store->exportDocuments() as $name => $json) {
            self::assertEquals(json_decode($this->documents()[$name]), json_decode($json));
        }
        self::assertCount(6, $store->entities());
        self::assertCount(1, $store->rows('element'));
        self::assertCount(5, $store->rows('reference'));
        self::assertCount(2, $store->rows('asset_version'));
        self::assertSame(1, $store->revision());
        $db = new \PDO('sqlite:'.$this->path);
        self::assertSame([], $db->query('PRAGMA foreign_key_check')->fetchAll());
        // Original import bytes are not consulted for working reads.
        $db->exec("UPDATE wc_source SET original_json='[]'");
        self::assertCount(6, (new WorkingCatalog($this->path))->entities());
        $residual = json_decode($store->rows('entity')[1]['custom_data_json']);
        self::assertFalse(property_exists($residual[0], 'value'));
    }

    public function testBatchEditsHaveStableIdsAndAuditableRevision(): void
    {
        $store = $this->store();
        $element = $store->rows('element')[0];
        $clip = $store->rows('clip')[0];
        $scene = array_values(array_filter($store->rows('entity'), fn ($r) => 'NarrativeScene' === $r['entity_type']))[0];
        $next = $store->revise(1, 'editor', function (WorkingCatalog $s) use ($element, $clip, $scene): void {
            $s->updateElement($element['id'], 'Goodbye', (object) ['delivery' => 'firm']);
            $s->renumberScene($scene['id'], '14A');
            $s->retimeClip($clip['id'], 2, 28, 4);
        });
        self::assertSame(2, $next);
        self::assertSame($element['id'], $store->rows('element')[0]['id']);
        self::assertSame('Goodbye', $store->rows('element')[0]['text']);
        self::assertSame('14A', $store->entities('NarrativeScene')[0]->sceneNumber);
        self::assertSame(4, $store->rows('clip')[0]['source_start_frame']);
        self::assertCount(3, json_decode($store->rows('revision')[1]['changes_json']));
        self::assertSame('Goodbye', (new WorkingCatalog($this->path))->rows('element')[0]['text']);
    }

    public function testBadTimingRollsBackWholeBatch(): void
    {
        $store = $this->store();
        $before = $store->exportDocuments();
        try {
            $store->revise(1, 'editor', function (WorkingCatalog $s): void {
                $s->updateElement($s->rows('element')[0]['id'], 'Should roll back', (object) []);
                $s->retimeClip($s->rows('clip')[0]['id'], 20, 30, 0);
            });
            self::fail('Overlap accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, $store->exportDocuments());
            self::assertSame(1, $store->revision());
        }
        $this->expectException(\PDOException::class);
        $store->revise(1, 'editor', fn (WorkingCatalog $s) => $s->retimeClip($s->rows('clip')[0]['id'], 0, -1, 0));
    }

    public function testStaleWriterIsRejected(): void
    {
        $a = $this->store();
        $b = new WorkingCatalog($this->path);
        $a->revise(1, 'first', fn (WorkingCatalog $s) => $s->updateElement($s->rows('element')[0]['id'], 'first', (object) []));
        $this->expectException(\RuntimeException::class);
        $b->revise(1, 'stale', fn (WorkingCatalog $s) => $s->updateElement($s->rows('element')[0]['id'], 'lost update', (object) []));
    }

    public function testAssetVersionsAndClipSelectionAreExplicit(): void
    {
        $store = $this->store();
        $media = $store->rows('media');
        $clips = $store->rows('clip');
        $version = null;
        $store->revise(1, 'renderer', function (WorkingCatalog $s) use ($media, &$version): void {
            $version = $s->addAssetVersion($media[0]['entity_id'], '/new.png', 'image', (object) ['model' => 'test', 'seed' => 42]);
        });
        self::assertSame('/a.png', $store->entities('Asset')[1]->customData[0]->value->url);
        $store->revise(2, 'editor', function (WorkingCatalog $s) use ($media, $version, $clips): void {
            $s->selectAssetVersion($media[0]['entity_id'], $version);
            $s->selectClipAsset($clips[1]['id'], $media[0]['entity_id']);
        });
        self::assertSame('/new.png', $store->entities('Asset')[1]->customData[0]->value->url);
        self::assertCount(3, $store->rows('asset_version'));
        self::assertSame($media[0]['entity_id'], $store->rows('clip')[1]['asset_id']);
        $this->expectException(\InvalidArgumentException::class);
        $store->revise(3, 'editor', fn (WorkingCatalog $s) => $s->selectAssetVersion($media[1]['entity_id'], $version));
    }

    public function testInvalidExtensionReferenceRollsBackImport(): void
    {
        $docs = $this->documents();
        $story = json_decode($docs['story.json']);
        $story[1]->customData[0]->value->elements[0]->speaker = self::ref('missing');
        $docs['story.json'] = json_encode($story);
        $store = new WorkingCatalog($this->path);
        try {
            $store->importDocuments($docs);
            self::fail('Dangling reference accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $store->revision());
            self::assertSame([], $store->rows('entity'));
        }
        $store->importDocuments($this->documents());
        self::assertSame(1, $store->revision());
    }

    public function testConflictingIdentityAndUnknownShotElementAreRejected(): void
    {
        $store = new WorkingCatalog($this->path);
        $docs = $this->documents();
        $story = json_decode($docs['story.json']);
        $story[] = $story[0];
        $docs['story.json'] = json_encode($story);
        try {
            $store->importDocuments($docs);
            self::fail('Duplicate identity accepted');
        } catch (\PDOException) {
            self::assertSame([], $store->rows('entity'));
        }
        $docs = $this->documents();
        $story = json_decode($docs['story.json']);
        $story[2]->customData[0]->value->elementId = 'missing';
        $docs['story.json'] = json_encode($story);
        $this->expectException(\InvalidArgumentException::class);
        $store->importDocuments($docs);
    }

    public function testRecipeReferencesAreValidated(): void
    {
        $store = $this->store();
        $shot = $store->rows('shot')[0]['entity_id'];
        $store->revise(1, 'artist', fn (WorkingCatalog $s) => $s->setShotRecipe($shot, 'storyboard', (object) ['layers' => [(object) ['character' => self::ref('character/a'), 'pose' => 'seated']]]));
        self::assertCount(6, $store->rows('reference'));
        $this->expectException(\InvalidArgumentException::class);
        $store->revise(2, 'artist', fn (WorkingCatalog $s) => $s->setShotRecipe($shot, 'storyboard', (object) ['layers' => [(object) ['character' => self::ref('missing')]]]));
    }

    public function testMutationOutsideRevisionFails(): void
    {
        $store = $this->store();
        $this->expectException(\LogicException::class);
        $store->updateElement($store->rows('element')[0]['id'], 'oops', (object) []);
    }

    public function testNativeFieldEditsAreSchemaValidated(): void
    {
        $store = $this->store();
        $character = $store->rows('entity')[0]['id'];
        $store->revise(1, 'writer', fn (WorkingCatalog $s) => $s->setEntityField($character, 'characterName', (object) ['scriptName' => 'Renamed']));
        self::assertSame('Renamed', $store->entities('Character')[0]->characterName->scriptName);
        try {
            $store->revise(2, 'writer', fn (WorkingCatalog $s) => $s->setEntityField($character, 'characterName', 42));
            self::fail('Invalid native field accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame(2, $store->revision());
            self::assertSame('Renamed', $store->entities('Character')[0]->characterName->scriptName);
        }
    }

    public function testFractionalImportTimingIsRejectedWithoutCoercion(): void
    {
        $docs = $this->documents();
        $media = json_decode($docs['media.json']);
        $media[2]->customData[0]->value->tracks[0]->clips[0]->startFrame = 0.5;
        $docs['media.json'] = json_encode($media);
        $this->expectException(\InvalidArgumentException::class);
        (new WorkingCatalog($this->path))->importDocuments($docs);
    }

    public function testUnrelatedDatabaseIsNotModified(): void
    {
        $db = new \PDO('sqlite:'.$this->path);
        $db->exec('CREATE TABLE unrelated (value TEXT)');
        try {
            new WorkingCatalog($this->path);
            self::fail('Unrelated database accepted');
        } catch (\RuntimeException) {
            self::assertSame(['unrelated'], $db->query('SELECT name FROM sqlite_master')->fetchAll(\PDO::FETCH_COLUMN));
        }
    }
}
