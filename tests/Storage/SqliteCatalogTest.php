<?php

declare(strict_types=1);

namespace Survos\Omc\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Survos\Omc\Model\Identifier;
use Survos\Omc\Schema;
use Survos\Omc\Storage\SqliteCatalog;

final class SqliteCatalogTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'omc-test-');
    }

    protected function tearDown(): void
    {
        unlink($this->path);
    }

    private function character(): \stdClass
    {
        return json_decode(file_get_contents(dirname(__DIR__).'/fixtures/Character.json'), flags: JSON_THROW_ON_ERROR)[0];
    }

    private function json(): string
    {
        $first = $this->character();
        $first->identifier[] = (object) ['identifierScope' => 'alias', 'identifierValue' => 'sven'];
        $second = clone $first;
        $second->identifier = [(object) ['identifierScope' => 'other', 'identifierValue' => $first->identifier[0]->identifierValue]];
        $second->Depiction = null;

        return json_encode([$first, $second], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    }

    public function testExactRoundtripAndPortableIndexesAfterReopen(): void
    {
        $json = $this->json();
        $store = new SqliteCatalog($this->path);
        $store->importJson($json);
        unset($store);
        $store = new SqliteCatalog($this->path);
        self::assertSame($json, $store->exportJson());
        self::assertSame(Schema::VERSION, $store->metadata()['schema_version']);
        self::assertSame(Schema::UPSTREAM_COMMIT, $store->metadata()['schema_commit']);
        self::assertSame(hash('sha256', $json), $store->metadata()['source_sha256']);
        self::assertCount(3, $store->catalog()->entities());
        self::assertNotNull($store->catalog()->find(new Identifier('alias', 'sven')));
        $db = new \PDO('sqlite:'.$this->path);
        self::assertSame('ok', $db->query('PRAGMA integrity_check')->fetchColumn());
        self::assertSame([], $db->query('PRAGMA foreign_key_check')->fetchAll());
        self::assertSame(4, (int) $db->query('SELECT count(*) FROM omc_identifier')->fetchColumn());
        self::assertSame(2, (int) $db->query("SELECT count(*) FROM omc_relationship WHERE resolution = 'unresolved' AND resolved_entity_key IS NULL")->fetchColumn());
        self::assertSame('/Depiction/0', $db->query("SELECT reference_path FROM omc_relationship WHERE resolution = 'resolved'")->fetchColumn());
        self::assertSame('Depiction', json_decode($db->query("SELECT entity_json FROM omc_entity WHERE entity_type = 'Depiction'")->fetchColumn())->entityType);
    }

    public function testInvalidAndConflictingIdentityImportsLeaveSnapshotUntouched(): void
    {
        $store = new SqliteCatalog($this->path);
        $original = $this->json();
        $store->importJson($original);
        $a = $this->character();
        $b = clone $a;
        $b->description = 'Conflicting definition';
        $invalidIdentity = clone $a;
        $invalidIdentity->identifier = [(object) ['identifierValue' => 'missing-scope']];
        foreach (['{', '{"entityType":"Character"}', json_encode([$a, $b]), json_encode($invalidIdentity)] as $bad) {
            try {
                $store->importJson($bad);
                self::fail('Invalid catalog accepted');
            } catch (\InvalidArgumentException|\JsonException) {
                self::assertSame($original, $store->exportJson());
            }
        }
    }

    public function testWriteFailureRollsBackSourceAndEveryIndex(): void
    {
        $store = new SqliteCatalog($this->path);
        $store->importJson($original = $this->json());
        $db = new \PDO('sqlite:'.$this->path);
        $db->exec("CREATE TRIGGER fail_import BEFORE INSERT ON omc_entity BEGIN SELECT RAISE(ABORT, 'test write failure'); END");
        try {
            $store->importJson(json_encode($this->character()));
            self::fail('Expected write failure');
        } catch (\PDOException) {
            self::assertSame($original, $store->exportJson());
            self::assertSame(3, (int) $db->query('SELECT count(*) FROM omc_entity')->fetchColumn());
            self::assertSame(4, (int) $db->query('SELECT count(*) FROM omc_identifier')->fetchColumn());
            self::assertSame(3, (int) $db->query('SELECT count(*) FROM omc_relationship')->fetchColumn());
        }
        $db->exec('DROP TRIGGER fail_import');
        $replacement = json_encode($this->character());
        $store->importJson($replacement);
        self::assertSame($replacement, (new SqliteCatalog($this->path))->exportJson());
        self::assertSame(2, (int) $db->query('SELECT count(*) FROM omc_entity')->fetchColumn());
    }

    public function testUnrelatedDatabaseIsRejectedWithoutModification(): void
    {
        $db = new \PDO('sqlite:'.$this->path);
        $db->exec('CREATE TABLE unrelated (value TEXT)');
        try {
            new SqliteCatalog($this->path);
            self::fail('Unrelated database accepted');
        } catch (\RuntimeException) {
            self::assertSame(['unrelated'], $db->query('SELECT name FROM sqlite_master')->fetchAll(\PDO::FETCH_COLUMN));
        }
    }

    public function testFutureFormatIsRejected(): void
    {
        new SqliteCatalog($this->path);
        (new \PDO('sqlite:'.$this->path))->exec('PRAGMA user_version = 999');
        $this->expectException(\RuntimeException::class);
        new SqliteCatalog($this->path);
    }

    public function testEmptySnapshotCannotBeExported(): void
    {
        $store = new SqliteCatalog($this->path);
        $this->expectException(\LogicException::class);
        $store->exportJson();
    }

    public function testSourceTamperingIsDetected(): void
    {
        $store = new SqliteCatalog($this->path);
        $store->importJson($this->json());
        (new \PDO('sqlite:'.$this->path))->exec("UPDATE omc_catalog SET source_json = '{}'");
        $this->expectException(\RuntimeException::class);
        $store->exportJson();
    }
}
