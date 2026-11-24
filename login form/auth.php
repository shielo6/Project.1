<?php
session_start();
require_once __DIR__ . '/../account_store.php';
require_once __DIR__ . '/../app_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['login'])) {
    $_SESSION = [];
    session_regenerate_id(true);
    header('Location: index.php');
    exit();
}

require_once __DIR__ . '/../login_guard.php';
if (!loginGuardAllowsAttempt('login')) {
    header('Location: index.php?error=' . rawurlencode('Too many attempts. Please try again in a few minutes.'));
    exit();
}

$email = strtolower(trim((string)($_POST['email'] ?? '')));
$password = (string)($_POST['login_password'] ?? $_POST['password'] ?? '');

if (appAdminLoginSucceeds($email, $password)) {
    loginGuardReset('login');
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['role'] = 'admin';
    $_SESSION['username'] = 'admin';
    header('Location: ../admin%20dashboard/admin_dashboard.php');
    exit();
}

foreach (loadCustomerAccounts() as $account) {
    if (
        strtolower((string)($account['email'] ?? '')) === $email
        && password_verify($password, $account['password'] ?? '')
        && customerPasswordIsStrong($password)
        && customerPasswordIsDifferentFromAdmin($password)
    ) {
        loginGuardReset('login');
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['role'] = 'customer';
        $_SESSION['username'] = customerAccountDisplayName($account);
        $_SESSION['first_name'] = trim((string)($account['first_name'] ?? strtok($_SESSION['username'], ' ')));
        $_SESSION['customer_email'] = $email;
        header('Location: ../customer%20dashboard/customer_dashboard.php');
        exit();
    }
}

// The failed-attempt counter is recorded AFTER clearing the session above,
// otherwise $_SESSION = [] would wipe the throttle state we just wrote.
loginGuardRecordFailure('login');
header('Location: customer_login.php?error=Invalid+email+or+password');
exit();
