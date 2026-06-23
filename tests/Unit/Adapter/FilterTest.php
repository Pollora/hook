<?php

declare(strict_types=1);

use Pollora\Hook\Adapter\Out\WordPress\Filter;

beforeEach(function (): void {
    $GLOBALS['wp_filters'] = [];
    $GLOBALS['wp_filters_removed'] = [];
});

if (! function_exists('add_filter')) {
    function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $GLOBALS['wp_filters'][] = ['hook' => $hook, 'callback' => $callback, 'priority' => $priority, 'args' => $acceptedArgs];
    }
}

if (! function_exists('remove_filter')) {
    function remove_filter(string $hook, mixed $callback, int $priority = 10): void
    {
        $GLOBALS['wp_filters_removed'][] = ['hook' => $hook, 'callback' => $callback, 'priority' => $priority];
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}

describe('WordPress Filter Adapter', function (): void {
    it('registers filter via WordPress add_filter', function (): void {
        $filter = new Filter;
        $callback = fn (string $val): string => $val;
        $filter->add('the_content', $callback, 20);

        expect($GLOBALS['wp_filters'])->toHaveCount(1)
            ->and($GLOBALS['wp_filters'][0]['hook'])->toBe('the_content')
            ->and($GLOBALS['wp_filters'][0]['priority'])->toBe(20);
    });

    it('removes filter via WordPress remove_filter', function (): void {
        $filter = new Filter;
        $callback = fn (string $val): string => $val;
        $filter->add('the_content', $callback);
        $filter->remove('the_content', $callback);

        expect($GLOBALS['wp_filters_removed'])->toHaveCount(1)
            ->and($GLOBALS['wp_filters_removed'][0]['hook'])->toBe('the_content');
    });

    it('applies filter via WordPress apply_filters', function (): void {
        $filter = new Filter;
        $result = $filter->apply('the_title', 'Hello World');

        expect($result)->toBe('Hello World');
    });
});
