<?php

declare(strict_types=1);

namespace Pollora\Hook\Tests\Fixtures\Async;

final class InvoiceHandler
{
    public function __construct(public string $source = 'direct') {}

    public function __invoke(int $orderId): void {}

    public function send(int $orderId): void {}

    public static function report(int $orderId): void {}
}
