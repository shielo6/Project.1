<?php
session_start();
require_once __DIR__ . '/../account_store.php';

$error = '';
$success = (string)($_SESSION['signup_success'] ?? '');
unset($_SESSION['signup_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $middleName = trim((string)($_POST['middle_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $fullName = trim(implode(' ', array_filter([$firstName, $middleName, $lastName], static fn($name) => $name !== '')));
    $contactNumber = trim((string)($_POST['contact_number'] ?? ''));
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!preg_match('/^[\p{L}\p{M} .\'-]{1,80}$/u', $firstName)
        || !preg_match('/^[\p{L}\p{M} .\'-]{0,80}$/u', $middleName)
        || !preg_match('/^[\p{L}\p{M} .\'-]{1,80}$/u', $lastName)) {
        $error = 'Please enter valid first and last names.';
    } elseif (!preg_match('/^[0-9]{11}$/', $contactNumber)) {
        $error = 'Enter a valid 11-digit contact number.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (!customerPasswordIsStrong($password)) {
        $error = 'Use a strong password: at least 8 characters with uppercase and lowercase letters, a number, and a symbol.';
    } elseif (!customerPasswordIsDifferentFromAdmin($password)) {
        $error = 'Choose a password different from the admin password.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $account = [
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => date(DATE_ATOM),
        ];
        if (saveCustomerAccounts([$account])) {
            $_SESSION['signup_success'] = 'Your account has been created successfully. You can log in whenever you are ready.';
            header('Location: signup.php');
            exit();
        }
        $error = 'Could not save your account. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panaderia Pilipina | Sign Up</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; padding: 20px; display: grid; place-items: center; font-family: Arial, sans-serif; background: linear-gradient(135deg, #fff7ed, #fed7aa, #f59e0b); }
        .signup-box { width: min(680px, 100%); padding: 30px 25px; border: 2px solid #f59e0b; border-radius: 18px; background: #fffdf9; box-shadow: 0 14px 30px rgba(146, 64, 14, .2); }
        h1 { margin: 0 0 6px; text-align: center; color: #7c2d12; font-size: 1.7rem; }
        .subtitle { margin: 0 0 22px; text-align: center; color: #9a4d12; }
        .field { margin-bottom: 15px; }
        .name-fields { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
        label { display: block; margin-bottom: 6px; color: #7c2d12; font-weight: 700; }
        input { width: 100%; padding: 11px 12px; border: 1px solid #fbbf24; border-radius: 8px; background: #fffdf7; font-size: 14px; }
        .password-input-wrap { position: relative; }
        .password-input-wrap input { padding-right: 46px; }
        .password-input-wrap input.password-weak { border: 2px solid #dc2626; background-color: #fef2f2; box-shadow: 0 0 0 2px rgba(220, 38, 38, .14); }
        .password-input-wrap input.password-strong { border: 2px solid #16a34a; background-color: #f0fdf4; box-shadow: 0 0 0 2px rgba(22, 163, 74, .14); }
        .visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        button { width: 100%; padding: 12px; border: 0; border-radius: 10px; background: linear-gradient(135deg, #d97706, #b45309); color: #fff; font-size: 16px; font-weight: 700; cursor: pointer; }
        .password-visibility { position: absolute; top: 50%; right: 5px; display: grid; place-items: center; width: 36px; height: 36px; margin: 0; padding: 7px; transform: translateY(-50%); border: 0; border-radius: 6px; background: transparent; color: #7c2d12; }
        .password-visibility:hover { background: #fff1d6; }
        .password-visibility svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .password-visibility .eye-open { display: none; }
        .password-visibility.is-visible .eye-open { display: block; }
        .password-visibility.is-visible .eye-closed { display: none; }
        .message { margin-bottom: 15px; padding: 10px; border-radius: 8px; background: #fff7ed; color: #c2410c; border: 1px solid #fdba74; font-size: 14px; }
        .success-message { background: #f0fdf4; color: #166534; border-color: #86efac; }
        .link { display: block; margin-top: 14px; text-align: center; color: #a16207; text-decoration: none; font-weight: 600; }
        @media (max-width: 600px) { .name-fields { grid-template-columns: 1fr; gap: 0; } }
    </style>
</head>
<body>
    <main class="signup-box">
        <h1>Create Customer Account</h1>
        <p class="subtitle">Join Panaderia Pilipina</p>
        <?php if ($success !== ''): ?><div class="message success-message" role="status"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="message"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <form method="POST" autocomplete="off">
            <div class="name-fields">
                <div class="field"><label for="first_name">First Name</label><input id="first_name" name="first_name" required maxlength="80" autocomplete="off"></div>
                <div class="field"><label for="middle_name">Middle Name (Optional)</label><input id="middle_name" name="middle_name" maxlength="80" autocomplete="off"></div>
                <div class="field"><label for="last_name">Last Name</label><input id="last_name" name="last_name" required maxlength="80" autocomplete="off"></div>
            </div>
            <div class="field"><label for="contact_number">Contact Number</label><input id="contact_number" type="tel" name="contact_number" required inputmode="numeric" pattern="[0-9]{11}" minlength="11" maxlength="11" autocomplete="off"></div>
            <div class="field"><label for="email">Email</label><input id="email" type="email" name="email" required autocomplete="off"></div>
            <div class="field"><label for="password">Password</label><div class="password-input-wrap"><input id="password" type="password" name="password" required minlength="8" autocomplete="off" aria-describedby="password_strength"><button class="password-visibility" type="button" data-target="password" aria-label="Show password" title="Show password"><svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg><svg class="eye-closed" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18"></path><path d="M10.6 5.1A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a14.8 14.8 0 0 1-3.1 3.8M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.4 0 2.7-.3 3.8-.8"></path><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg></button></div><span id="password_strength" class="visually-hidden" aria-live="polite"></span></div>
            <div class="field"><label for="confirm_password">Confirm password</label><div class="password-input-wrap"><input id="confirm_password" type="password" name="confirm_password" required minlength="8" autocomplete="off"><button class="password-visibility" type="button" data-target="confirm_password" aria-label="Show password" title="Show password"><svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg><svg class="eye-closed" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18"></path><path d="M10.6 5.1A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a14.8 14.8 0 0 1-3.1 3.8M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.4 0 2.7-.3 3.8-.8"></path><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg></button></div></div>
            <button type="submit">Create Account</button>
        </form>
        <a class="link" href="customer_login.php">Back to Login</a>
    </main>
    <script>
        document.querySelectorAll('#email, #password, #confirm_password').forEach((input) => {
            input.readOnly = true;
            input.addEventListener('focus', () => {
                input.removeAttribute('readonly');
            }, { once: true });
        });

        const passwordInput = document.getElementById('password');
        const passwordStrength = document.getElementById('password_strength');
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

        passwordInput.addEventListener('input', () => {
            const password = passwordInput.value;
            if (password.length === 0) {
                passwordInput.classList.remove('password-weak', 'password-strong');
                passwordStrength.textContent = '';
                return;
            }
            const isStrong = password.length >= 8
                && /[a-z]/.test(password)
                && /[A-Z]/.test(password)
                && /[0-9]/.test(password)
                && /[^A-Za-z0-9]/.test(password);
            passwordInput.classList.toggle('password-strong', isStrong);
            passwordInput.classList.toggle('password-weak', !isStrong);
            passwordStrength.textContent = isStrong ? 'Strong password' : 'Weak password';
        });
    </script>
</body>
</html>
