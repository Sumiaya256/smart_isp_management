<?php

declare(strict_types=1);

function env(string $key, ?string $default = null): ?string
{
    static $env = null;

    if (!function_exists('backend_env_starts_with')) {
        function backend_env_starts_with(string $haystack, string $needle): bool
        {
            if ($needle === '') {
                return true;
            }

            return substr($haystack, 0, strlen($needle)) === $needle;
        }
    }

    if ($env === null) {
        $env = [];
        $envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

        if (is_file($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);

                    if ($line === '' || backend_env_starts_with($line, '#')) {
                        continue;
                    }

                    $parts = explode('=', $line, 2);
                    if (count($parts) !== 2) {
                        continue;
                    }

                    $name = trim($parts[0]);
                    $value = trim($parts[1]);

                    if ($name !== '') {
                        $env[$name] = trim($value, "\"'");
                    }
                }
            }
        }
    }

    return $env[$key] ?? $default;
}
