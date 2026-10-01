<?php

namespace App\Exception\CrewImport;

final class MissingCrewCatalogTranslationException extends \LogicException
{
    public static function forEntity(object $entity): self
    {
        return new self(sprintf(
            'Missing explicit English name translation for %s with ID %s.',
            $entity::class,
            method_exists($entity, 'getId') ? (string) ($entity->getId() ?? 'null') : 'unknown'
        ));
    }
}
