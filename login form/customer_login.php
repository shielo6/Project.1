<?php
session_start();
require_once __DIR__ . '/../app_config.php';
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    header('Location: ../admin%20dashboard/admin_dashboard.php');
    exit();
}
if (isset($_SESSION['role']) && $_SESSION['role'] === 'customer') {
    header('Location: ../customer%20dashboard/customer_dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panaderia Pilipina | Customer Login</title>
    <link rel="preload" as="image" href="../Image/bread-basket-logo.jpg" fetchpriority="high">
    <style>
        * { box-sizing: border-box; }
        html {
            min-height: 100%;
            background-color: #fed7aa;
        }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background-color: #fed7aa;
            background-image: linear-gradient(135deg, #fff7ed 0%, #fed7aa 52%, #f59e0b 100%);
            background-repeat: no-repeat;
            background-position: center top;
            background-size: cover;
            background-attachment: fixed;
            display: flex;
            align-items: safe center;
            justify-content: center;
            min-height: 100vh;
            min-height: 100svh;
            padding: 20px;
        }
        .login-box {
            background: linear-gradient(155deg, #fff7ed, #ffedd5 58%, #fed7aa);
            width: min(390px, 100%);
            padding: 30px 25px;
            border-radius: 18px;
            box-shadow: 0 14px 30px rgba(146, 64, 14, 0.2);
            border: 2px solid #f59e0b;
        }
        .login-header {
            margin: -12px -8px 22px;
            padding: 18px 12px 14px;
            border: 1px solid #fbbf24;
            border-radius: 14px;
            background: linear-gradient(145deg, #7c2d12, #9a3412 58%, #b45309);
            box-shadow: 0 7px 16px rgba(124, 45, 18, .18);
        }
        .logo {
            width: min(100%, 320px);
            aspect-ratio: 573 / 360;
            margin: 0 auto 12px;
            overflow: hidden;
            border-radius: 12px;
            background: transparent;
        }
        .logo img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: inherit;
        }
        .brand {
            margin-top: 0;
            text-align: center;
            color: #fff7ed;
            font-size: clamp(1.5rem, 4vw, 1.9rem);
            margin-bottom: 4px;
        }
        .subtitle {
            text-align: center;
            margin: 0;
            color: #ffedd5;
            font-size: 0.95rem;
            font-weight: 600;
        }
        .field {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 6px;
            color: #7c2d12;
            font-weight: 600;
        }
        input {
            width: 100%;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #fbbf24;
            box-sizing: border-box;
            font-size: 14px;
            background: #fffdf7;
        }
        .password-input-wrap { position: relative; }
        .password-input-wrap input { padding-right: 46px; }
        button {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #d97706, #b45309);
            color: #fff;
            font-size: 16px;
            cursor: pointer;
            font-weight: 700;
            margin-top: 4px;
        }
        button:hover {
            background: linear-gradient(135deg, #b45309, #92400e);
        }
        button.password-visibility {
            position: absolute;
            top: 50%;
            right: 5px;
            display: grid;
            place-items: center;
            width: 36px;
            height: 36px;
            margin: 0;
            padding: 7px;
            transform: translateY(-50%);
            border: 0;
            border-radius: 6px;
            background: transparent;
            color: #7c2d12;
        }
        button.password-visibility:hover { background: #fff1d6; }
        .password-visibility svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .password-visibility .eye-open { display: none; }
        .password-visibility.is-visible .eye-open { display: block; }
        .password-visibility.is-visible .eye-closed { display: none; }
        .password-input-wrap input.password-weak { border: 2px solid #dc2626; background-color: #fef2f2; box-shadow: 0 0 0 2px rgba(220, 38, 38, .14); }
        .password-input-wrap input.password-strong { border: 2px solid #16a34a; background-color: #f0fdf4; box-shadow: 0 0 0 2px rgba(22, 163, 74, .14); }
        .visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        .error {
            background: #fff7ed;
            color: #c2410c;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 14px;
            border: 1px solid #fdba74;
        }
        .success {
            background: #ecfdf5;
            color: #166534;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 14px;
            border: 1px solid #bbf7d0;
        }
        .link {
            display: block;
            margin-top: 14px;
            text-align: center;
            color: #a16207;
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <div class="login-header">
            <div class="logo">
                <img src="../Image/bread-basket-logo.jpg" alt="Basket filled with assorted breads" loading="eager" decoding="async" fetchpriority="high">
            </div>
            <h2 class="brand">Panaderia Pilipina</h2>
            <p class="subtitle">Customer &amp; Admin Login</p>
        </div>

        <?php if (isset($_GET['error'])): ?>
            <div class="error"><?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['success'])): ?>
            <div class="success"><?php echo htmlspecialchars($_GET['success']); ?></div>
        <?php endif; ?>

        <form action="../auth.php" method="POST" autocomplete="on">
            <div class="field">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" autocomplete="username" required>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <div class="password-input-wrap">
                    <input type="password" id="password" name="login_password" autocomplete="current-password" required aria-describedby="password_strength">
                    <button class="password-visibility" type="button" id="toggle_password" aria-label="Show password" title="Show password">
                        <svg id="eye_open" class="eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        <svg id="eye_closed" class="eye-closed" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18"></path><path d="M10.6 5.1A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a14.8 14.8 0 0 1-3.1 3.8M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.4 0 2.7-.3 3.8-.8"></path><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg>
                    </button>
                </div>
                <span id="password_strength" class="visually-hidden" aria-live="polite"></span>
            </div>
            <button type="submit" name="login" value="1">Log In</button>
        </form>

        <a class="link" href="signup.php">Sign Up</a>
        <a class="link" href="forgot_password.php">Forgot Password?</a>
    </div>
    <script>
        const passwordInput = document.getElementById('password');
        const passwordToggle = document.getElementById('toggle_password');
        const passwordStrength = document.getElementById('password_strength');

        passwordToggle.addEventListener('click', () => {
            const isVisible = passwordInput.type === 'password';
            passwordInput.type = isVisible ? 'text' : 'password';
            passwordToggle.setAttribute('aria-label', isVisible ? 'Hide password' : 'Show password');
            passwordToggle.title = isVisible ? 'Hide password' : 'Show password';
            passwordToggle.classList.toggle('is-visible', isVisible);
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
