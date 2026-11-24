<?php
/**
 * Central configuration for Panaderia Pilipina.
 *
 * Admin credentials are NO LONGER hardcoded in the app.
 * They are read from (in priority order):
 *   1. Real environment variables - how Render/Docker inject secrets.
 *   2. A local .env file (never committed; blocked from HTTP by .htaccess).
 *
 * Keeping secrets out of the source tree means the repository can be made
 * public without handing out the admin dashboard.
 */

// __DIR__ is the repository root. Keep the legacy Project1 path for older
// local checkouts.
function appEnvFiles(): array
{
    return [
        __DIR__ . '/.env',
        __DIR__ . '/Project1/.env',
    ];
}

function appEnvFilePath(): ?string
{
    foreach (appEnvFiles() as $path) {
        if (is_file($path) && is_readable($path)) {
            return $path;
        }
    }
    return null;
}

/**
 * Read a configuration value, preferring the real environment.
 */
function appEnv(string $key, string $default = ''): string
{
    $fromEnvironment = getenv($key);
    if ($fromEnvironment !== false && $fromEnvironment !== '') {
        return $fromEnvironment;
    }
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string)$_ENV[$key];
    }

    $path = appEnvFilePath();
    if ($path === null) {
        return $default;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$configKey, $value] = explode('=', $line, 2);
        if (trim($configKey) !== $key) {
            continue;
        }

        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
        }

        return $value;
    }

    return $default;
}

function appDemoPasswordResetEnabled(): bool
{
    return strtolower(appEnv('APP_ENV')) === 'demo'
        && strtolower(appEnv('DEMO_PASSWORD_RESET')) === 'true';
}

/**
 * Directory for writable JSON data. On Render this points at a persistent
 * disk so accounts and orders survive restarts and deploys.
 */
function appDataDir(): string
{
    $configured = appEnv('DATA_DIR', '');
    if ($configured !== '') {
        if (!is_dir($configured) && !mkdir($configured, 0775, true) && !is_dir($configured)) {
            throw new RuntimeException('Unable to create configured data directory: ' . $configured);
        }
        if (!is_writable($configured)) {
            throw new RuntimeException('Configured data directory is not writable: ' . $configured);
        }

        return rtrim($configured, '/\\');
    }

    return __DIR__;
}

function appAdminEmail(): string
{
    return strtolower(trim(appEnv('ADMIN_EMAIL', '')));
}

function appAdminPassword(): string
{
    return appEnv('ADMIN_PASSWORD', '');
}

function appAdminPasswordOverrideHash(): ?string
{
    $path = appDataDir() . '/admin_password.json';
    if (!is_file($path)) {
        return null;
    }

    $contents = file_get_contents($path);
    $data = json_decode($contents === false ? '' : $contents, true);
    $hash = is_array($data) ? ($data['password_hash'] ?? null) : null;
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('The saved admin password has an invalid format.');
    }

    return $hash;
}

function saveAdminPasswordHash(string $password): bool
{
    $encoded = json_encode(
        ['password_hash' => password_hash($password, PASSWORD_DEFAULT)],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
    if ($encoded === false) {
        return false;
    }

    return file_put_contents(appDataDir() . '/admin_password.json', $encoded, LOCK_EX) !== false;
}

function appAdminPasswordMatches(string $password): bool
{
    $overrideHash = appAdminPasswordOverrideHash();
    if ($overrideHash !== null) {
        return password_verify($password, $overrideHash);
    }

    return hash_equals(appAdminPassword(), $password);
}

/**
 * Admin access is disabled unless strong credentials are configured.
 */
function appAdminCredentialsReady(): bool
{
    $email = appAdminEmail();
    $password = appAdminPassword();

    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && strlen($password) >= 8
        && !in_array($password, ['password', 'admin', '12345678'], true);
}

/**
 * Constant-time admin credential check.
 */
function appAdminLoginSucceeds(string $email, string $password): bool
{
    if (!appAdminCredentialsReady()) {
        return false;
    }

    $expectedEmail = appAdminEmail();
    return hash_equals($expectedEmail, strtolower(trim($email)))
        && appAdminPasswordMatches($password);
}