<?php

declare(strict_types=1);

namespace Pollora\Hook\Tests\Fixtures\Async;

/**
 * A service a handler asks for, resolved by injection.
 */
final class Mailer
{
    public function __construct(public string $transport = 'smtp') {}
}
