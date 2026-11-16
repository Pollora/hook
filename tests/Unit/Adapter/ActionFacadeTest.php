<?php

declare(strict_types=1);

use Pollora\Hook\Action as ActionFacade;
use Pollora\Hook\Adapter\Out\WordPress\Action;

/**
 * Reset, or replace, the facade's shared instance.
 */
function setFacadeInstance(?Action $instance): ?Action
{
    $property = new ReflectionProperty(ActionFacade::class, 'instance');
    $previous = $property->getValue();
    $property->setValue(null, $instance);

    return $previous;
}

beforeEach(function (): void {
    $GLOBALS['wp_actions'] = [];
    $GLOBALS['wp_actions_removed'] = [];
    $GLOBALS['wp_async_listeners'] = [];
    $this->previousInstance = setFacadeInstance(null);
});

afterEach(function (): void {
    setFacadeInstance($this->previousInstance ?? new Action);
});

describe('Action facade', function (): void {
    it('registers the callback it is given with WordPress', function (): void {
        $returned = ActionFacade::add('init', 'boot_something', 15, 2);

        expect($returned)->toBeInstanceOf(Action::class)
            ->and($GLOBALS['wp_actions'])->toHaveCount(1)
            ->and($GLOBALS['wp_actions'][0]['hook'])->toBe('init')
            ->and($GLOBALS['wp_actions'][0]['callback'])->toBe('boot_something')
            ->and($GLOBALS['wp_actions'][0]['priority'])->toBe(15)
            ->and($GLOBALS['wp_actions'][0]['args'])->toBe(2);
    });

    it('shares one adapter between calls', function (): void {
        $first = ActionFacade::add('init', 'boot_something');
        $second = ActionFacade::add('wp_head', 'print_something');

        expect($second)->toBe($first)
            ->and(ActionFacade::exists('init'))->toBeTrue()
            ->and(ActionFacade::exists('wp_head'))->toBeTrue()
            ->and(ActionFacade::callbacks('init'))->toHaveCount(1)
            ->and(ActionFacade::remove('init', 'boot_something'))->toBeTrue()
            ->and(ActionFacade::exists('init'))->toBeFalse();
    });

    it('tells whether anything was removed', function (): void {
        expect(ActionFacade::remove('never_registered'))->toBeFalse();
    });

    it('stays quiet when the framework facade is not there', function (): void {
        $notices = [];
        set_error_handler(function (int $level, string $message) use (&$notices): bool {
            $notices[] = $message;

            return true;
        }, E_USER_NOTICE);

        try {
            ActionFacade::add('init', 'boot_something');
        } finally {
            restore_error_handler();
        }

        expect($notices)->toBe([]);
    });

    it('recommends the framework facade when it is available, once', function (): void {
        // In a separate process: the framework facade, once declared, stays declared for every other test
        $script = tempnam(sys_get_temp_dir(), 'pollora-hook').'.php';
        file_put_contents($script, sprintf(
            '<?php namespace Pollora\\Support\\Facades { class Action {} } namespace { require %s; require %s; '
            .'set_error_handler(function (int $level, string $message): bool { echo $message, "\\n"; return true; }, E_USER_NOTICE); '
            .'Pollora\\Hook\\Action::add("init", "boot_something"); Pollora\\Hook\\Action::add("init", "boot_something_else"); }',
            var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true),
            var_export(dirname(__DIR__, 2).'/Stubs/wordpress.php', true),
        ));

        try {
            $output = (string) shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1');
        } finally {
            unlink($script);
        }

        expect($output)->toBe(
            'Using Pollora\\Hook\\Action directly is not recommended when the Pollora framework is available. '
            ."Use the Pollora\\Support\\Facades\\Action facade instead for DI container support.\n"
        );
    });
});
