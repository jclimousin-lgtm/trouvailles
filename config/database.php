<?php

declare(strict_types=1);

use Trouvailles\Core\Env;

Env::load(__DIR__ . '/../.env');

return [
    'driver'   => 'mysql',
    'host'     => Env::get('DB_HOST', '127.0.0.1'),
    'port'     => Env::get('DB_PORT', '3306'),
    'database' => Env::get('DB_NAME', 'trouvailles'),
    'username' => Env::get('DB_USER', 'root'),
    'password' => Env::get('DB_PASS', ''),
    'charset'  => 'utf8mb4',
];
