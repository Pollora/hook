<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/*
 * ARCH-07: the harness preset (WordPress classes and functions, the tests), plus what is specific to this package.
 */
$configuration = require __DIR__.'/vendor/pollora/harness/presets/composer-dependency-analyser.php';

return $configuration
    // The framework's facades: used only when Pollora is present (the class is checked first), absent when the package runs alone.
    ->ignoreUnknownClasses(['Pollora\Support\Facades\Action', 'Pollora\Support\Facades\Filter'])
    // AsyncFake is a testing helper: it asserts with PHPUnit, which every project that tests has.
    ->ignoreErrorsOnPackage('phpunit/phpunit', [ErrorType::SHADOW_DEPENDENCY])
    // Closures are queued with laravel/serializable-closure when it is installed (suggested); the code checks class_exists() first.
    ->ignoreErrorsOnPackage('laravel/serializable-closure', [ErrorType::DEV_DEPENDENCY_IN_PROD]);
