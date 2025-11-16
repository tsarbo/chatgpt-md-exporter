<?php

return [
    'browsershot' => [
        'node_binary' => env('BROWSERSHOT_NODE_BINARY'),
        'npm_binary' => env('BROWSERSHOT_NPM_BINARY'),
        'chrome_path' => env('BROWSERSHOT_CHROME_PATH'),
        'disable_sandbox' => env('BROWSERSHOT_DISABLE_SANDBOX', false),
    ],
];
