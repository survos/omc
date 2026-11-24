<?php

declare(strict_types=1);

namespace Survos\Omc\Tests;

use PHPUnit\Framework\TestCase;
use Survos\Omc\Catalog;
use Survos\Omc\EntityFactory;
use Survos\Omc\Model\Character;
use Survos\Omc\Schema;
use Symfony\Component\Validator\Validation;

final class ModelTest extends TestCase
{
    public function testPinnedExamplesValidate(): void
    {
        $schema = new Schema();
        foreach (glob(__DIR__.'/fixtures/*.json') as $file) {
            self::assertSame([], $schema->errors(json_decode(file_get_contents($file))), basename($file));
        }
    }

    public function testTypedCharacterRetainsOriginalDataAndEmbeddedEntities(): void
    {
        $data = json_decode(file_get_contents(__DIR__.'/fixtures/Character.json'))[0];
        $entity = (new EntityFactory())->fromJson(json_encode($data));
        self::assertInstanceOf(Character::class, $entity);
        self::assertSame('SVEN', $entity->characterName->scriptName);
        self::assertEquals($data, $entity->jsonSerialize());
        $catalog = new Catalog(json_encode($data));
        self::assertSame($catalog->entities()[0], $catalog->find($entity->identifier[0]));
        self::assertNotEmpty($catalog->relationships());
    }

    public function testSymfonyAttributeValidatesInheritedDtoAgainstSchema(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $data = json_decode(file_get_contents(__DIR__.'/fixtures/Character.json'))[0];
        self::assertCount(0, $validator->validate(new Character($data)));
        $data->unexpectedField = 'Not part of OMC';
        self::assertGreaterThan(0, count($validator->validate(new Character($data))));
    }
}
