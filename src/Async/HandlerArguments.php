<?php

declare(strict_types=1);

namespace Pollora\Hook\Async;

/**
 * Maps hook arguments onto a handler's signature.
 *
 * A parameter typed AsyncContext receives the context, and an injectable
 * parameter (see Async::injectParametersUsing()) its resolved value, wherever
 * they sit; the other parameters receive the hook arguments, in order.
 *
 * @internal
 */
final class HandlerArguments
{
    /**
     * How many hook arguments a handler takes, its AsyncContext and injected parameters left out.
     *
     * @param  callable|string|array  $callback  The registered callback
     * @param  int  $registeredArgs  The argument count it was registered with
     */
    public static function hookArgumentCount(callable|string|array $callback, int $registeredArgs): int
    {
        $reflection = self::reflect($callback);

        if (! $reflection instanceof \ReflectionFunctionAbstract) {
            return $registeredArgs;
        }

        $hookParameters = count(array_filter(
            $reflection->getParameters(),
            fn (\ReflectionParameter $parameter): bool => ! self::isContextParameter($parameter) && ! Async::isInjectable($parameter),
        ));

        return max(0, min($registeredArgs, $hookParameters));
    }

    /**
     * Build the argument list for a call.
     *
     * @param  array<int|string, mixed>  $hookArguments
     * @return list<mixed>
     */
    public static function for(callable $callback, array $hookArguments, AsyncContext $context): array
    {
        $reflection = self::reflect($callback);
        $hookArguments = array_values($hookArguments);

        if (! $reflection instanceof \ReflectionFunctionAbstract) {
            return $hookArguments;
        }

        $arguments = [];

        foreach ($reflection->getParameters() as $parameter) {
            if (self::isContextParameter($parameter)) {
                $arguments[] = $context;

                continue;
            }

            if (Async::isInjectable($parameter)) {
                $arguments[] = Async::inject($parameter);

                continue;
            }

            if ($parameter->isVariadic()) {
                return [...$arguments, ...$hookArguments];
            }

            if ($hookArguments === []) {
                // Use the default value, so a later context or injected parameter still gets its own
                if (! $parameter->isDefaultValueAvailable()) {
                    break;
                }

                $arguments[] = $parameter->getDefaultValue();

                continue;
            }

            $arguments[] = array_shift($hookArguments);
        }

        return $arguments;
    }

    private static function isContextParameter(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        return $type instanceof \ReflectionNamedType && $type->getName() === AsyncContext::class;
    }

    private static function reflect(callable|string|array $callback): ?\ReflectionFunctionAbstract
    {
        try {
            return match (true) {
                $callback instanceof \Closure => new \ReflectionFunction($callback),
                is_string($callback) && str_contains($callback, '::') => new \ReflectionMethod(...explode('::', $callback, 2)),
                is_string($callback) && function_exists($callback) => new \ReflectionFunction($callback),
                is_array($callback) && count($callback) === 2 && (is_object($callback[0]) || is_string($callback[0])) && is_string($callback[1]) && method_exists($callback[0], $callback[1]) => new \ReflectionMethod($callback[0], $callback[1]),
                is_object($callback) && method_exists($callback, '__invoke') => new \ReflectionMethod($callback, '__invoke'),
                default => null,
            };
        } catch (\ReflectionException) {
            return null;
        }
    }
}
