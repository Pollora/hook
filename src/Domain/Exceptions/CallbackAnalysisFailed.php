<?php

declare(strict_types=1);

namespace Pollora\Hook\Domain\Exceptions;

/**
 * Thrown when reflection cannot count the parameters of a hook callback.
 */
final class CallbackAnalysisFailed extends HookException
{
    /**
     * @param  \ReflectionException  $previous  The reflection error; its code is kept
     */
    public static function fromReflection(\ReflectionException $previous): self
    {
        return new self(
            sprintf(
                'Failed to analyze callable: %s Check that the function or method exists and is public, '
                .'or pass the number of accepted arguments explicitly so it is not detected.',
                rtrim($previous->getMessage(), '.').'.',
            ),
            (int) $previous->getCode(),
            $previous,
        );
    }
}
