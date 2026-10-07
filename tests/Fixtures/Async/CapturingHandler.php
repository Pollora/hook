<?php

declare(strict_types=1);

namespace Pollora\Hook\Tests\Fixtures\Async;

use Pollora\Hook\Async\AsyncContext;

/**
 * A handler class with a public capture() method.
 */
final class CapturingHandler
{
    /** @var list<array<string, mixed>> */
    public static array $contexts = [];

    public function handle(int $postId, AsyncContext $context): void
    {
        self::$contexts[] = ['method' => $context->get('method'), 'postId' => $context->get('postId')];
    }

    /**
     * @return array<string, mixed>
     */
    public function capture(int $postId): array
    {
        return ['method' => 'capture()', 'postId' => $postId];
    }
}
