<?php

declare(strict_types=1);

namespace Pollora\Hook\Async\Contracts;

/**
 * Carries an object by reference: stored as an identifier, reloaded at execution time.
 *
 * This is how WordPress objects travel, and how the framework adds Eloquent models.
 */
interface ObjectReference
{
    /**
     * Short name stored with each reference, unique among the registered references.
     */
    public function name(): string;

    /**
     * Whether this reference can carry the object.
     */
    public function supports(object $object): bool;

    /**
     * Identify the object.
     *
     * @return array<string, int|string> JSON-representable identifier
     */
    public function reference(object $object): array;

    /**
     * Reload the object.
     *
     * @param  array<string, mixed>  $reference  What reference() returned
     * @return object|null The object in its current state, or null when it no longer exists
     */
    public function resolve(array $reference): ?object;
}
