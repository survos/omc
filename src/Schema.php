<?php

declare(strict_types=1);

namespace Survos\Omc;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

final class Schema
{
    public const string VERSION = 'https://movielabs.com/omc/json/schema/v2.8';
    public const string UPSTREAM_COMMIT = 'dd047298c7aaebf42b71f78f5208a8d872c182ca';
    private Validator $validator;
    private \stdClass $schema;

    public function __construct()
    {
        $this->schema = json_decode(file_get_contents(__DIR__.'/../resources/upstream/OMC-JSON-v2.8.schema.json'), flags: JSON_THROW_ON_ERROR);
        $this->validator = new Validator();
        $this->validator->parser()->setOption('allowDefaults', false);
        $this->validator->resolver()->registerRaw($this->schema);
    }

    /** @return array<string, list<string>> */
    public function errors(mixed $document): array
    {
        $result = $this->validator->validate($document, self::VERSION);

        return $result->isValid() ? [] : (new ErrorFormatter())->format($result->error());
    }

    public function assertValid(mixed $document): void
    {
        if ($errors = $this->errors($document)) {
            throw new \InvalidArgumentException('Invalid OMC document: '.json_encode($errors, JSON_THROW_ON_ERROR));
        }
    }

    /** @return list<string> */
    public function entityTypes(): array
    {
        return array_keys(get_object_vars($this->schema->{'$defs'}->core->properties->rootObject->properties));
    }
}
