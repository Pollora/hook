<?php

declare(strict_types=1);

use Pollora\Hook\Adapter\Out\WordPress\Filter;

beforeEach(function (): void {
    $GLOBALS['wp_filters'] = [];
    $GLOBALS['wp_filters_removed'] = [];
});

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

describe('Filter tracking', function (): void {
    it('keeps track of the filters it registers', function (): void {
        $filter = new Filter;
        $callback = fn (string $val): string => $val;

        $filter->add('the_content', $callback, 20);

        expect($filter->callbacks('the_content'))->toHaveCount(1)
            ->and($filter->callbacks('the_content')[0]['callback'])->toBe($callback)
            ->and($filter->callbacks('the_content')[0]['priority'])->toBe(20)
            ->and($filter->exists('the_content', $callback))->toBeTrue();
    });
});
