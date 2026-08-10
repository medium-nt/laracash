<?php

return [
    'token' => env('TG_BOT_TOKEN'),
    'webhook_secret' => env('TG_WEBHOOK_SECRET'),
    'username' => env('TG_BOT_USERNAME'),
    'api_base' => env('TG_API_BASE', 'https://api.telegram.org'),
];
