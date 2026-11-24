<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login%20form/admin_login.php?error=Please+login+as+admin');
    exit();
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ../login%20form/index.php');
    exit();
}

require_once __DIR__ . '/../order_store.php';
require_once __DIR__ . '/../utang_store.php';
require_once __DIR__ . '/../inventory_store.php';
require_once __DIR__ . '/../account_store.php';
$serverOrders = loadAllCustomerOrders();
$debtCsrfToken = $_SESSION['debt_csrf_token'] ??= bin2hex(random_bytes(32));
$inventoryCsrfToken = $_SESSION['inventory_csrf_token'] ??= bin2hex(random_bytes(32));
$customerPasswordResetCsrfToken = $_SESSION['customer_password_reset_csrf_token'] ??= bin2hex(random_bytes(32));
$customerPasswordResetMessage = (string)($_SESSION['customer_password_reset_message'] ?? '');
$customerPasswordResetMessageIsError = !empty($_SESSION['customer_password_reset_message_is_error']);
unset($_SESSION['customer_password_reset_message'], $_SESSION['customer_password_reset_message_is_error']);
$debtError = '';
$isInventoryAjaxRequest = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['customer_password_reset'])) {
    $resetMessage = '';
    $resetMessageIsError = true;
    $submittedToken = $_POST['customer_password_reset_csrf_token'] ?? null;
    $emailValue = $_POST['customer_email'] ?? '';
    $newPasswordValue = $_POST['new_password'] ?? '';
    $confirmPasswordValue = $_POST['confirm_password'] ?? '';
    $email = is_string($emailValue) ? strtolower(trim($emailValue)) : '';
    $newPassword = is_string($newPasswordValue) ? $newPasswordValue : '';
    $confirmPassword = is_string($confirmPasswordValue) ? $confirmPasswordValue : '';

    if (!is_string($submittedToken) || !hash_equals($customerPasswordResetCsrfToken, $submittedToken)) {
        $resetMessage = 'Invalid request. Refresh the page and try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $resetMessage = 'Select a valid customer account.';
    } elseif (!customerPasswordIsStrong($newPassword)) {
        $resetMessage = 'Use at least 8 characters with uppercase and lowercase letters, a number, and a symbol.';
    } elseif (!customerPasswordIsDifferentFromAdmin($newPassword)) {
        $resetMessage = 'Choose a password different from the admin password.';
    } elseif ($newPassword !== $confirmPassword) {
        $resetMessage = 'Passwords do not match.';
    } else {
        $customerAccounts = loadCustomerAccounts();
        $accountIndex = null;
        foreach ($customerAccounts as $index => $account) {
            if (strtolower((string)($account['email'] ?? '')) === $email) {
                $accountIndex = $index;
                break;
            }
        }

        if ($accountIndex === null) {
            $resetMessage = 'The selected customer account could not be found.';
        } else {
            $customerAccounts[$accountIndex]['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
            if (saveCustomerAccounts($customerAccounts)) {
                $resetMessage = 'Customer password updated successfully.';
                $resetMessageIsError = false;
            } else {
                $resetMessage = 'Could not update the customer password. Please try again.';
            }
        }
    }

    $_SESSION['customer_password_reset_message'] = $resetMessage;
    $_SESSION['customer_password_reset_message_is_error'] = $resetMessageIsError;
    header('Location: admin_dashboard.php#customers');
    exit();
}

$customerAccounts = loadCustomerAccounts();

if (($_GET['inventory_json'] ?? '') === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(loadSharedInventory(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inventory_action'])) {
    $submittedToken = $_POST['inventory_csrf_token'] ?? null;
    $inventoryResponseStatus = 422;
    $inventoryError = 'Invalid inventory request.';
    if (!is_string($submittedToken) || !hash_equals($inventoryCsrfToken, $submittedToken)) {
        $inventoryResponseStatus = 403;
        $inventoryError = 'Invalid request. Refresh the page and try again.';
    } elseif ($_POST['inventory_action'] === 'update') {
        $oldNameValue = $_POST['old_name'] ?? '';
        $newNameValue = $_POST['name'] ?? '';
        $stockValue = $_POST['stock'] ?? '';
        $oldName = is_string($oldNameValue) ? trim($oldNameValue) : '';
        $newName = is_string($newNameValue) ? trim($newNameValue) : '';
        $stockText = is_string($stockValue) ? $stockValue : '';
        if ($oldName === '' || $newName === '' || strlen($newName) > 100 || !preg_match('/\A\d{1,7}\z/', $stockText) || (int)$stockText > 1000000) {
            $inventoryError = 'Enter a valid bread name and stock quantity between 0 and 1,000,000.';
        } else {
            try {
                $updatedInventory = updateSharedInventoryItem($oldName, $newName, (int)$stockText);
                if ($isInventoryAjaxRequest) {
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode(['ok' => true, 'inventory' => $updatedInventory], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    exit();
                }
                header('Location: admin_dashboard.php#inventory');
                exit();
            } catch (InvalidArgumentException $exception) {
                $inventoryError = $exception->getMessage();
            } catch (RuntimeException | JsonException $exception) {
                error_log('Inventory update failed: ' . $exception->getMessage());
                $inventoryResponseStatus = 500;
                $inventoryError = 'Inventory could not be saved. Please try again later.';
            }
        }
    }
    if ($isInventoryAjaxRequest) {
        http_response_code($inventoryResponseStatus);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => $inventoryError], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit();
    }
}
$serverInventory = loadSharedInventory();
$isDebtAjaxRequest = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

function debtSummary(array $records): array
{
    $count = 0;
    $totalCents = 0;
    foreach ($records as $record) {
        if (($record['status'] ?? '') === 'unpaid') {
            $count++;
            $totalCents += max(0, (int)($record['amountCents'] ?? 0));
        }
    }

    return ['count' => $count, 'totalCents' => $totalCents];
}

function sendDebtAjaxResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['debt_action'])) {
    $debtResponseStatus = 422;
    $submittedCsrfToken = $_POST['debt_csrf_token'] ?? null;
    if (!is_string($submittedCsrfToken) || !hash_equals($debtCsrfToken, $submittedCsrfToken)) {
        http_response_code(403);
        $debtResponseStatus = 403;
        $debtError = 'Invalid request. Refresh the page and try again.';
    } else {
        try {
            if ($_POST['debt_action'] === 'add') {
                $nameValue = $_POST['debt_name'] ?? '';
                $amountValue = $_POST['debt_amount'] ?? '';
                $noteValue = $_POST['debt_note'] ?? '';
                $name = is_string($nameValue) ? trim($nameValue) : '';
                $amount = is_string($amountValue) ? trim($amountValue) : '';
                $note = is_string($noteValue) ? trim($noteValue) : '';
                if ($name === '' || strlen($name) > 100 || strlen($note) > 500 || !preg_match('/\A(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?\z/', $amount)) {
                    $debtError = 'Enter a name and valid debt amount. Names can be up to 100 characters and notes up to 500.';
                } else {
                    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
                    $amountCents = ((int)$whole * 100) + (int)str_pad($fraction, 2, '0');
                    if ($amountCents < 1) {
                        $debtError = 'The debt amount must be greater than zero.';
                    } else {
                        $record = addDebtRecord($name, $amountCents, $note);
                        if ($isDebtAjaxRequest) {
                            $summary = debtSummary(loadDebtRecords());
                            $record['dateLabel'] = date('M j, Y', strtotime($record['createdAt']));
                            $record['amountLabel'] = 'PHP ' . number_format($record['amountCents'] / 100, 2);
                            sendDebtAjaxResponse(['ok' => true, 'record' => $record, 'summary' => $summary]);
                        }
                        header('Location: admin_dashboard.php#debts');
                        exit();
                    }
                }
            } elseif ($_POST['debt_action'] === 'mark_paid') {
                $debtIdValue = $_POST['debt_id'] ?? '';
                $debtId = is_string($debtIdValue) ? $debtIdValue : '';
                if (!preg_match('/\A[a-f0-9]{32}\z/', $debtId) || !markDebtRecordPaid($debtId)) {
                    $debtError = 'The selected debt could not be found or has already been paid.';
                } else {
                    if ($isDebtAjaxRequest) {
                        sendDebtAjaxResponse(['ok' => true, 'recordId' => $debtId, 'summary' => debtSummary(loadDebtRecords())]);
                    }
                    header('Location: admin_dashboard.php#debts');
                    exit();
                }
            } else {
                $debtResponseStatus = 400;
                $debtError = 'This action is not supported.';
            }
        } catch (RuntimeException | JsonException $exception) {
            error_log('Debt record operation failed: ' . $exception->getMessage());
            $debtResponseStatus = 500;
            $debtError = 'The debt record could not be saved. Please try again later.';
        }
    }
    if ($isDebtAjaxRequest && $debtError !== '') {
        sendDebtAjaxResponse(['ok' => false, 'error' => $debtError], $debtResponseStatus);
    }
}

try {
    $debtRecords = loadDebtRecords();
} catch (RuntimeException | JsonException $exception) {
    error_log('Debt records could not be loaded: ' . $exception->getMessage());
    $debtRecords = null;
    $debtError = 'Debt records could not be loaded. Check the server log or try again later.';
}
$unpaidDebtCount = 0;
$unpaidDebtTotalCents = 0;
if (is_array($debtRecords)) {
    foreach ($debtRecords as $debtRecord) {
        if (($debtRecord['status'] ?? '') === 'unpaid') {
            $unpaidDebtCount++;
            $unpaidDebtTotalCents += max(0, (int)($debtRecord['amountCents'] ?? 0));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panaderia Pilipina | Admin Dashboard</title>
    <style>
        :root {
            --brown: #7c2d12;
            --dark-brown: #431407;
            --gold: #d97706;
            --cream: #fffaf0;
            --ink: #292524;
            --muted: #78716c;
            --line: #f1d9b5;
            --success: #15803d;
            --danger: #b91c1c;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #fff7ed;
            color: var(--ink);
            background-attachment: fixed;
            overflow-x: hidden;
            scroll-behavior: smooth;
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--dark-brown);
            color: #fff;
            padding: 18px 5vw;
        }
        .brand {
            font-size: 1.4rem;
            font-weight: 800;
            letter-spacing: .04em;
        }
        .brand span {
            display: block;
            font-size: .72rem;
            letter-spacing: .16em;
            color: #fbbf24;
            font-weight: 600;
            margin-top: 4px;
        }
        .top-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .admin-pill {
            color: #fff;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.25);
            padding: 8px 12px;
            border-radius: 999px;
            font-size: .85rem;
            font-weight: 700;
        }
        .logout-btn {
            color: #fff;
            text-decoration: none;
            border: 1px solid #fbbf24;
            border-radius: 7px;
            padding: 9px 14px;
            font-weight: 700;
        }
        .layout {
            display: grid;
            grid-template-columns: 220px 1fr;
            min-height: calc(100vh - 80px);
        }
        .sidebar {
            background: var(--dark-brown);
            color: #fff;
            padding: 24px 18px;
        }
        .nav-group {
            margin-bottom: 26px;
        }
        .nav-title {
            color: #fbbf24;
            font-size: .72rem;
            letter-spacing: .12em;
            text-transform: uppercase;
            margin-bottom: 10px;
            font-weight: 800;
        }
        .nav-item {
            display: block;
            color: #fff;
            text-decoration: none;
            padding: 10px 12px;
            border-radius: 8px;
            margin-bottom: 8px;
            background: rgba(255,255,255,0.04);
            font-weight: 700;
        }
        .nav-item[hidden] { display: none; }
        .nav-item.active {
            background: rgba(251,191,36,0.18);
            border: 1px solid rgba(251,191,36,0.45);
        }
        .content {
            padding: 26px;
        }
        .welcome {
            margin-bottom: 24px;
        }
        .eyebrow {
            margin: 0 0 6px;
            color: var(--gold);
            font-size: .78rem;
            letter-spacing: .12em;
            text-transform: uppercase;
            font-weight: 800;
        }
        h1 {
            margin: 0;
            color: var(--brown);
            font-size: clamp(2rem, 4vw, 2.7rem);
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(180px, 1fr));
            gap: 16px;
            margin: 24px 0;
            width: 100%;
        }
        .stat-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 20px;
        }
        .payout-list {
            display: grid;
            gap: 12px;
            margin-top: 16px;
        }
        .payout-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid var(--line);
            background: var(--cream);
            border-radius: 10px;
            padding: 12px 14px;
        }
        .payout-item strong {
            color: var(--brown);
        }
        .payout-value {
            color: var(--muted);
            font-weight: 700;
        }
        .stat-label {
            color: var(--muted);
            font-size: .8rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            font-weight: 700;
        }
        .stat-value {
            color: var(--brown);
            font-size: 1.8rem;
            font-weight: 800;
            margin-top: 10px;
        }
        .best-seller-list { display: grid; gap: 6px; margin: 12px 0 0; padding: 0; list-style: none; }
        .best-seller-list li { display: flex; justify-content: space-between; gap: 8px; color: var(--brown); font-size: .82rem; font-weight: 700; }
        .best-seller-list li span:last-child { color: var(--muted); white-space: nowrap; }
        .sales-panel { margin-top: 20px; }
        .sales-summary { margin: 0 0 18px; color: var(--muted); }
        .sales-chart { display: flex; align-items: end; gap: 8px; min-height: 280px; padding: 8px 2px 0; overflow-x: auto; border-bottom: 1px solid var(--line); }
        .sales-row { display: grid; flex: 1 0 62px; grid-template-rows: 20px 1fr 42px; align-items: end; gap: 7px; height: 260px; min-width: 62px; }
        .sales-name { display: flex; align-items: start; justify-content: center; color: var(--brown); font-size: .7rem; font-weight: 700; line-height: 1.15; text-align: center; overflow-wrap: anywhere; }
        .sales-track { display: flex; align-items: end; justify-content: center; width: 100%; height: 100%; overflow: hidden; border-radius: 6px 6px 0 0; background: #f5e8d3; }
        .sales-bar { width: 72%; min-height: 0; border-radius: 6px 6px 0 0; background: var(--gold); }
        .sales-row.is-best .sales-bar { background: var(--success); }
        .sales-row.is-lowest .sales-bar { background: #a8a29e; }
        .sales-count { color: var(--muted); font-size: .78rem; font-weight: 700; text-align: center; }
        .content-grid {
            display: grid;
            grid-template-columns: 1.55fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        .panel {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 22px;
        }
        .panel:not([hidden]) {
            grid-column: 1 / -1;
            min-height: 300px;
            width: 100%;
        }
        .overview-panel-scroll {
            max-height: calc(100vh - 132px);
            overflow-y: auto;
            overscroll-behavior-y: contain;
            padding: 4px 10px 14px 4px;
        }
        #orders {
            max-height: calc(100vh - 132px);
            overflow: auto;
            overscroll-behavior: contain;
        }
        @media (max-width: 860px) {
            .overview-panel-scroll {
                max-height: none;
                overflow-y: visible;
            }
            #orders {
                max-height: calc(100vh - 132px);
            }
        }
        .panel h2 {
            margin-top: 0;
            color: var(--brown);
            font-size: 1.2rem;
        }
        .orders-heading, .payments-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }
        .orders-heading .cta, .payments-heading .cta {
            margin-top: 0;
            white-space: nowrap;
        }
        .payments-action {
            display: flex;
            justify-content: flex-end;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: .94rem;
        }
        th, td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid #f5e8d3;
        }
        th {
            color: var(--muted);
            font-size: .74rem;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 999px;
            font-weight: 700;
            font-size: .75rem;
        }
        .status.success {
            background: #ecfdf5;
            color: var(--success);
        }
        .status.pending {
            background: #fff7ed;
            color: var(--gold);
        }
        .status.out-of-stock {
            background: #fef2f2;
            color: var(--danger);
        }
        .product-list {
            display: grid;
            gap: 12px;
            margin-top: 10px;
        }
        .product-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--cream);
        }
        .product-name {
            font-weight: 700;
            color: var(--brown);
        }
        .stock {
            color: var(--muted);
            font-size: .85rem;
        }
        .inventory-table {
            margin-top: 10px;
        }
        .payment-proof-thumbnail {
            display: block;
            width: 120px;
            height: 96px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: #fff;
            object-fit: contain;
        }
        .inventory-table td:last-child {
            text-align: right;
        }
        .action-btn {
            border: none;
            border-radius: 8px;
            padding: 7px 10px;
            font-weight: 700;
            cursor: pointer;
        }
        .action-btn.primary {
            background: var(--gold);
            color: #fff;
        }
        .action-btn.secondary {
            background: #fff;
            color: var(--brown);
            border: 1px solid var(--line);
        }
        .debt-form {
            display: grid;
            grid-template-columns: 1fr 180px 1.5fr auto;
            align-items: end;
            gap: 12px;
            margin: 18px 0 24px;
        }
        .customer-password-reset-form {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            max-width: 760px;
            margin-top: 18px;
        }
        .customer-password-reset-form .field-group:first-child,
        .customer-password-reset-form button,
        .customer-password-reset-form .customer-password-reset-note {
            grid-column: 1 / -1;
        }
        .customer-password-reset-note {
            margin: 0;
            color: var(--muted);
            font-size: .9rem;
        }
        .customer-password-reset-feedback {
            margin: 14px 0 0;
            color: var(--danger);
            font-weight: 700;
        }
        .customer-password-reset-feedback.success {
            color: var(--success);
        }
        .debt-form input {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 1rem;
        }
        .debt-error {
            margin: 12px 0;
            color: var(--danger);
            font-weight: 700;
        }
        .debt-action-form { margin: 0; }
        .debt-paid { color: var(--success); font-weight: 700; }
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(41, 37, 36, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 1000;
        }
        .modal-backdrop.open {
            display: flex;
        }
        .modal {
            background: #fff;
            border-radius: 16px;
            border: 1px solid var(--line);
            width: min(480px, 100%);
            padding: 24px;
            box-shadow: 0 18px 42px rgba(67, 20, 7, 0.18);
        }
        .modal h3 {
            margin: 0 0 18px;
            color: var(--brown);
            font-size: 1.35rem;
        }
        .form-grid {
            display: grid;
            gap: 14px;
        }
        .field-group {
            display: grid;
            gap: 6px;
        }
        .field-group label {
            color: var(--muted);
            font-size: .82rem;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .field-group input,
        .field-group select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 1rem;
        }
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        .cta {
            display: inline-block;
            margin-top: 15px;
            background: var(--gold);
            color: #fff;
            padding: 11px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 700;
        }
        @media (max-width: 860px) {
            .stats, .content-grid { grid-template-columns: 1fr; }
            .topbar { flex-wrap: wrap; }
            .debt-form { grid-template-columns: 1fr; }
            .customer-password-reset-form { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="brand">Panaderia Pilipina<span>Admin Portal</span></div>
        <div class="top-actions">
            <span class="admin-pill">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
            <a class="logout-btn" href="admin_dashboard.php?logout=1">Log Out</a>
        </div>
    </header>

    <div class="layout">
        <aside class="sidebar">
            <div class="nav-group">
                <div class="nav-title">Menu</div>
                <a class="nav-item active" href="#overview">Overview</a>
                <a class="nav-item" href="#orders">Orders</a>
                <a class="nav-item" href="#inventory">Stack and Inventory</a>
                <a class="nav-item" href="#debts">Debts</a>
                <a class="nav-item" id="payments_nav_item" href="#payments">Payments</a>
                <a class="nav-item" href="#reports">Reports</a>
            </div>
        </aside>

        <main class="content">
            <div class="overview-panel-scroll" id="overview_panel_view">
                <div id="overview_content">
                    <section class="welcome" id="overview">
                        <p class="eyebrow">Overview</p>
                        <h1>Admin Dashboard</h1>
                    </section>

                    <div class="stats">
                        <div class="stat-card">
                            <div class="stat-label">Total Orders</div>
                            <div class="stat-value" id="total_orders_value">128</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Revenue</div>
                            <div class="stat-value" id="revenue_value">₱18.4K</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Best Sellers</div>
                            <ol class="best-seller-list" id="best_seller_value"><li>No sales yet</li></ol>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Debts</div>
                            <div class="stat-value" id="debt_count_value"><?php echo is_array($debtRecords) ? $unpaidDebtCount : '—'; ?> records</div>
                            <div class="stat-label">Total Outstanding</div>
                            <div class="stat-value" id="debt_total_value">PHP <?php echo is_array($debtRecords) ? number_format($unpaidDebtTotalCents / 100, 2) : '—'; ?></div>
                        </div>
                    </div>

                    <section class="panel sales-panel" aria-labelledby="sales_chart_title">
                        <h2 id="sales_chart_title">Bread sales ranking</h2>
                        <p class="sales-summary" id="sales_summary">Loading sales data...</p>
                        <div class="sales-chart" id="sales_chart" role="list" aria-label="Bread quantities sold"></div>
                    </section>
                </div>

                <section class="panel" id="inventory" hidden>
                    <h2>Stack and Inventory</h2>
                    <table class="inventory-table">
                        <thead>
                            <tr>
                                <th>Bread</th>
                                <th>Stock</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="inventory_table_body"></tbody>
                    </table>
                </section>

                <section class="panel" id="customers" hidden>
                    <h2>Customer Accounts</h2>
                    <?php if ($customerPasswordResetMessage !== ''): ?>
                        <p class="customer-password-reset-feedback<?php echo $customerPasswordResetMessageIsError ? '' : ' success'; ?>" role="<?php echo $customerPasswordResetMessageIsError ? 'alert' : 'status'; ?>"><?php echo htmlspecialchars($customerPasswordResetMessage, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                    <?php if ($customerAccounts === []): ?>
                        <p>No customer accounts are registered yet.</p>
                    <?php else: ?>
                        <form class="customer-password-reset-form" method="post" action="admin_dashboard.php#customers">
                            <input type="hidden" name="customer_password_reset" value="1">
                            <input type="hidden" name="customer_password_reset_csrf_token" value="<?php echo htmlspecialchars($customerPasswordResetCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="field-group">
                                <label for="customer_email">Customer account</label>
                                <select id="customer_email" name="customer_email" required>
                                    <option value="">Select customer</option>
                                    <?php foreach ($customerAccounts as $customerAccount): ?>
                                        <?php
                                        $accountEmail = strtolower(trim((string)($customerAccount['email'] ?? '')));
                                        if (!filter_var($accountEmail, FILTER_VALIDATE_EMAIL)) {
                                            continue;
                                        }
                                        ?>
                                        <option value="<?php echo htmlspecialchars($accountEmail, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(customerAccountDisplayName($customerAccount) . ' — ' . $accountEmail, ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <p class="customer-password-reset-note">Set a temporary password for the selected customer and share it with them securely. Passwords are never displayed after saving.</p>
                            <div class="field-group">
                                <label for="new_customer_password">New password</label>
                                <input id="new_customer_password" name="new_password" type="password" minlength="8" autocomplete="new-password" required>
                            </div>
                            <div class="field-group">
                                <label for="confirm_customer_password">Confirm new password</label>
                                <input id="confirm_customer_password" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required>
                            </div>
                            <button class="action-btn primary" type="submit">Reset Customer Password</button>
                        </form>
                    <?php endif; ?>
                </section>

                <section class="panel" id="debts" hidden>
                    <h2>Debt Records</h2>
                    <?php if ($debtError !== ''): ?>
                        <p class="debt-error" role="alert"><?php echo htmlspecialchars($debtError, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                    <form class="debt-form" method="post" action="admin_dashboard.php#debts">
                        <input type="hidden" name="debt_csrf_token" value="<?php echo htmlspecialchars($debtCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="debt_action" value="add">
                        <div class="field-group">
                            <label for="debt_name">Name</label>
                            <input id="debt_name" name="debt_name" type="text" maxlength="100" required>
                        </div>
                        <div class="field-group">
                            <label for="debt_amount">Amount (PHP)</label>
                            <input id="debt_amount" name="debt_amount" type="number" min="0.01" max="99999999.99" step="0.01" required>
                        </div>
                        <div class="field-group">
                            <label for="debt_note">Note</label>
                            <input id="debt_note" name="debt_note" type="text" maxlength="500">
                        </div>
                        <button class="action-btn primary" type="submit">Add Debt Record</button>
                    </form>
                    <p class="debt-error" id="debt_feedback" role="alert" hidden></p>
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Note</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="debt_records_body">
                            <?php if (!is_array($debtRecords)): ?>
                                <tr><td colspan="6">Debt records could not be displayed.</td></tr>
                            <?php elseif ($debtRecords === []): ?>
                                <tr data-empty-debts><td colspan="6">No debt records yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($debtRecords as $debtRecord): ?>
                                    <?php
                                    $debtStatus = ($debtRecord['status'] ?? '') === 'paid' ? 'paid' : 'unpaid';
                                    $debtDate = strtotime((string)($debtRecord['createdAt'] ?? ''));
                                    ?>
                                    <tr data-debt-id="<?php echo htmlspecialchars((string)($debtRecord['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <td><?php echo htmlspecialchars((string)($debtRecord['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)($debtRecord['note'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo $debtDate === false ? '—' : date('M j, Y', $debtDate); ?></td>
                                        <td>PHP <?php echo number_format(max(0, (int)($debtRecord['amountCents'] ?? 0)) / 100, 2); ?></td>
                                        <td><span class="<?php echo $debtStatus === 'paid' ? 'debt-paid' : 'status pending'; ?>"><?php echo $debtStatus === 'paid' ? 'Paid' : 'Unpaid'; ?></span></td>
                                        <td>
                                            <?php if ($debtStatus === 'unpaid'): ?>
                                                <form class="debt-action-form" method="post" action="admin_dashboard.php#debts">
                                                    <input type="hidden" name="debt_csrf_token" value="<?php echo htmlspecialchars($debtCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="debt_action" value="mark_paid">
                                                    <input type="hidden" name="debt_id" value="<?php echo htmlspecialchars((string)($debtRecord['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <button class="action-btn secondary" type="submit">Mark as Paid</button>
                                                </form>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>
            </div>

            <div class="content-grid">
                <section class="panel" id="orders" hidden>
                    <div class="orders-heading">
                        <h2>Orders</h2>
                        <a class="cta" id="view_all_orders" href="#orders">View all orders</a>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Bread Purchased</th>
                                <th>Address</th>
                                <th>Delivery Description</th>
                                <th>Customer Number</th>
                                <th>Total</th>
                                <th>Payment Proof</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="admin_orders_body">
                            <tr><td colspan="10">No customer orders have been recorded yet.</td></tr>
                        </tbody>
                    </table>
                </section>

            </div>

            <div class="content-grid" style="margin-top: 20px;">
                <section class="panel" id="payments" hidden>
                    <div class="payments-heading">
                        <h2>Payments &amp; Payouts</h2>
                    </div>
                    <div class="payout-list">
                        <div class="payout-item">
                            <strong>GCash</strong>
                            <span class="payout-value" id="gcash_payout_value">₱4,200.00</span>
                        </div>
                        <div class="payout-item">
                            <strong>Maya</strong>
                            <span class="payout-value" id="maya_payout_value">₱3,150.00</span>
                        </div>
                        <div class="payout-item">
                            <strong>Cash on Delivery</strong>
                            <span class="payout-value" id="cod_payout_value">₱2,850.00</span>
                        </div>
                        <div class="payout-item">
                            <strong>Net Payout</strong>
                            <span class="payout-value" id="net_payout_value">₱10,200.00</span>
                        </div>
                        <div class="payout-item">
                            <strong>Processing Fees</strong>
                            <span class="payout-value">₱245.00</span>
                        </div>
                        <div class="payout-item">
                            <strong>Next Payout</strong>
                            <span class="payout-value">Sep 30, 2026</span>
                        </div>
                    </div>
                </section>
            </div>
            <section class="panel" id="reports" hidden style="margin-top: 20px;">
                <h2>Reports</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Period</th>
                            <th>Orders</th>
                            <th>Revenue</th>
                            <th>Top Product</th>
                        </tr>
                    </thead>
                    <tbody id="reports_table_body">
                        <tr>
                            <td>Today</td>
                            <td id="report_today_orders">0</td>
                            <td id="report_today_revenue">PHP 0.00</td>
                            <td id="report_today_top">-</td>
                        </tr>
                        <tr>
                            <td>This week</td>
                            <td id="report_week_orders">0</td>
                            <td id="report_week_revenue">PHP 0.00</td>
                            <td id="report_week_top">-</td>
                        </tr>
                        <tr>
                            <td>This month</td>
                            <td id="report_month_orders">0</td>
                            <td id="report_month_revenue">PHP 0.00</td>
                            <td id="report_month_top">-</td>
                        </tr>
                        <tr>
                            <td>Last month</td>
                            <td id="report_last_month_orders">0</td>
                            <td id="report_last_month_revenue">PHP 0.00</td>
                            <td id="report_last_month_top">-</td>
                        </tr>
                        <tr>
                            <td>Average order</td>
                            <td id="report_average_orders">PHP 0.00</td>
                            <td id="report_average_revenue">Customer orders</td>
                            <td id="report_average_top">-</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
    <div class="modal-backdrop" id="inventory_modal">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="inventory_modal_title">
            <h3 id="inventory_modal_title">Edit Inventory</h3>
            <p class="debt-error" id="inventory_error" role="alert" hidden></p>
            <form id="inventory_form">
                <div class="form-grid">
                    <div class="field-group">
                        <label for="inventory_name">Bread</label>
                        <input id="inventory_name" name="name" type="text" required>
                    </div>
                    <div class="field-group">
                        <label for="inventory_stock">Stock</label>
                        <input id="inventory_stock" name="stock" type="number" min="0" required>
                    </div>
                    <div class="field-group">
                        <label for="inventory_status">Status</label>
                        <select id="inventory_status" name="status">
                            <option value="Healthy">Healthy</option>
                            <option value="Low">Low</option>
                            <option value="Out of Stock">Out of Stock</option>
                        </select>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="action-btn secondary" id="cancel_inventory_edit">Cancel</button>
                    <button type="submit" class="action-btn primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let inventoryState = <?php echo json_encode($serverInventory, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const inventoryCsrfToken = <?php echo json_encode($inventoryCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function formatCurrency(value) {
            return 'PHP ' + Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function renderSalesChart(orders) {
            const chart = document.getElementById('sales_chart');
            const summary = document.getElementById('sales_summary');
            const quantities = Object.fromEntries(inventoryState.map((item) => [item.name, 0]));

            orders.forEach((order) => {
                Object.entries(order.items || {}).forEach(([product, quantity]) => {
                    quantities[product] = (quantities[product] || 0) + Math.max(0, Number(quantity) || 0);
                });
            });

            const rankedProducts = Object.entries(quantities).sort((first, second) => second[1] - first[1] || first[0].localeCompare(second[0]));
            const maximumSold = rankedProducts[0]?.[1] || 0;
            const lowestProduct = rankedProducts[rankedProducts.length - 1];
            chart.replaceChildren();

            if (orders.length === 0 || maximumSold === 0) {
                summary.textContent = 'No sales recorded yet.';
            } else {
                summary.textContent = 'Most sold: ' + rankedProducts[0][0] + ' (' + rankedProducts[0][1] + ') · Least sold: ' + lowestProduct[0] + ' (' + lowestProduct[1] + ')';
            }

            rankedProducts.slice().reverse().forEach(([product, quantity]) => {
                const row = document.createElement('div');
                row.className = 'sales-row';
                row.setAttribute('role', 'listitem');
                if (quantity > 0 && quantity === rankedProducts[0][1]) {
                    row.classList.add('is-best');
                }
                if (quantity === lowestProduct[1]) {
                    row.classList.add('is-lowest');
                }

                const name = document.createElement('span');
                name.className = 'sales-name';
                name.textContent = product;

                const track = document.createElement('div');
                track.className = 'sales-track';
                track.setAttribute('role', 'progressbar');
                track.setAttribute('aria-label', product + ' sold');
                track.setAttribute('aria-valuemin', '0');
                track.setAttribute('aria-valuemax', String(maximumSold));
                track.setAttribute('aria-valuenow', String(quantity));
                const bar = document.createElement('div');
                bar.className = 'sales-bar';
                bar.style.height = maximumSold > 0 && quantity > 0 ? Math.max(quantity / maximumSold * 100, 2) + '%' : '0%';
                track.appendChild(bar);

                const count = document.createElement('span');
                count.className = 'sales-count';
                count.textContent = String(quantity);
                count.title = quantity + ' sold';

                row.append(count, track, name);
                chart.appendChild(row);
            });
        }

        function parseOrderDate(dateValue) {
            const parsed = new Date(dateValue);
            return Number.isNaN(parsed.getTime()) ? null : parsed;
        }

        function getTopProductForOrders(orders) {
            const counts = {};
            orders.forEach((order) => {
                Object.entries(order.items || {}).forEach(([product, quantity]) => {
                    counts[product] = (counts[product] || 0) + Number(quantity || 0);
                });
            });
            const topProduct = Object.entries(counts).sort((first, second) => second[1] - first[1])[0];
            return topProduct ? topProduct[0] : '-';
        }

        function renderReports(orders) {
            const reportData = {
                today: [],
                week: [],
                month: [],
                lastMonth: []
            };
            const now = new Date();
            orders.forEach((order) => {
                const parsedDate = parseOrderDate(order.date);
                if (!parsedDate) {
                    return;
                }
                if (parsedDate.toDateString() === now.toDateString()) {
                    reportData.today.push(order);
                }
                const daysDifference = (now - parsedDate) / (1000 * 60 * 60 * 24);
                if (daysDifference >= 0 && daysDifference <= 7) {
                    reportData.week.push(order);
                }
                if (parsedDate.getMonth() === now.getMonth() && parsedDate.getFullYear() === now.getFullYear()) {
                    reportData.month.push(order);
                }
                const previousMonthDate = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                if (parsedDate.getMonth() === previousMonthDate.getMonth() && parsedDate.getFullYear() === previousMonthDate.getFullYear()) {
                    reportData.lastMonth.push(order);
                }
            });

            const todayRevenue = reportData.today.reduce((sum, order) => sum + Number(order.total || 0), 0);
            const weekRevenue = reportData.week.reduce((sum, order) => sum + Number(order.total || 0), 0);
            const monthRevenue = reportData.month.reduce((sum, order) => sum + Number(order.total || 0), 0);
            const lastMonthRevenue = reportData.lastMonth.reduce((sum, order) => sum + Number(order.total || 0), 0);
            const averageOrder = orders.length ? orders.reduce((sum, order) => sum + Number(order.total || 0), 0) / orders.length : 0;

            document.getElementById('report_today_orders').textContent = String(reportData.today.length);
            document.getElementById('report_today_revenue').textContent = formatCurrency(todayRevenue);
            document.getElementById('report_today_top').textContent = getTopProductForOrders(reportData.today);

            document.getElementById('report_week_orders').textContent = String(reportData.week.length);
            document.getElementById('report_week_revenue').textContent = formatCurrency(weekRevenue);
            document.getElementById('report_week_top').textContent = getTopProductForOrders(reportData.week);

            document.getElementById('report_month_orders').textContent = String(reportData.month.length);
            document.getElementById('report_month_revenue').textContent = formatCurrency(monthRevenue);
            document.getElementById('report_month_top').textContent = getTopProductForOrders(reportData.month);

            document.getElementById('report_last_month_orders').textContent = String(reportData.lastMonth.length);
            document.getElementById('report_last_month_revenue').textContent = formatCurrency(lastMonthRevenue);
            document.getElementById('report_last_month_top').textContent = getTopProductForOrders(reportData.lastMonth);

            document.getElementById('report_average_orders').textContent = formatCurrency(averageOrder);
            document.getElementById('report_average_revenue').textContent = orders.length ? 'Customer orders' : 'No orders';
            document.getElementById('report_average_top').textContent = getTopProductForOrders(orders);
        }

        function loadInventory() {
            return inventoryState.map((item) => ({ ...item }));
        }

        function normalizeInventoryStatus(stock) {
            if (Number(stock) <= 0) {
                return 'Out of Stock';
            }
            if (Number(stock) <= 10) {
                return 'Low';
            }
            return 'Healthy';
        }

        function saveInventory(items) {
            inventoryState = items.map((item) => ({
                ...item,
                stock: Number(item.stock || 0),
                status: normalizeInventoryStatus(Number(item.stock || 0))
            }));
        }

        function renderInventoryTable() {
            const tbody = document.getElementById('inventory_table_body');
            if (!tbody) {
                return;
            }

            tbody.innerHTML = '';
            const inventory = loadInventory();
            inventory.forEach((item) => {
                const safeStock = Number(item.stock || 0);
                const statusLabel = normalizeInventoryStatus(safeStock);
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${item.name}</td>
                    <td>${safeStock} pcs</td>
                    <td><span class="status ${statusLabel === 'Healthy' ? 'success' : statusLabel === 'Low' ? 'pending' : 'out-of-stock'}">${statusLabel}</span></td>
                    <td>
                        <button class="action-btn primary" type="button" data-action="edit" data-name="${item.name}">Edit</button>
                    </td>
                `;
                tbody.appendChild(row);
            });
        }

        async function refreshSharedInventory() {
            try {
                const response = await fetch('admin_dashboard.php?inventory_json=1', { credentials: 'same-origin' });
                if (!response.ok) {
                    throw new Error('Inventory refresh failed.');
                }
                saveInventory(await response.json());
                renderInventoryTable();
            } catch (error) {
                console.error('Could not refresh shared inventory.', error);
            }
        }
        window.setInterval(refreshSharedInventory, 5000);

        const inventoryModal = document.getElementById('inventory_modal');
        const inventoryForm = document.getElementById('inventory_form');
        const inventoryNameInput = document.getElementById('inventory_name');
        const inventoryStockInput = document.getElementById('inventory_stock');
        const inventoryStatusInput = document.getElementById('inventory_status');
        const inventoryError = document.getElementById('inventory_error');
        const cancelInventoryEditButton = document.getElementById('cancel_inventory_edit');
        let editedItemName = null;

        function openInventoryModal(name) {
            const inventory = loadInventory();
            const selected = inventory.find((item) => item.name === name);
            if (!selected) {
                return;
            }

            editedItemName = name;
            inventoryNameInput.value = selected.name;
            inventoryStockInput.value = selected.stock;
            inventoryStatusInput.value = normalizeInventoryStatus(Number(selected.stock || 0));
            inventoryModal.classList.add('open');
        }

        function closeInventoryModal() {
            editedItemName = null;
            inventoryForm.reset();
            inventoryModal.classList.remove('open');
        }

        document.addEventListener('click', (event) => {
            const actionButton = event.target.closest('[data-action="edit"]');
            if (actionButton) {
                openInventoryModal(actionButton.dataset.name);
            }
        });

        cancelInventoryEditButton.addEventListener('click', closeInventoryModal);
        inventoryModal.addEventListener('click', (event) => {
            if (event.target === inventoryModal) {
                closeInventoryModal();
            }
        });

        inventoryForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!editedItemName) {
                return;
            }

            const submitButton = inventoryForm.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            inventoryError.hidden = true;
            try {
                const formData = new FormData();
                formData.append('inventory_action', 'update');
                formData.append('inventory_csrf_token', inventoryCsrfToken);
                formData.append('old_name', editedItemName);
                formData.append('name', inventoryNameInput.value.trim() || editedItemName);
                formData.append('stock', inventoryStockInput.value);
                const response = await fetch('admin_dashboard.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' }
                });
                const result = await response.json();
                if (!response.ok || !result.ok) {
                    throw new Error(result.error || 'Inventory could not be saved.');
                }
                saveInventory(result.inventory);
                renderInventoryTable();
                closeInventoryModal();
            } catch (error) {
                inventoryError.textContent = error instanceof Error ? error.message : 'Inventory could not be saved.';
                inventoryError.hidden = false;
            } finally {
                submitButton.disabled = false;
            }
        });

        const exportData = document.getElementById('export_data');
        if (exportData) {
            exportData.addEventListener('click', (event) => {
                event.preventDefault();
                const rows = [
                    ['Bread', 'Stock', 'Status'],
                    ...loadInventory().map((item) => [item.name, String(item.stock), item.status || normalizeInventoryStatus(item.stock)])
                ];
                const csv = rows.map((row) => row.join(',')).join('\n');
                const download = document.createElement('a');
                download.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
                download.download = 'panaderia-inventory.csv';
                download.click();
                URL.revokeObjectURL(download.href);
            });
        }
        const inventoryPanel = document.getElementById('inventory');
        const customersPanel = document.getElementById('customers');
        const debtsPanel = document.getElementById('debts');
        const paymentsPanel = document.getElementById('payments');
        const ordersPanel = document.getElementById('orders');
        const reportsPanel = document.getElementById('reports');
        const overviewPanelView = document.getElementById('overview_panel_view');
        const overviewContent = document.getElementById('overview_content');
        const debtRecordsBody = document.getElementById('debt_records_body');
        const debtFeedback = document.getElementById('debt_feedback');
        const debtCountValue = document.getElementById('debt_count_value');
        const debtTotalValue = document.getElementById('debt_total_value');
        function updateDebtSummary(summary) {
            debtCountValue.textContent = summary.count + ' records';
            debtTotalValue.textContent = 'PHP ' + (summary.totalCents / 100).toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
        function createDebtRow(record) {
            const row = document.createElement('tr');
            row.dataset.debtId = record.id;
            [record.name, record.note || '', record.dateLabel, record.amountLabel].forEach((value) => {
                const cell = document.createElement('td');
                cell.textContent = value;
                row.appendChild(cell);
            });
            const statusCell = document.createElement('td');
            const status = document.createElement('span');
            status.className = 'status pending';
            status.textContent = 'Unpaid';
            statusCell.appendChild(status);
            row.appendChild(statusCell);
            row.appendChild(createDebtActionCell(record.id));
            return row;
        }
        function createDebtActionCell(debtId) {
            const cell = document.createElement('td');
            const form = document.createElement('form');
            form.className = 'debt-action-form';
            form.method = 'post';
            form.action = 'admin_dashboard.php#debts';
            [
                ['debt_csrf_token', <?php echo json_encode($debtCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>],
                ['debt_action', 'mark_paid'],
                ['debt_id', debtId]
            ].forEach(([name, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            });
            const button = document.createElement('button');
            button.className = 'action-btn secondary';
            button.type = 'submit';
            button.textContent = 'Mark as Paid';
            form.appendChild(button);
            cell.appendChild(form);
            return cell;
        }
        async function submitDebtForm(form) {
            const submitButton = form.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            debtFeedback.hidden = true;
            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { Accept: 'application/json' }
                });
                const result = await response.json();
                if (!response.ok || !result.ok) {
                    throw new Error(result.error || 'The debt record could not be saved. Please try again.');
                }
                if (form.classList.contains('debt-form')) {
                    debtRecordsBody.querySelector('[data-empty-debts]')?.remove();
                    debtRecordsBody.insertBefore(createDebtRow(result.record), debtRecordsBody.firstChild);
                    form.reset();
                } else {
                    const row = form.closest('tr');
                    if (row && row.dataset.debtId === result.recordId) {
                        const status = row.children[4].querySelector('span');
                        status.className = 'debt-paid';
                        status.textContent = 'Paid';
                        row.children[5].replaceChildren(document.createTextNode('—'));
                    }
                }
                updateDebtSummary(result.summary);
            } catch (error) {
                debtFeedback.textContent = error instanceof Error ? error.message : 'The debt record could not be saved. Please try again.';
                debtFeedback.hidden = false;
            } finally {
                submitButton.disabled = false;
            }
        }
        debtsPanel.addEventListener('submit', (event) => {
            const form = event.target.closest('.debt-form, .debt-action-form');
            if (!form) {
                return;
            }
            event.preventDefault();
            submitDebtForm(form);
        });
        function showSection(selectedSection) {
            const panels = [ordersPanel, inventoryPanel, customersPanel, debtsPanel, paymentsPanel, reportsPanel];
            panels.forEach((panel) => {
                if (panel) {
                    panel.hidden = true;
                }
            });
            const showOverview = selectedSection === '#overview';
            if (overviewPanelView) {
                overviewPanelView.hidden = !showOverview && selectedSection !== '#inventory' && selectedSection !== '#customers' && selectedSection !== '#debts';
            }
            if (overviewContent) {
                overviewContent.hidden = !showOverview;
            }
            const selectedPanel = document.querySelector(selectedSection);
            if (selectedPanel && panels.includes(selectedPanel)) {
                selectedPanel.hidden = false;
            }
        }
        document.querySelectorAll('.nav-item[href^="#"]').forEach((navItem) => {
            navItem.addEventListener('click', (event) => {
                event.preventDefault();
                document.querySelectorAll('.nav-item').forEach((item) => item.classList.remove('active'));
                navItem.classList.add('active');
                showSection(navItem.getAttribute('href'));
            });
        });
        const initialSection = <?php echo json_encode($customerPasswordResetMessage !== '' ? '#customers' : ($debtError !== '' ? '#debts' : '')); ?> || window.location.hash;
        if (initialSection === '#customers' || initialSection === '#debts') {
            const navItem = document.querySelector('.nav-item[href="' + initialSection + '"]');
            document.querySelectorAll('.nav-item').forEach((item) => item.classList.remove('active'));
            if (navItem) {
                navItem.classList.add('active');
            }
            showSection(initialSection);
        }
        document.querySelectorAll('a[href="#orders"]').forEach((ordersLink) => {
            ordersLink.addEventListener('click', (event) => {
                event.preventDefault();
                showSection('#orders');
            });
        });

        renderInventoryTable();

        const serverOrders = <?php echo json_encode($serverOrders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const recordedOrders = [...serverOrders];
        for (let index = localStorage.length - 1; index >= 0; index -= 1) {
            const key = localStorage.key(index);
            if (!key || !key.startsWith('panaderia_recent_orders_')) {
                continue;
            }
            localStorage.removeItem(key);
        }

        const paymentsNavItem = document.getElementById('payments_nav_item');
        renderSalesChart(serverOrders);
        if (paymentsNavItem) {
            paymentsNavItem.hidden = recordedOrders.length === 0;
        }

        if (recordedOrders.length > 0) {
            const syncedOrders = recordedOrders.map((order) => ({
                ...order,
                status: order.status === 'Pending' || !order.status ? 'Success' : order.status
            }));
            const revenue = syncedOrders.reduce((sum, order) => sum + Number(order.total || 0), 0);
            const productCounts = {};
            serverOrders.forEach((order) => {
                Object.entries(order.items || {}).forEach(([product, quantity]) => {
                    productCounts[product] = (productCounts[product] || 0) + Number(quantity || 0);
                });
            });
            const rankedProducts = Object.entries(productCounts)
                .filter(([, quantity]) => Number(quantity) > 0)
                .sort((first, second) => second[1] - first[1] || first[0].localeCompare(second[0]));
            const bestSellerCutoff = rankedProducts[Math.min(2, rankedProducts.length - 1)]?.[1] || 0;
            const bestSellers = rankedProducts.filter(([, quantity]) => bestSellerCutoff > 0 && quantity >= bestSellerCutoff);
            renderReports(syncedOrders);

            document.getElementById('total_orders_value').textContent = String(syncedOrders.length);
            document.getElementById('revenue_value').textContent = 'PHP ' + revenue.toLocaleString('en-PH', { minimumFractionDigits: 2 });
            const bestSellerList = document.getElementById('best_seller_value');
            bestSellerList.replaceChildren();
            if (bestSellers.length === 0) {
                const emptyRow = document.createElement('li');
                emptyRow.textContent = 'No sales yet';
                bestSellerList.appendChild(emptyRow);
            } else {
                bestSellers.forEach(([product, quantity]) => {
                    const row = document.createElement('li');
                    const name = document.createElement('span');
                    name.textContent = product;
                    const sales = document.createElement('span');
                    sales.textContent = quantity + ' sold';
                    row.append(name, sales);
                    bestSellerList.appendChild(row);
                });
            }
            const paymentTotals = { GCash: 0, Maya: 0, 'Cash on Delivery': 0 };
            syncedOrders.forEach((order) => {
                const paymentMethod = order.payment || 'Cash on Delivery';
                paymentTotals[paymentMethod] = (paymentTotals[paymentMethod] || 0) + Number(order.total || 0);
            });
            document.getElementById('gcash_payout_value').textContent = 'PHP ' + paymentTotals.GCash.toFixed(2);
            document.getElementById('maya_payout_value').textContent = 'PHP ' + paymentTotals.Maya.toFixed(2);
            document.getElementById('cod_payout_value').textContent = 'PHP ' + paymentTotals['Cash on Delivery'].toFixed(2);
            document.getElementById('net_payout_value').textContent = 'PHP ' + revenue.toFixed(2);

            const adminOrdersBody = document.getElementById('admin_orders_body');
            const viewAllOrdersLink = document.getElementById('view_all_orders');
            let showingAllOrders = false;
            const renderAdminOrders = () => {
                adminOrdersBody.innerHTML = '';
                const ordersToShow = showingAllOrders ? syncedOrders : syncedOrders.slice(0, 10);
                ordersToShow.forEach((order) => {
                    const row = document.createElement('tr');
                    const normalizedStatus = order.status || 'Success';
                    const purchasedBreads = Object.entries(order.items || {}).map(([product, quantity]) => product + ' x' + quantity).join(', ') || 'Not recorded';
                    const deliveryDescription = order.deliveryAddress?.locationDescription || order.locationDescription || order.description || 'Not recorded';
                    const hasPaymentProof = typeof order.paymentProof === 'string' && order.paymentProof !== '';
                    const values = [order.id || 'Order', order.date || 'Not recorded', order.customerName || order.customer || order.deliveryRecipient || 'Unknown customer', purchasedBreads, order.address || 'Not recorded', deliveryDescription, order.contactNumber || order.gcashNumber || 'Not recorded', 'PHP ' + Number(order.total || 0).toFixed(2), hasPaymentProof ? 'View proof' : 'Not provided', normalizedStatus];
                    values.forEach((value, valueIndex) => {
                        const cell = document.createElement('td');
                        if (valueIndex === 8 && hasPaymentProof) {
                            const proofUrl = 'payment_proof.php?order=' + encodeURIComponent(order.id || '');
                            const proofLink = document.createElement('a');
                            proofLink.className = 'payment-proof-link';
                            proofLink.href = proofUrl;
                            proofLink.target = '_blank';
                            proofLink.rel = 'noopener';
                            proofLink.title = 'Open full-size payment proof';
                            const proofImage = document.createElement('img');
                            proofImage.className = 'payment-proof-thumbnail';
                            proofImage.src = proofUrl;
                            proofImage.alt = 'Uploaded payment proof; open full-size image';
                            proofImage.loading = 'lazy';
                            proofLink.appendChild(proofImage);
                            cell.appendChild(proofLink);
                        } else if (valueIndex === 9) {
                            const status = document.createElement('span');
                            status.className = normalizedStatus === 'Pending' ? 'status pending' : 'status success';
                            status.textContent = value;
                            cell.appendChild(status);
                        } else {
                            cell.textContent = value;
                        }
                        row.appendChild(cell);
                    });
                    adminOrdersBody.appendChild(row);
                });
                viewAllOrdersLink.textContent = showingAllOrders ? 'Show latest 10 orders' : 'View all orders';
            };
            renderAdminOrders();
            viewAllOrdersLink.addEventListener('click', (event) => {
                event.preventDefault();
                showingAllOrders = !showingAllOrders;
                renderAdminOrders();
            });
        }
    </script>
</body>
</html>
