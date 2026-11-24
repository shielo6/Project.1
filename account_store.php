<?php
require_once __DIR__ . '/app_config.php';

function customerAccountsPath(): string
{
    return appDataDir() . '/bread_accounts.json';
}

function sanitizeCustomerAccount(array $account): array
{
    $sanitized = [];
    foreach (['first_name', 'middle_name', 'last_name', 'email', 'password', 'created_at', 'username'] as $key) {
        if (array_key_exists($key, $account)) {
            $sanitized[$key] = $account[$key];
        }
    }

    if (isset($sanitized['first_name']) || isset($sanitized['last_name'])) {
        $sanitized['first_name'] = trim((string)($sanitized['first_name'] ?? ''));
        $sanitized['last_name'] = trim((string)($sanitized['last_name'] ?? ''));
    }

    return $sanitized;
}

function customerAccountDisplayName(array $account): string
{
    $firstName = trim((string)($account['first_name'] ?? ''));
    $lastName = trim((string)($account['last_name'] ?? ''));
    if ($firstName !== '' || $lastName !== '') {
        return trim($firstName . ' ' . $lastName);
    }

    $email = strtolower(trim((string)($account['email'] ?? '')));
    return $email !== '' ? explode('@', $email, 2)[0] : 'Customer';
}

function loadCustomerAccounts(): array
{
    $path = customerAccountsPath();
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    $accounts = json_decode($contents === false ? '' : $contents, true);
    if (!is_array($accounts)) {
        return [];
    }

    return array_values(array_map(static function ($account): array {
        if (!is_array($account)) {
            return [];
        }

        return sanitizeCustomerAccount($account);
    }, array_filter($accounts, 'is_array')));
}

function customerPasswordIsStrong(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[a-z]/', $password) === 1
        && preg_match('/[A-Z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1
        && preg_match('/[^A-Za-z0-9]/', $password) === 1;
}

function customerPasswordIsDifferentFromAdmin(string $password): bool
{
    return !appAdminPasswordMatches($password);
}

function saveCustomerAccounts(array $accounts): bool
{
    $existing = loadCustomerAccounts();
    $merged = [];

    foreach (array_merge($existing, $accounts) as $account) {
        if (!is_array($account)) {
            continue;
        }

        $sanitized = sanitizeCustomerAccount($account);
        $email = strtolower(trim((string)($sanitized['email'] ?? '')));
        if ($email !== '') {
            $merged[$email] = $sanitized;
            continue;
        }

        $merged[] = $sanitized;
    }

    $json = json_encode(array_values($merged), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json !== false && file_put_contents(customerAccountsPath(), $json, LOCK_EX) !== false;
}
