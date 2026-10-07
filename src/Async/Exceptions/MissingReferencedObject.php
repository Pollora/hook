<?php

declare(strict_types=1);

namespace Pollora\Hook\Async\Exceptions;

/**
 * Thrown when an object carried by reference no longer exists at execution time.
 */
final class MissingReferencedObject extends AsyncException
{
    /**
     * @param  array<string, mixed>  $reference
     */
    public static function for(string $kind, array $reference): self
    {
        return new self(sprintf('The %s object referenced by %s no longer exists.', $kind, json_encode($reference)));
    }
}
