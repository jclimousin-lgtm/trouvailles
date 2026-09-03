<?php

declare(strict_types=1);

/**
 * Autoloader minimal (spl_autoload_register), sans Composer.
 */
spl_autoload_register(function (string $class): void {
    $map = [
        'Trouvailles\\Core\\' => __DIR__ . '/',
        'Trouvailles\\Controllers\\' => __DIR__ . '/../Controllers/',
        'Trouvailles\\Models\\' => __DIR__ . '/../Models/',
        'Trouvailles\\Services\\' => __DIR__ . '/../Services/',
    ];

    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {

            $relative = substr($class, strlen($prefix));

            $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);

            $file = $dir . $relative . '.php';

            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
