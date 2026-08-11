<?php

return [
    'token' => env('MAX_BOT_TOKEN'),
    'webhook_secret' => env('MAX_WEBHOOK_SECRET'),
    'username' => env('MAX_BOT_USERNAME'),
    'api_base' => env('MAX_API_BASE', 'https://platform-api2.max.ru'),

    // Прд: путь к cacert.pem (Mozilla bundle + Russian Trusted Root CA). На Beget системный
    // CA-бандл не содержит Russian root, которым подписан platform-api2.max.ru → cURL error 60.
    // Локально не задан → SSL-проверка по системному cacert из php.ini.
    'cacert' => env('MAX_CACERT'),
];
