<?php

declare(strict_types=1);

use Trouvailles\Core\Env;

Env::load(__DIR__ . '/../.env');

return [
    'keystring' => Env::get('ETSY_KEYSTRING', ''),
    'shared_secret' => Env::get('ETSY_SHARED_SECRET', ''),
];
