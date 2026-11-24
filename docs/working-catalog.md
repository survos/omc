# Editable OMC working catalog

`Survos\Omc\Storage\WorkingCatalog` is the database-authoritative working store for the current `rph.* /1` production profile. It requires PDO SQLite. No Symfony bundle or Doctrine mappings are needed. The earlier snapshot-only `SqliteCatalog` has been removed; this is new development with no backward-compatibility requirement.

OMC is the interchange shape. After the one-time import, current relational rows and their JSON extras own the content. Original document bytes are provenance only. Exports reconstruct documents from working rows, never from the imported source blobs. JSON formatting and key order are not preserved; values, nulls, empty objects, arrays and unknown fields are preserved for the supported profile.

## RPH integration

```php
use Survos\Omc\Storage\WorkingCatalog;

$store = new WorkingCatalog('/path/to/production.sqlite');
// One-time import; include every file named by the project, plus project.json.
$store->importDocuments([
    'project.json' => file_get_contents('/import/project.json'),
    // ...characters.json, scenes.json, assets.json, edit.json, etc.
], 'migration');

// Rendering, review and OTIO read current database content.
$entities = $store->entities(); // list<stdClass>; optionally filter by entityType
$revision = $store->revision();
$elements = $store->rows('element');
$clips = $store->rows('clip');

$nextRevision = $store->revise($revision, 'editor:user-id', function (WorkingCatalog $db) use ($elements, $clips) {
    $db->updateElement($elements[0]['id'], 'Revised dialogue', (object) ['delivery' => 'quietly']);
    $db->retimeClip($clips[0]['id'], 0, 30, 0);
});

$documents = $store->exportDocuments(); // filename => current OMC JSON
```

RPH should replace its filesystem `Production` loader with one that loads `entities()` once per request, then builds its existing scoped-identity map. Existing review and OTIO mapping logic can consume that map; no temporary exported files are needed. The storage API returns objects to preserve empty-object semantics; an application requiring associative arrays can convert at its boundary. Endpoints should expose named domain operations and require the client's expected revision. Do not expose arbitrary SQL or arbitrary file paths through MCP.

`revise()` uses `BEGIN IMMEDIATE`, checks the expected revision under the write lock, validates OMC and the RPH reference/timing constraints, then commits all edits plus an actor/timestamp/change record. Failure rolls back the entire batch. An outdated revision throws instead of overwriting intervening work. Document reads use a SQLite read transaction. Fetch a fresh revision for editing; read-only consumers can render a single `entities()` result consistently.

## Tables and authority

| Table | Responsibility |
|---|---|
| `wc_entity`, `wc_identifier` | Stable entity identity, native type, name and display number; native fields not individually projected remain in `extra_json`. Scope/value uniqueness is enforced. |
| `wc_element` | Scene FK, stable line ID distinct from sequence, text, kind, speaker FK and delivery/direction JSON. Includes action and other script elements. |
| `wc_edit`, `wc_track`, `wc_clip` | Integer fps, dimensions, ordered tracks, clip identity, media FK, integer start/duration/source-offset frames, presentation extras. |
| `wc_shot` | Shot identity, scene and scene-element FKs, sequence, framing, timing intent and selected media FK. |
| `wc_media`, `wc_asset_version` | Media identity, selected version FK, immutable candidate URL/kind/provenance and the revision that registered it. |
| `wc_extension` | RPH extension remainder: renderer recipes, blocking, prompt templates and other fields not projected into columns. Projected values are removed from this remainder. |
| `wc_reference` | Validated projection of native and RPH references, including recipe bindings. Target FKs and JSON Pointer paths support usage queries. |
| `wc_revision` | Actor, UTC timestamp and before/after field changes or import/version-registration records. |
| `wc_source` | Original bytes, checksum and schema URL/commit for migration provenance only. |

Identifiers are distinct from display numbers. Renumbering a scene does not change its entity ID, line IDs or relationships. Track and clip IDs are minted deterministically from their initial owner/position during migration, then stored permanently; they are not recomputed when timing changes. Element IDs are scoped by scene. No PHP serialization, custom SQLite extension or JSON SQL functions are used.

Props, wardrobe and locations are native OMC entity rows. Their native Context.features relationships remain in the entity payload and are projected into `wc_reference` for joins; there are not yet dedicated property-specific usage tables. Blocking is renderer-specific JSON, with actual OMC references validated; unstructured strings in legacy recipes are not magically converted into entity references.

## Mutation API

All mutations run inside `revise(expectedRevision, actor, callback)`:

- `updateElement(id, text, direction)` edits authored text and delivery.
- `renameEntity(id, name)` and `renumberScene(id, displayNumber)` preserve identity.
- `setEntityField(id, field, value)` edits native OMC data such as character profile, description or Context.features, with schema/reference validation. Identity and normalized fields are protected.
- `retimeClip(id, startFrame, durationFrames, sourceStartFrame)` enforces nonnegative integer frames, positive duration, track bounds and no same-track overlap.
- `selectClipAsset(clipId, mediaEntityId)` explicitly changes one clip's media selection.
- `addAssetVersion(assetId, url, kind, provenanceObject)` registers a candidate without selecting it; returns its ID.
- `selectAssetVersion(assetId, versionId)` selects a candidate belonging to that asset. All clips using that media identity see the selected URL. To keep independent takes pinned simultaneously, use distinct media identities.
- `setShotRecipe(shotId, field, value)` edits allowed renderer recipe fields and validates embedded RPH references.

`rows(kind)` supports entity, identifier, element, extension, edit, track, clip, shot, media, asset_version, reference, revision and source. Treat this as an inspection API; external SQL writes bypass domain validation and revision tracking.

OMC exports describe the current selected media. Candidate history and revision records stay in the SQLite database; an OMC export alone is not a complete working-database backup. Version provenance records are explicit and do not invent a generation prompt. Existing descriptive media extras remain unchanged when a new URL is selected; update authored metadata separately when its meaning changes.

## Verified migration

Exact RPH sources audited on 2026-11-24, without modifying RPH:

| Production | Entities | Script elements | Dialogue | Shots | Media | Tracks | Clips | Native + RPH references |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| xmas | 223 | 116 | 33 | 37 | 112 | 13 | 113 | 466 |
| last-train | 58 | 24 | 12 | 12 | 37 | 3 | 60 | 104 |

All 19 original files passed schema validation and semantic per-file roundtrip through the working database. All references resolved in the combined production catalogs. The previous snapshot index covered only 158/19 native references; the working index additionally covers 308/85 RPH references.

## Deliberate current boundaries

This is a working-store foundation, not a complete production authoring system. The importer currently requires flat, fully resolved OMC entity arrays and the current RPH extension shapes. It rejects embedded entity definitions and dangling references instead of silently losing them. Imports are one-time; migrating another source requires a new database.

No create/delete/reorder operations for entities, elements, tracks or clips are exposed yet. Shot timing/selection mutation, speaker reassignment, general media metadata editing, domain-specific prop-use CRUD, strict recipe schemas, render job state, full revision replay/undo, and cross-format database migration remain future work. No RPH application or MCP endpoints were changed here. The source files lack stable track/clip IDs, a historical take registry and verified prompt provenance; the migration assigns IDs and registers only the observed selected media as initial candidates.

Tests:

```sh
php vendor/bin/phpunit --no-configuration --do-not-record-test-run-history --bootstrap lib/omc/tests/bootstrap.php lib/omc/tests/Storage
```
