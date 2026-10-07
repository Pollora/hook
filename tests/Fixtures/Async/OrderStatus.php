<?php

declare(strict_types=1);

namespace Pollora\Hook\Tests\Fixtures\Async;

enum OrderStatus: string
{
    case Completed = 'completed';
    case Refunded = 'refunded';
}
