<?php

declare(strict_types=1);

namespace Survos\Omc\Validation;

use Survos\Omc\Schema;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class OmcDocumentValidator extends ConstraintValidator
{
    public function __construct(private readonly Schema $schema = new Schema())
    {
    }

    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof OmcDocument) {
            throw new UnexpectedTypeException($constraint, OmcDocument::class);
        }
        if (null === $value) {
            return;
        }
        if (!$value instanceof \JsonSerializable) {
            throw new UnexpectedValueException($value, \JsonSerializable::class);
        }
        foreach ($this->schema->errors($value->jsonSerialize()) as $pointer => $messages) {
            foreach ($messages as $message) {
                $this->context->buildViolation($message)->atPath($pointer)->addViolation();
            }
        }
    }
}
