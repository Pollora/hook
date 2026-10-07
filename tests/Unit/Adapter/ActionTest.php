<?php

declare(strict_types=1);

use Pollora\Hook\Adapter\Out\WordPress\Action;

beforeEach(function (): void {
    $GLOBALS['wp_actions'] = [];
    $GLOBALS['wp_actions_removed'] = [];
    $GLOBALS['wp_actions_done'] = [];
});

describe('WordPress Action Adapter', function (): void {
    it('registers action via WordPress add_action', function (): void {
        $action = new Action;
        $callback = fn (): null => null;
        $action->add('init', $callback, 15);

        expect($GLOBALS['wp_actions'])->toHaveCount(1)
            ->and($GLOBALS['wp_actions'][0]['hook'])->toBe('init')
            ->and($GLOBALS['wp_actions'][0]['priority'])->toBe(15);
    });

    it('removes action via WordPress remove_action', function (): void {
        $action = new Action;
        $callback = fn (): null => null;
        $action->add('init', $callback);
        $action->remove('init', $callback);

        expect($GLOBALS['wp_actions_removed'])->toHaveCount(1)
            ->and($GLOBALS['wp_actions_removed'][0]['hook'])->toBe('init');
    });

    it('executes action via WordPress do_action', function (): void {
        $action = new Action;
        $action->do('my_custom_action', 'arg1', 'arg2');

        expect($GLOBALS['wp_actions_done'])->toHaveCount(1)
            ->and($GLOBALS['wp_actions_done'][0]['hook'])->toBe('my_custom_action')
            ->and($GLOBALS['wp_actions_done'][0]['args'])->toBe(['arg1', 'arg2']);
    });
});

describe('Instance methods named by class', function (): void {
    it('hands WordPress a callable instance method', function (): void {
        $className = 'ActionInstanceHandler_'.uniqid();
        eval(sprintf('class %s { public function handle(int $id) {} }', $className));

        (new Action)->add('save_post', [$className, 'handle']);

        expect(is_callable($GLOBALS['wp_actions'][0]['callback']))->toBeTrue()
            ->and($GLOBALS['wp_actions'][0]['args'])->toBe(1);
    });

    it('removes from WordPress the instance it registered', function (): void {
        $className = 'ActionRemovedHandler_'.uniqid();
        eval(sprintf('class %s { public function handle(int $id) {} }', $className));
        $action = new Action;
        $action->add('save_post', [$className, 'handle']);
        $registered = $GLOBALS['wp_actions'][0]['callback'];

        $action->remove('save_post', [$className, 'handle']);

        expect($GLOBALS['wp_actions_removed'][0]['callback'])->toBe($registered)
            ->and($GLOBALS['wp_actions'])->toBe([]);
    });
});
