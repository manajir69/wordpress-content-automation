<?php
declare(strict_types=1);

return [
    'name' => 'Blog Automation',
    'env' => env('APP_ENV', 'production'),
    'debug' => filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
    'url' => rtrim((string)env('APP_URL', ''), '/'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Dhaka'),
    'key' => env('APP_KEY', ''),
];
