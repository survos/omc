# MovieLabs OMC for PHP

PHP 8.5 immutable DTOs and lossless JSON catalogs using the pinned MovieLabs
OMC 2.8 schema. See `resources/upstream/NOTICE.md` for the upstream revision
and Apache-2.0 attribution. Library code is MIT licensed.

```php
use Survos\Omc\EntityFactory;
use Survos\Omc\Catalog;

$entity = (new EntityFactory())->fromJson($singleEntityJson);
$catalog = new Catalog($documentJson);
$characters = $catalog->entities('Character');
```

Both entry points validate imported JSON. Typed models expose common fields;
`value()` and `jsonSerialize()` retain the remaining schema fields, extensions,
nulls and empty objects. Catalog indexes scoped identifiers, aliases and native
references. Conflicting entity definitions are rejected. External references
may remain unresolved. Arbitrary `customData` is not interpreted as native OMC.

## Symfony validation attributes

`Entity` carries `#[OmcDocument]`, inherited by its DTO subclasses. The constraint
uses the same pinned schema, so there is no parallel hand-maintained list of OMC
constraints. This also validates DTOs constructed directly rather than imported
through the factory. Validation is explicit; attributes alone do not run checks.

```php
use Symfony\Component\Validator\Validation;

$validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
$violations = $validator->validate($entity);
```

Violation paths are JSON pointers from the schema validator. Applications may
add Symfony constraints for their own stricter requirements. Collection type
annotations remain where PHP has no native generic type syntax.

Run the model tests from the mono root:

```sh
vendor/bin/phpunit --bootstrap lib/omc/tests/bootstrap.php lib/omc/tests/ModelTest.php
```
