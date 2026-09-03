<?php
declare(strict_types=1);

/**
 * Assertions minimales, copiées de juridico — génériques, aucune
 * adaptation nécessaire.
 */

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertEquals(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message .
            PHP_EOL .
            'Expected: ' . var_export($expected, true) .
            PHP_EOL .
            'Actual:   ' . var_export($actual, true)
        );
    }
}

function assertNotNull(mixed $value, string $message): void
{
    if ($value === null) {
        throw new RuntimeException($message);
    }
}

function assertNull(mixed $value, string $message): void
{
    if ($value !== null) {
        throw new RuntimeException($message);
    }
}

function assertThrows(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        return;
    }
    throw new RuntimeException($message);
}
