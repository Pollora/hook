<?php

declare(strict_types=1);

namespace Pollora\Hook\Domain\Contract;

/**
 * Port interface for resolving class instances used as hook callbacks.
 *
 * Abstracts class instantiation so the domain layer remains framework-agnostic.
 * Infrastructure implementations can leverage DI containers (Laravel, Symfony, etc.).
 */
interface CallbackResolverInterface
{
    /**
     * Resolve an instance of the given class.
     *
     * @param  string  $className  Fully qualified class name.
     * @return object The resolved instance.
     */
    public function resolve(string $className): object;
}
