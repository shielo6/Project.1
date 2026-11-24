<?php
session_start();
require_once __DIR__ . '/../account_store.php';
require_once __DIR__ . '/../login_guard.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

$demoEmailResetEnabled = appDemoPasswordResetEnabled();
$message = '';
$messageIsError = true;
$csrfToken = $_SESSION['password_change_csrf'] ??= bin2hex(random_bytes(32));
$verifiedRole = (string)($_SESSION['password_change_role'] ?? '');
$verifiedEmail = (string)($_SESSION['password_change_email'] ?? '');
$verifiedUntil = (int)($_SESSION['password_change_expires'] ?? 0);

if ($verifiedRole !== '' && $verifiedUntil < time()) {
    unset(
        $_SESSION['password_change_role'],
        $_SESSION['password_change_email'],
        $_SESSION['password_change_expires'],
        $_SESSION['password_change_is_demo']
    );
    $verifiedRole = '';
    $verifiedEmail = '';
    $message = 'Email confirmation expired. Please enter your email again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? null;
    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $message = 'Your request could not be verified. Refresh the page and try again.';
    } elseif (($_POST['action'] ?? '') === 'verify_account') {
        if (!loginGuardAllowsAttempt('password_change')) {
            $message = 'Too many attempts. Please try again in a few minutes.';
        } else {
            $emailValue = $_POST['email'] ?? '';
            $email = is_string($emailValue) ? strtolower(trim($emailValue)) : '';
            $role = '';

            if ($demoEmailResetEnabled && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                if (hash_equals(appAdminEmail(), $email)) {
                    $role = 'admin';
                } else {
                    foreach (loadCustomerAccounts() as $account) {
                        if (strtolower((string)($account['email'] ?? '')) === $email) {
                            $role = 'customer';
                            break;
                        }
                    }
                }
            }

            if ($role === '') {
                loginGuardRecordFailure('password_change');
                $message = 'Gmail address not found. Please check the address and try again.';
            } else {
                loginGuardReset('password_change');
                session_regenerate_id(true);
                $_SESSION['password_change_role'] = $role;
                $_SESSION['password_change_email'] = $email;
                $_SESSION['password_change_expires'] = time() + 600;
                $_SESSION['password_change_is_demo'] = $demoEmailResetEnabled;
                $_SESSION['password_change_csrf'] = bin2hex(random_bytes(32));
                header('Location: forgot_password.php?stage=new-password');
                exit();
            }
        }
    } elseif (($_POST['action'] ?? '') === 'save_new_password') {
        $verifiedRole = (string)($_SESSION['password_change_role'] ?? '');
        $verifiedEmail = strtolower((string)($_SESSION['password_change_email'] ?? ''));
        $verifiedUntil = (int)($_SESSION['password_change_expires'] ?? 0);
        $newPasswordValue = $_POST['new_password'] ?? '';
        $confirmPasswordValue = $_POST['confirm_password'] ?? '';
        $newPassword = is_string($newPasswordValue) ? $newPasswordValue : '';
        $confirmPassword = is_string($confirmPasswordValue) ? $confirmPasswordValue : '';
        $currentCustomerPasswordHash = null;

        if ($verifiedRole === 'customer') {
            foreach (loadCustomerAccounts() as $account) {
                if (strtolower((string)($account['email'] ?? '')) === $verifiedEmail) {
                    $currentCustomerPasswordHash = $account['password'] ?? null;
                    break;
                }
            }
        }

        if (!in_array($verifiedRole, ['admin', 'customer'], true)
            || $verifiedEmail === ''
            || $verifiedUntil < time()
            || !array_key_exists('password_change_is_demo', $_SESSION)
            || (bool)$_SESSION['password_change_is_demo'] !== $demoEmailResetEnabled) {
            $message = 'Email confirmation expired. Please enter your email again.';
            unset(
                $_SESSION['password_change_role'],
                $_SESSION['password_change_email'],
                $_SESSION['password_change_expires'],
                $_SESSION['password_change_is_demo']
            );
        } elseif (!customerPasswordIsStrong($newPassword)) {
            $message = 'Use at least 8 characters with uppercase and lowercase letters, a number, and a symbol.';
        } elseif (!customerPasswordIsDifferentFromAdmin($newPassword)) {
            $message = 'Choose a password different from the admin password.';
        } elseif (is_string($currentCustomerPasswordHash) && password_verify($newPassword, $currentCustomerPasswordHash)) {
            $message = 'Choose a password different from your current password.';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'Passwords do not match.';
        } else {
            $saved = false;
            if ($verifiedRole === 'admin') {
                $saved = hash_equals(appAdminEmail(), $verifiedEmail)
                    && saveAdminPasswordHash($newPassword)
                    && appAdminPasswordMatches($newPassword);
            } else {
                $accounts = loadCustomerAccounts();
                foreach ($accounts as $index => $account) {
                    if (strtolower((string)($account['email'] ?? '')) === $verifiedEmail) {
                        $accounts[$index]['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
                        if (saveCustomerAccounts($accounts)) {
                            foreach (loadCustomerAccounts() as $savedAccount) {
                                if (strtolower((string)($savedAccount['email'] ?? '')) === $verifiedEmail) {
                                    $saved = password_verify($newPassword, (string)($savedAccount['password'] ?? ''));
                                    break;
                                }
                            }
                        }
                        break;
                    }
                }
            }

            if ($saved) {
                unset(
                    $_SESSION['password_change_role'],
                    $_SESSION['password_change_email'],
                    $_SESSION['password_change_expires'],
                    $_SESSION['password_change_is_demo'],
                    $_SESSION['password_change_csrf']
                );
                header('Location: customer_login.php?success=' . rawurlencode('Password updated. Please log in with your new password.'));
                exit();
            }
            $message = 'Could not update the password. Please try again.';
        }
    } else {
        $message = 'Invalid request. Please try again.';
    }

    $csrfToken = (string)($_SESSION['password_change_csrf'] ??= bin2hex(random_bytes(32)));
    $verifiedRole = (string)($_SESSION['password_change_role'] ?? '');
    $verifiedEmail = (string)($_SESSION['password_change_email'] ?? '');
}

$showNewPasswordForm = $verifiedRole !== ''
    && $verifiedEmail !== ''
    && (int)($_SESSION['password_change_expires'] ?? 0) >= time()
    && array_key_exists('password_change_is_demo', $_SESSION)
    && (bool)$_SESSION['password_change_is_demo'] === $demoEmailResetEnabled
    && ($_GET['stage'] ?? '') === 'new-password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panaderia Pilipina | Password Reset</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; padding: 20px; display: grid; place-items: center; font-family: Arial, sans-serif; background: linear-gradient(135deg, #fff7ed, #fed7aa, #f59e0b); }
        .recovery-box { width: min(430px, 100%); padding: 30px 25px; border: 2px solid #f59e0b; border-radius: 18px; background: #fffdf9; box-shadow: 0 14px 30px rgba(146, 64, 14, .2); }
        h1 { margin: 0 0 8px; text-align: center; color: #7c2d12; font-size: 1.7rem; }
        .subtitle { margin: 0 0 20px; text-align: center; color: #78716c; line-height: 1.5; font-size: .9rem; }
        .field { margin-bottom: 15px; }
        label { display: block; margin-bottom: 6px; color: #7c2d12; font-weight: 700; }
        input, select { width: 100%; padding: 11px 12px; border: 1px solid #fbbf24; border-radius: 8px; background: #fffdf7; font-size: 14px; }
        .password-input-wrap { position: relative; }
        .password-input-wrap input { padding-right: 46px; }
        .password-input-wrap input.password-weak { border: 2px solid #dc2626; background-color: #fef2f2; box-shadow: 0 0 0 2px rgba(220, 38, 38, .14); }
        .password-input-wrap input.password-strong { border: 2px solid #16a34a; background-color: #f0fdf4; box-shadow: 0 0 0 2px rgba(22, 163, 74, .14); }
        .password-hint { margin: 7px 0 0; color: #78716c; font-size: .82rem; line-height: 1.4; }
        button, .link { display: block; width: 100%; padding: 12px; border: 0; border-radius: 10px; background: linear-gradient(135deg, #d97706, #b45309); color: #fff; text-align: center; text-decoration: none; font-size: 16px; font-weight: 700; cursor: pointer; }
        .password-visibility { position: absolute; top: 50%; right: 5px; display: grid; place-items: center; width: 36px; height: 36px; margin: 0; padding: 7px; transform: translateY(-50%); border: 0; border-radius: 6px; background: transparent; color: #7c2d12; }
        .password-visibility:hover { background: #fff1d6; }
        .password-visibility svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .password-visibility .eye-open { display: none; }
        .password-visibility.is-visible .eye-open { display: block; }
        .password-visibility.is-visible .eye-closed { display: none; }
        .message { margin-bottom: 15px; padding: 10px; border-radius: 8px; font-size: 14px; }
        .message.error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .link { margin-top: 14px; background: transparent; color: #a16207; font-size: 14px; }
    </style>
</head>
<body>
    <main class="recovery-box">
        <h1><?php echo $showNewPasswordForm ? 'Set New Password' : 'Forgot Password?'; ?></h1>
        <p class="subtitle"><?php echo $showNewPasswordForm
            ? 'Enter a new password for the Gmail account below.'
            : ($demoEmailResetEnabled
                ? 'Demo mode: enter your registered Gmail address to continue.'
                : 'Email-only password reset is disabled outside demo mode. Please contact the administrator.'); ?></p>
        <?php if ($message !== ''): ?>
            <p class="message error" role="alert"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <?php if ($showNewPasswordForm): ?>
            <form method="post" action="forgot_password.php?stage=new-password">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="save_new_password">
                <div class="field">
                    <label for="verified_email">Gmail</label>
                    <input id="verified_email" type="email" value="<?php echo htmlspecialchars($verifiedEmail, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="email" readonly>
                </div>
                <div class="field">
                    <label for="new_password">New password</label>
                    <div class="password-input-wrap">
                        <input id="new_password" name="new_password" type="password" minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}" title="Use at least 8 characters with uppercase and lowercase letters, a number, and a symbol." autocomplete="new-password" required>
                        <button class="password-visibility" type="button" data-target="new_password" aria-label="Show password" title="Show password">
                            <svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            <svg class="eye-closed" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18"></path><path d="M10.6 5.1A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a14.8 14.8 0 0 1-3.1 3.8M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.4 0 2.7-.3 3.8-.8"></path><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg>
                        </button>
                    </div>
                    <p class="password-hint">At least 8 characters, including uppercase, lowercase, a number, and a symbol.</p>
                </div>
                <div class="field">
                    <label for="confirm_password">Confirm password</label>
                    <div class="password-input-wrap">
                        <input id="confirm_password" name="confirm_password" type="password" minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}" title="Use a strong password that matches the new password." autocomplete="new-password" required>
                        <button class="password-visibility" type="button" data-target="confirm_password" aria-label="Show password" title="Show password">
                            <svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            <svg class="eye-closed" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18"></path><path d="M10.6 5.1A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a14.8 14.8 0 0 1-3.1 3.8M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.4 0 2.7-.3 3.8-.8"></path><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg>
                        </button>
                    </div>
                    <p class="password-hint">Must meet the same requirements and match the new password.</p>
                </div>
                <button type="submit">Update Password</button>
            </form>
        <?php elseif ($demoEmailResetEnabled): ?>
            <form method="post" action="forgot_password.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="verify_account">
                <div class="field">
                    <label for="email">Gmail</label>
                    <input id="email" name="email" type="email" placeholder="name@gmail.com" autocomplete="email" required>
                </div>
                <button type="submit">Confirm Email</button>
            </form>
        <?php endif; ?>
        <a class="link" href="customer_login.php">Back to Log In</a>
    </main>
    <script>
        document.querySelectorAll('.password-visibility').forEach((toggleButton) => {
            toggleButton.addEventListener('click', () => {
                const input = document.getElementById(toggleButton.dataset.target);
                const isVisible = input.type === 'password';
                input.type = isVisible ? 'text' : 'password';
                toggleButton.classList.toggle('is-visible', isVisible);
                toggleButton.setAttribute('aria-label', isVisible ? 'Hide password' : 'Show password');
                toggleButton.title = isVisible ? 'Hide password' : 'Show password';
            });
        });

        const newPasswordInput = document.getElementById('new_password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        if (newPasswordInput && confirmPasswordInput) {
            const passwordIsStrong = (password) => password.length >= 8
                && /[a-z]/.test(password)
                && /[A-Z]/.test(password)
                && /[0-9]/.test(password)
                && /[^A-Za-z0-9]/.test(password);

            const updatePasswordState = (input, isValid) => {
                const hasValue = input.value.length > 0;
                input.classList.toggle('password-strong', hasValue && isValid);
                input.classList.toggle('password-weak', hasValue && !isValid);
            };

            const updatePasswordValidation = () => {
                const newPasswordIsStrong = passwordIsStrong(newPasswordInput.value);
                updatePasswordState(newPasswordInput, newPasswordIsStrong);
                updatePasswordState(
                    confirmPasswordInput,
                    passwordIsStrong(confirmPasswordInput.value)
                        && confirmPasswordInput.value === newPasswordInput.value
                );
            };

            newPasswordInput.addEventListener('input', updatePasswordValidation);
            confirmPasswordInput.addEventListener('input', updatePasswordValidation);
        }
    </script>
</body>
</html>
