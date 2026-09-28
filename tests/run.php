<?php

declare(strict_types=1);

/**
 * Standalone test runner for the Kodhe Session package.
 *
 * Usage: php tests/run.php
 *
 * Works with or without PHPUnit/composer installed: when PHPUnit is
 * missing, bootstrap.php provides a lightweight TestCase shim.
 */

require __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

$testFiles = glob(__DIR__ . '/*Test.php');
sort($testFiles);

$totalPass = 0;
$totalFail = 0;
$failures = [];

$classes = [];
foreach ($testFiles as $file) {
    $before = get_declared_classes();
    require_once $file;
    $new = array_diff(get_declared_classes(), $before);
    foreach ($new as $class) {
        if (is_subclass_of($class, TestCase::class)) {
            $classes[] = $class;
        }
    }
}

foreach ($classes as $class) {
    $reflection = new ReflectionClass($class);
    $shortName = $reflection->getShortName();

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (strncmp($method->getName(), 'test', 4) !== 0) {
            continue;
        }

        $test = new $class();
        try {
            // Invoke protected setUp via closure binding
            $setUp = fn () => $test->setUp();
            $setUpType = ReflectionMethod::class;
            $setUpInvokable = \Closure::bind(function () { $this->setUp(); }, $test, $class);
            $tearDownInvokable = \Closure::bind(function () { $this->tearDown(); }, $test, $class);

            $setUpInvokable();
            $test->{$method->getName()}();
            $tearDownInvokable();

            $totalPass++;
            echo ".";
        } catch (\Throwable $e) {
            $totalFail++;
            $failures[] = sprintf('%s::%s -> %s', $shortName, $method->getName(), $e->getMessage());
            echo "F";
        }
    }
}

echo PHP_EOL . PHP_EOL;

if ($failures) {
    echo "FAILURES:" . PHP_EOL;
    foreach ($failures as $f) {
        echo "  - {$f}" . PHP_EOL;
    }
    echo PHP_EOL;
}

printf("Tests: %d, Passed: %d, Failed: %d%s", $totalPass + $totalFail, $totalPass, $totalFail, PHP_EOL);

exit($totalFail > 0 ? 1 : 0);
