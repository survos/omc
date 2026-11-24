# Optional SQLite snapshots

`Survos\Omc\Storage\SqliteCatalog` requires PHP PDO with `pdo_sqlite` only when used. The rest of `survos/omc` does not require SQLite. No Symfony bundle, Doctrine mapping, or database service is needed.

```php
use Survos\Omc\Storage\SqliteCatalog;

$snapshot = new SqliteCatalog('/path/to/production.sqlite');
$snapshot->importJson(file_get_contents('/path/to/production.json'));
file_put_contents('/path/to/export.json', $snapshot->exportJson());
$catalog = $snapshot->catalog(); // validated OMC Catalog
$metadata = $snapshot->metadata();
```

One database holds one catalog. Import replaces it transactionally; invalid JSON, invalid OMC, conflicting identities rejected by Catalog, or SQL failures leave the previous snapshot intact. An empty snapshot cannot be exported. Opening an unrelated database or unsupported format fails. Use a normal filesystem path (or `:memory:`); the containing directory must exist.

The original JSON bytes are authoritative and preserved exactly, including formatting, nulls, custom data and embedded entities. Export reads those bytes, checks their SHA-256, and does not reconstruct JSON from relational tables. `catalog()` revalidates the exported source. The source's own version fields remain unchanged. Metadata records the validator schema URL, pinned schema commit and UTC import timestamp separately from the SQLite format version.

## Format 1

Standard SQLite, application ID `0x4f4d4301`, user version `1`. No extensions, PHP serialization, JSON SQL functions, or custom collations. Native SQLite clients, including WASM and Unity integrations, can query it directly.

- `omc_catalog`: singleton source JSON, checksum, schema metadata, import timestamp.
- `omc_entity`: canonical Catalog entity key, document traversal ordinal, native entity type, entity JSON.
- `omc_identifier`: original scope/value pair as the composite primary key, identifier hash and owning entity key. Values in different scopes remain distinct.
- `omc_relationship`: reference ordinal, source entity key, JSON Pointer relative to that entity, original target scope/value, nullable resolved entity key, explicit `resolved`/`unresolved` status. Multiple identifiers on a reference yield separate rows following Catalog semantics. Embedded entities have their own outgoing relationships.

```sql
SELECT source_entity_key, reference_path, target_scope, target_value
FROM omc_relationship WHERE resolution = 'unresolved' ORDER BY ordinal;
```

The relational tables are rebuildable indexes of Catalog's current interpretation; they do not replace native OMC relationship types or change the JSON shape. Consumers should treat them as read-only. No migration framework, multi-catalog merge, revision history, media embedding, streaming import, or browser player is included. Import materializes the Catalog in memory. Future index interpretation changes require a format version change. Use a closed/quiescent snapshot when copying files; active concurrent writers need SQLite's backup facilities. The adapter uses ordinary SQLite rollback journaling by default rather than enabling WAL.

Run tests from the mono root:

```sh
php vendor/bin/phpunit --no-configuration --bootstrap lib/omc/tests/bootstrap.php lib/omc/tests/Storage
```
