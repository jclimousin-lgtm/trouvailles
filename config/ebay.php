<?php

declare(strict_types=1);

use Trouvailles\Core\Env;

Env::load(__DIR__ . '/../.env');

return [
    'client_id' => Env::get('EBAY_CLIENT_ID', ''),
    'client_secret' => Env::get('EBAY_CLIENT_SECRET', ''),
    'marketplace_id' => Env::get('EBAY_MARKETPLACE_ID', 'EBAY_US'),
];
