<?php

declare(strict_types=1);

use Pollora\Hook\Domain\Contract\HookInterface;

if (! function_exists('registrationAt')) {
    /**
     * The registration at a position of a hook's tracked callbacks, failing the test when there is none.
     *
     * @return array<string, mixed>
     */
    function registrationAt(HookInterface $hook, string $name, int $index = 0): array
    {
        return $hook->callbacks($name)[$index] ?? throw new LogicException(sprintf("No registration #%d is tracked for '%s'.", $index, $name));
    }
}
