<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    header('Location: ../login%20form/customer_login.php?error=Please+login+as+customer');
    exit();
}

require_once __DIR__ . '/../order_store.php';
require_once __DIR__ . '/../account_store.php';
require_once __DIR__ . '/../inventory_store.php';

if (isset($_GET['logout'])) {
    foreach ($_SESSION['cart_stock_reserved'] ?? [] as $product => $quantity) {
        if (is_string($product) && (int)$quantity > 0) {
            adjustSharedInventoryStock($product, (int)$quantity);
        }
    }
    session_destroy();
    header('Location: ../login%20form/index.php');
    exit();
}

$showGreeting = !empty($_SESSION['greeting_pending']) || empty($_SESSION['greeting_shown']);
$_SESSION['greeting_shown'] = true;
unset($_SESSION['greeting_pending']);

$products = [
    'Pandesal' => 8,
    'Ensaymada' => 35,
    'Ube Bread' => 45,
    'Spanish Bread' => 12,
    'Cheese Roll' => 15,
    'Hopia' => 20,
    'Monay' => 18,
    'Pan de Coco' => 25,
    'Putok (Star Bread)' => 15,
    'Pan de Monggo' => 20,
    'Bicho-Bicho' => 18,
    'Pinagong' => 22,
    'Pan de Leche' => 25,
    'Pianono' => 30,
];
$deliveryFee = 20;
$inventoryItems = loadSharedInventory();
$inventoryByName = [];
foreach ($inventoryItems as $inventoryItem) {
    $inventoryByName[$inventoryItem['name']] = (int)$inventoryItem['stock'];
}
$cartCsrfToken = $_SESSION['cart_csrf_token'] ??= bin2hex(random_bytes(32));
$productImages = [
    'Pandesal' => '../Image/824969686_1155667566922777_8072016570240887277_n.jpg',
    'Ensaymada' => '../Image/818101773_941404519041499_6505722113530877245_n.jpg',
    'Ube Bread' => '../Image/821478025_914959798083123_2342554132276234621_n.jpg',
    'Spanish Bread' => '../Image/819523542_2854259078290746_8669366267980956231_n.jpg',
    'Cheese Roll' => '../Image/images%20%283%29.jpeg',
    'Hopia' => '../Image/823728781_1319838950089602_6463677968597898602_n.jpg',
    'Monay' => '../Image/817176278_1125760289882458_6785903130317121759_n.jpg',
    'Pan de Coco' => '../Image/slices-of-pan-de-coco-filipino-bread-roll.jpg',
    'Putok (Star Bread)' => '../Image/images%20%281%29.jpeg',
    'Pan de Monggo' => '../Image/images%20%282%29.jpeg',
    'Bicho-Bicho' => '../Image/images%20%283%29.jpeg',
    'Pinagong' => '../Image/featurred-pinagong-turtle-bread.jpg',
    'Pan de Leche' => '../Image/pan-de-leche-3.webp',
    'Pianono' => '../Image/sddefault.jpg',
];
$productDescriptions = [
    'Pandesal' => 'Soft, lightly sweet bread rolls coated with fine breadcrumbs.',
    'Ensaymada' => 'A soft, buttery sweet roll topped with sugar and cheese.',
    'Ube Bread' => 'Soft, sweet bread with the distinctive flavor of purple yam.',
    'Spanish Bread' => 'Soft bread rolls filled with a sweet, buttery crumb mixture.',
    'Cheese Roll' => 'Tender bread rolled around a savory cheese filling.',
    'Hopia' => 'A flaky Filipino pastry traditionally filled with sweet mung bean paste.',
    'Monay' => 'A hearty, mildly sweet Filipino bread with a firm crust.',
    'Pan de Coco' => 'Soft bread rolls filled with sweet shredded coconut.',
    'Putok (Star Bread)' => 'A crusty, mildly sweet Filipino bread with a signature split top.',
    'Pan de Monggo' => 'Soft bread filled with sweet mung bean paste.',
    'Bicho-Bicho' => 'Filipino fried dough, lightly crisp outside and soft inside.',
    'Pinagong' => 'A mildly sweet, dense bread traditionally shaped like a turtle.',
    'Pan de Leche' => 'Soft, fluffy bread with a gentle milky sweetness.',
    'Pianono' => 'A rolled sponge cake with a sweet filling.',
];
$productCategories = [
    'Pandesal' => 'classic',
    'Ensaymada' => 'classic',
    'Ube Bread' => 'special',
    'Spanish Bread' => 'classic',
    'Cheese Roll' => 'savory',
    'Hopia' => 'classic',
    'Monay' => 'fresh',
    'Pan de Coco' => 'classic',
    'Putok (Star Bread)' => 'classic',
    'Pan de Monggo' => 'fresh',
    'Bicho-Bicho' => 'fresh',
    'Pinagong' => 'classic',
    'Pan de Leche' => 'fresh',
    'Pianono' => 'special',
];
$allOrders = loadAllCustomerOrders();
$salesByProduct = array_fill_keys(array_keys($products), 0);
foreach ($allOrders as $order) {
    foreach (($order['items'] ?? []) as $product => $quantity) {
        if (array_key_exists($product, $salesByProduct)) {
            $salesByProduct[$product] += max(0, (int)$quantity);
        }
    }
}
$rankedSales = array_filter($salesByProduct, static fn($quantity) => $quantity > 0);
arsort($rankedSales);
$rankedQuantities = array_values($rankedSales);
$bestSellerCutoff = $rankedQuantities[min(2, count($rankedQuantities) - 1)] ?? 0;
$bestSellerProducts = $bestSellerCutoff > 0
    ? array_keys(array_filter($rankedSales, static fn($quantity) => $quantity >= $bestSellerCutoff))
    : [];
$bestSellerSet = array_fill_keys($bestSellerProducts, true);
$categories = [
    'all' => 'All Menu',
    'best_seller' => 'Best Seller',
    'classic' => 'Classic',
    'fresh' => 'Fresh Picks',
    'savory' => 'Savory',
    'special' => 'Specialty',
];
$searchQuery = trim($_GET['search'] ?? '');
$selectedCategory = $_GET['category'] ?? 'all';
if (!isset($categories[$selectedCategory])) {
    $selectedCategory = 'all';
}
if (($_GET['inventory_json'] ?? '') === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($inventoryItems, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit();
}
$filteredProducts = [];
foreach ($products as $product => $price) {
    $isBestSeller = isset($bestSellerSet[$product]);
    $matchesCategory = $selectedCategory === 'all' || ($selectedCategory === 'best_seller' ? $isBestSeller : ($productCategories[$product] ?? 'classic') === $selectedCategory);
    $searchText = strtolower($product . ' bread ' . ($productDescriptions[$product] ?? '') . ' ' . str_replace('_', ' ', $productCategories[$product] ?? '') . ($isBestSeller ? ' best seller' : ''));
    $matchesSearch = $searchQuery === '' || strpos($searchText, strtolower($searchQuery)) !== false;
    if ($matchesCategory && $matchesSearch) {
        $filteredProducts[$product] = $price;
    }
}
$featuredProductOrder = array_merge($bestSellerProducts, array_values(array_diff(array_keys($filteredProducts), $bestSellerProducts)));
$cart = $_SESSION['cart'] ?? [];
$cart = is_array($cart) ? $cart : [];
$cartError = '';
$reservedCart = $_SESSION['cart_stock_reserved'] ?? [];
if (!is_array($reservedCart)) {
    $reservedCart = [];
}
if (!isset($_SESSION['cart_stock_reserved'])) {
    foreach ($cart as $product => $quantity) {
        $quantity = max(0, (int)$quantity);
        if (!isset($products[$product]) || $quantity === 0) {
            unset($cart[$product]);
            continue;
        }
        $reserved = 0;
        while ($reserved < $quantity && adjustSharedInventoryStock($product, -1)) {
            $reserved++;
        }
        if ($reserved > 0) {
            $reservedCart[$product] = $reserved;
            $cart[$product] = $reserved;
        } else {
            unset($cart[$product]);
        }
        if ($reserved < $quantity) {
            $cartError = 'Some items in your saved cart are no longer available in that quantity. Your cart was updated to match current stock.';
        }
    }
    $_SESSION['cart'] = $cart;
    $_SESSION['cart_stock_reserved'] = $reservedCart;
}
$customerEmail = strtolower(trim((string)($_SESSION['customer_email'] ?? '')));
$customerName = trim((string)($_SESSION['username'] ?? 'Customer'));
$customerFirstName = trim((string)($_SESSION['first_name'] ?? ''));
foreach (loadCustomerAccounts() as $account) {
    $accountEmail = strtolower(trim((string)($account['email'] ?? '')));
    if ($customerEmail !== '' && $accountEmail === $customerEmail) {
        $customerName = customerAccountDisplayName($account);
        $customerFirstName = trim((string)($account['first_name'] ?? ''));
        $_SESSION['username'] = $customerName;
        $_SESSION['first_name'] = $customerFirstName;
        break;
    }
}
if ($customerEmail === '') {
    $customerEmail = strtolower($customerName);
}
$_SESSION['customer_email'] = $customerEmail;
$greetingName = $customerFirstName !== '' ? $customerFirstName : strtok($customerName, ' ');
$paymentMessage = '';
$placedOrder = null;
$isAsyncCartRequest = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $product = trim($_POST['product'] ?? '');
    $submittedCartToken = $_POST['cart_csrf_token'] ?? null;
    if (!is_string($submittedCartToken) || !hash_equals($cartCsrfToken, $submittedCartToken)) {
        $cartError = 'Invalid cart request. Refresh the page and try again.';
    } elseif (isset($products[$product]) && adjustSharedInventoryStock($product, -1)) {
        $cart[$product] = ($cart[$product] ?? 0) + 1;
        $reservedCart[$product] = ($reservedCart[$product] ?? 0) + 1;
        $_SESSION['cart'] = $cart;
        $_SESSION['cart_stock_reserved'] = $reservedCart;
    } else {
        $cartError = 'That bread is out of stock.';
    }
    $inventoryItems = loadSharedInventory();
    $inventoryByName = array_column($inventoryItems, 'stock', 'name');
    if ($cartError === '' && !$isAsyncCartRequest) {
        header('Location: customer_dashboard.php');
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cart'])) {
    $product = trim($_POST['product'] ?? '');
    $change = (int)($_POST['change'] ?? 0);
    $submittedCartToken = $_POST['cart_csrf_token'] ?? null;
    if (!is_string($submittedCartToken) || !hash_equals($cartCsrfToken, $submittedCartToken)) {
        $cartError = 'Invalid cart request. Refresh the page and try again.';
    } elseif (isset($products[$product]) && in_array($change, [-1, 1], true)) {
        if ($change === 1) {
            if (adjustSharedInventoryStock($product, -1)) {
                $cart[$product] = ($cart[$product] ?? 0) + 1;
                $reservedCart[$product] = ($reservedCart[$product] ?? 0) + 1;
            } else {
                $cartError = 'That bread is out of stock.';
            }
        } else {
            $cart[$product] = ($cart[$product] ?? 0) - 1;
            if (($reservedCart[$product] ?? 0) > 0) {
                adjustSharedInventoryStock($product, 1);
                $reservedCart[$product]--;
            }
            if ($cart[$product] <= 0) {
                unset($cart[$product], $reservedCart[$product]);
            }
        }
        $_SESSION['cart'] = $cart;
        $_SESSION['cart_stock_reserved'] = $reservedCart;
    }
    $inventoryItems = loadSharedInventory();
    $inventoryByName = array_column($inventoryItems, 'stock', 'name');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $submittedPaymentToken = $_POST['cart_csrf_token'] ?? null;
    $paymentRequestIsValid = is_string($submittedPaymentToken) && hash_equals($cartCsrfToken, $submittedPaymentToken);
    $paymentMethod = trim($_POST['payment_method'] ?? '');
    $deliveryRecipient = trim($_POST['delivery_recipient'] ?? '');
    $deliveryContactNumber = trim($_POST['delivery_contact_number'] ?? '');
    $region = trim($_POST['customer_region'] ?? '');
    $province = trim($_POST['customer_province'] ?? '');
    $city = trim($_POST['customer_city'] ?? '');
    $barangay = trim($_POST['customer_barangay'] ?? '');
    $streetAddress = trim($_POST['customer_street_address'] ?? '');
    $locationDescription = trim($_POST['customer_location_description'] ?? '');
    $customerAddress = implode(', ', array_filter([
        $streetAddress,
        $locationDescription !== '' ? 'Landmark: ' . $locationDescription : '',
        $barangay !== '' ? 'Brgy. ' . $barangay : '',
        $city,
        $province,
        $region,
    ], static fn($part) => $part !== ''));
    $addressIsComplete = $deliveryRecipient !== ''
        && preg_match('/^[0-9]{11}$/', $deliveryContactNumber)
        && $region !== ''
        && $city !== ''
        && $barangay !== ''
        && $streetAddress !== '';
    $paymentProofUpload = null;
    $paymentProofFilename = null;
    $paymentProofError = $paymentRequestIsValid ? '' : 'Invalid payment request. Refresh the page and try again.';
    if ($paymentRequestIsValid && in_array($paymentMethod, ['GCash', 'Maya'], true)) {
        $uploadedFile = $_FILES['payment_proof'] ?? null;
        if (!is_array($uploadedFile) || !isset($uploadedFile['error']) || !is_int($uploadedFile['error'])) {
            $paymentProofError = 'Could not receive the payment proof. Please try uploading it again.';
        } elseif ($uploadedFile['error'] === UPLOAD_ERR_NO_FILE) {
            $paymentProofError = 'Upload an image proof of payment for GCash or Maya.';
        } elseif ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            $paymentProofError = 'The payment proof upload failed. Please try another image.';
        } else {
            $temporaryFile = $uploadedFile['tmp_name'] ?? null;
            $fileSize = $uploadedFile['size'] ?? null;
            if (!is_string($temporaryFile) || !is_uploaded_file($temporaryFile)) {
                $paymentProofError = 'Could not verify the uploaded payment proof. Please try again.';
            } elseif (!is_int($fileSize) || $fileSize < 1 || $fileSize > 2 * 1024 * 1024) {
                $paymentProofError = 'The payment proof image must be no larger than 2 MB.';
            } else {
                $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($fileInfo === false) {
                    $paymentProofError = 'Could not validate the payment proof image. Please try again.';
                } else {
                    $mimeType = finfo_file($fileInfo, $temporaryFile);
                    finfo_close($fileInfo);
                    $imageExtensions = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp',
                    ];
                    $imageInfo = @getimagesize($temporaryFile);
                    if (!is_string($mimeType)
                        || !isset($imageExtensions[$mimeType])
                        || !is_array($imageInfo)
                        || ($imageInfo['mime'] ?? '') !== $mimeType) {
                        $paymentProofError = 'Only valid JPG, PNG, or WebP images are accepted.';
                    } else {
                        $paymentProofUpload = [
                            'temporaryFile' => $temporaryFile,
                            'extension' => $imageExtensions[$mimeType],
                        ];
                    }
                }
            }
        }
    }

    $cartIsReserved = $cart !== [];
    foreach ($cart as $product => $quantity) {
        if (($reservedCart[$product] ?? 0) < (int)$quantity) {
            $cartIsReserved = false;
            break;
        }
    }
    if ($paymentRequestIsValid && $cart !== [] && $cartIsReserved && $addressIsComplete
        && in_array($paymentMethod, ['Cash on Delivery', 'GCash', 'Maya'], true)
        && $paymentProofError === '') {
        $total = 0;
        foreach ($cart as $product => $quantity) {
            $total += $products[$product] * $quantity;
        }
        $subtotal = $total;
        $total += $deliveryFee;
        if ($paymentProofUpload !== null) {
            $paymentProofDirectory = appDataDir() . '/payment-proofs';
            if (!is_dir($paymentProofDirectory)
                && !mkdir($paymentProofDirectory, 0775, true)
                && !is_dir($paymentProofDirectory)) {
                $paymentProofError = 'Could not prepare secure storage for the payment proof.';
            } elseif (!is_writable($paymentProofDirectory)) {
                $paymentProofError = 'Payment proof storage is not writable. Please contact the administrator.';
            } elseif (!is_file($paymentProofDirectory . '/.htaccess')
                && file_put_contents($paymentProofDirectory . '/.htaccess', "Require all denied\n", LOCK_EX) === false) {
                $paymentProofError = 'Could not protect the payment proof storage.';
            } else {
                $paymentProofFilename = bin2hex(random_bytes(16)) . '.' . $paymentProofUpload['extension'];
                if (!move_uploaded_file(
                    $paymentProofUpload['temporaryFile'],
                    $paymentProofDirectory . '/' . $paymentProofFilename
                )) {
                    $paymentProofFilename = null;
                    $paymentProofError = 'Could not save the payment proof. Please try again.';
                }
            }
        }

        if ($paymentProofError !== '') {
            $paymentMessage = $paymentProofError;
        } else {
            $placedOrder = [
                'id' => '#PP-' . date('ymdHis') . '-' . strtoupper(bin2hex(random_bytes(3))),
                'date' => date('M j, Y'),
                'customerName' => $customerName,
                'customerEmail' => $customerEmail,
                'status' => 'Success',
                'items' => $cart,
                'subtotal' => $subtotal,
                'deliveryFee' => $deliveryFee,
                'total' => $total,
                'payment' => $paymentMethod,
                'paymentProof' => $paymentProofFilename,
                'deliveryRecipient' => $deliveryRecipient,
                'deliveryContactNumber' => $deliveryContactNumber,
                'deliveryAddress' => [
                    'region' => $region,
                    'province' => $province,
                    'city' => $city,
                    'barangay' => $barangay,
                    'streetAddress' => $streetAddress,
                    'locationDescription' => $locationDescription,
                ],
                'address' => $customerAddress,
                'contactNumber' => $deliveryContactNumber,
            ];
            if (saveCustomerOrder($placedOrder)) {
                $paymentMessage = 'Order placed for ' . htmlspecialchars($customerName) . ' via ' . htmlspecialchars($paymentMethod) . ' for ₱' . number_format($total, 2) . ' (includes ₱' . number_format($deliveryFee, 2) . ' delivery fee). Delivery address: ' . htmlspecialchars($customerAddress) . '.';
                $_SESSION['cart'] = [];
                $_SESSION['cart_stock_reserved'] = [];
                $reservedCart = [];
                $cart = [];
            } else {
                if ($paymentProofFilename !== null) {
                    $paymentProofPath = appDataDir() . '/payment-proofs/' . $paymentProofFilename;
                    if (is_file($paymentProofPath) && !unlink($paymentProofPath)) {
                        error_log('Could not remove payment proof after the order record failed to save.');
                    }
                }
                $paymentMessage = 'Could not save your order record. Your cart is still available; please try again.';
                $placedOrder = null;
            }
        }
    } else {
        if (!$paymentRequestIsValid) {
            $paymentMessage = $paymentProofError;
        } elseif ($cart !== [] && !$cartIsReserved) {
            $paymentMessage = 'Some items in your cart could not be reserved. Please review your cart and available stock.';
        } elseif ($cart === []) {
            $paymentMessage = 'Please add bread to your cart before placing an order.';
        } elseif (!$addressIsComplete) {
            $paymentMessage = 'Complete the recipient, contact number, location, and street address fields.';
        } elseif ($paymentProofError !== '') {
            $paymentMessage = $paymentProofError;
        } else {
            $paymentMessage = 'Please choose a valid payment method.';
        }
    }
}

$inventoryItems = loadSharedInventory();
$inventoryByName = array_column($inventoryItems, 'stock', 'name');

$cartTotal = 0;
foreach ($cart as $product => $quantity) {
    $cartTotal += $products[$product] * $quantity;
}
$customerOrders = array_values(array_filter($allOrders, static function ($order) use ($customerEmail): bool {
    return strtolower((string)($order['customerEmail'] ?? '')) === $customerEmail;
}));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panaderia Pilipina | Customer Dashboard</title>
    <style>
        :root { --brown: #7c2d12; --deep-brown: #431407; --gold: #d97706; --cream: #fffaf0; --ink: #292524; --muted: #78716c; --line: #f1d9b5; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; color: var(--ink); background: #fff7ed fixed; }
        .topbar { background: var(--deep-brown); color: #fff7ed; padding: 18px 5vw; display: flex; justify-content: space-between; align-items: center; gap: 20px; }
        .brand { font-size: 1.35rem; font-weight: 800; letter-spacing: .04em; }
        .brand span { display: block; color: #fbbf24; font-size: .75rem; font-weight: 500; letter-spacing: .14em; margin-top: 4px; }
        .user-actions { display: flex; align-items: center; gap: 12px; }
        .logged-user { color: #fef3c7; font-size: 0.9rem; font-weight: 700; letter-spacing: 0.03em; }
        .logout-btn { color: #fff; border: 1px solid #fbbf24; border-radius: 7px; padding: 9px 14px; text-decoration: none; font-size: .9rem; font-weight: 700; }
        main { width: min(1800px, 98%); margin: auto; padding: 42px 0 56px; }
        .welcome { display: flex; justify-content: space-between; align-items: end; gap: 24px; margin-bottom: 30px; }
        .eyebrow { color: var(--gold); font-size: .78rem; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; margin: 0 0 9px; }
        h1 { margin: 0 0 8px; color: var(--brown); font-size: clamp(2rem, 4vw, 3rem); }
        .welcome p { color: var(--muted); margin: 0; }
        .greeting-overlay { position: fixed; z-index: 20; inset: 0; display: grid; place-items: center; padding: 24px; background: rgba(41, 37, 36, .58); }
        .greeting-overlay[hidden] { display: none; }
        .greeting-popup { width: min(520px, 100%); padding: 42px 30px; border: 2px solid #f59e0b; border-radius: 20px; background: #fffdf9; color: var(--deep-brown); text-align: center; box-shadow: 0 18px 48px rgba(41, 37, 36, .3); }
        .greeting-popup h1 { margin: 0 0 12px; color: var(--brown); font-size: clamp(2rem, 7vw, 3rem); }
        .greeting-popup p { margin: 0 0 26px; color: #9a4d12; font-size: 1.05rem; line-height: 1.5; }
        .greeting-continue { padding: 13px 22px; border: 0; border-radius: 10px; background: linear-gradient(135deg, #d97706, #b45309); color: #fff; font-size: 1rem; font-weight: 700; cursor: pointer; }
        .pay-btn { background: var(--gold); color: #fff; padding: 13px 18px; border: 0; border-radius: 8px; text-decoration: none; font-weight: 800; cursor: pointer; }
        .content-grid { position: relative; display: block; }
        #featured { position: relative; width: 100%; min-height: 580px; }
        #orders { position: absolute; z-index: 5; top: 68px; right: 24px; width: min(390px, calc(100% - 48px)); height: min(68vh, 520px); max-height: min(68vh, 520px); min-height: 0; overflow-x: auto; overflow-y: auto; overscroll-behavior: contain; scrollbar-width: thin; box-shadow: 0 16px 40px rgba(41, 37, 36, .24); }
        #payment { width: 100%; margin-top: 22px; }
        .section, .product-card { background: #fff; border: 1px solid var(--line); border-radius: 10px; }
        .section { padding: 24px; }
        .section-heading { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
        h2 { color: var(--brown); font-size: 1.25rem; margin: 0; }
        .section-link { color: var(--gold); text-decoration: none; font-size: .85rem; font-weight: 700; }
        .dashboard-footer { margin-top: 26px; padding: 32px 0; background: #111; color: #f5f5f5; }
        .footer-inner { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(240px, 1fr) minmax(220px, .8fr); gap: 30px; width: min(1800px, 98%); margin: 0 auto; text-align: center; }
        .footer-title { margin: 0 0 12px; color: #fff; font-size: 1rem; font-weight: 800; }
        .footer-description { max-width: 620px; margin: 0 auto 18px; color: #d4d4d4; line-height: 1.6; }
        .footer-categories { margin: 0; }
        .footer-categories ul { display: grid; grid-template-rows: repeat(4, auto); grid-auto-flow: column; grid-auto-columns: minmax(100px, 1fr); justify-items: center; gap: 0 10px; overflow-x: auto; margin: 0; padding: 0; list-style: none; }
        .footer-categories a { display: inline-block; padding: 1px 0; color: #e5e5e5; font-size: .9rem; text-decoration: none; }
        .footer-categories a:hover, .footer-contact a:hover { color: #fbbf24; }
        .footer-contact { display: flex; flex-direction: column; justify-content: flex-start; align-items: center; gap: 6px; color: #d4d4d4; font-size: .9rem; line-height: 1.5; }
        .footer-contact .footer-title { margin-bottom: 2px; }
        .footer-contact span { display: block; }
        .footer-contact a { color: #fbbf24; font-weight: 700; text-decoration: none; }
        .search-box { margin-bottom: 18px; }
        .search-input { width: 100%; padding: 14px 16px; border: 1px solid var(--line); border-radius: 10px; background: #fff; font-size: 1rem; color: var(--ink); }
        .category-strip { display: flex; gap: 10px; overflow-x: auto; padding-bottom: 5px; margin-bottom: 18px; scrollbar-width: thin; }
        .category-pill { white-space: nowrap; display: inline-block; padding: 9px 14px; border: 1px solid var(--line); border-radius: 999px; background: #fffaf0; color: var(--brown); text-decoration: none; font-size: .84rem; font-weight: 700; }
        .category-pill.active { background: var(--brown); color: #fff; border-color: var(--brown); }
        .products { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); align-items: stretch; gap: 12px; }
        .search-empty { display: none; margin: 16px 0 0; color: var(--muted); }
        .product-card { display: flex; flex-direction: column; min-height: 200px; overflow: hidden; background: var(--cream); position: relative; }
        .product-card[hidden] { display: none; }
        .product-art { height: 92px; overflow: hidden; background: #f7d9a6; }
        .product-art img { width: 100%; height: 100%; object-fit: cover; object-position: center; display: block; }
        .product-badge { position: absolute; top: 10px; right: 10px; background: rgba(255, 191, 36, 0.95); color: var(--deep-brown); font-size: .65rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; padding: 5px 8px; border-radius: 999px; }
        .product-rating { margin: 6px 0 0; color: var(--gold); font-size: .8rem; }
        .product-info { display: flex; flex: 1; flex-direction: column; padding: 12px; }
        .product-info strong { display: -webkit-box; height: 2.4em; overflow: hidden; color: var(--brown); margin-bottom: 0; line-height: 1.2em; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
        .price { display: block; min-height: 1.2em; color: var(--muted); font-size: .86rem; }
        .product-description { display: -webkit-box; min-height: 3.6em; overflow: hidden; margin: 2px 0 8px; color: var(--muted); font-size: .78rem; line-height: 1.2em; -webkit-box-orient: vertical; -webkit-line-clamp: 3; }
        .product-rating { min-height: 1em; margin: 6px 0 0; color: var(--gold); font-size: .8rem; }
        .product-stock { display: flex; justify-content: flex-start; gap: 4px; margin-top: auto; color: var(--muted); font-size: .8rem; font-weight: 700; }
        .product-stock-value { color: var(--brown); text-align: right; }
        .product-stock-status { color: #b91c1c; }
        .product-stock-status[hidden] { display: none; }
        .product-stock.out-of-stock {
            color: #b91c1c;
        }
        .product-stock.out-of-stock .product-stock-value { color: #b91c1c; }
        .cart-form { margin-top: auto; }
        .buy-btn { width: 100%; margin-top: 6px; padding: 7px 10px; border: 0; border-radius: 6px; background: var(--gold); color: #fff; cursor: pointer; font-size: .78rem; font-weight: 700; }
        .buy-btn:disabled { opacity: 0.55; cursor: not-allowed; }
        .cart-table, .orders { width: 100%; border-collapse: collapse; font-size: .9rem; }
        .orders { min-width: 620px; }
        .cart-table th, .cart-table td, .orders th, .orders td { padding: 10px 8px; text-align: left; border-bottom: 1px solid #f5e8d3; }
        .cart-table th, .orders th { color: var(--muted); font-size: .75rem; text-transform: uppercase; }
        .quantity-controls { display: inline-flex; align-items: center; gap: 8px; }
        .quantity-btn { width: 28px; height: 28px; padding: 0; border: 1px solid var(--line); border-radius: 6px; background: #fffaf0; color: var(--brown); font-size: 1rem; font-weight: 800; cursor: pointer; }
        .quantity-btn:disabled { opacity: .55; cursor: wait; }
        .cart-total { color: var(--brown); font-size: 1.1rem; font-weight: 800; text-align: right; }
        .empty-cart { color: var(--muted); }
        .payment-form { display: block; gap: 14px; }
        .payment-field label, .gcash-number-label { display: block; margin-bottom: 6px; color: var(--brown); font-size: .82rem; font-weight: 700; }
        .payment-field select, .gcash-number-input, .address-fields input, .address-fields select, .address-fields textarea { width: 100%; padding: 11px; border: 1px solid var(--line); border-radius: 7px; background: #fff; color: var(--ink); }
        .gcash-number-input { max-width: 360px; display: block; margin: 0; text-align: left; letter-spacing: .04em; }
        .address-title { margin: 22px 0 12px; color: var(--brown); }
        .address-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 14px; }
        .address-fields .payment-field { min-width: 0; }
        .address-location-summary { grid-column: 1 / -1; }
        .address-location-summary output { display: block; padding: 11px; border: 1px solid var(--line); border-radius: 7px; background: #fffaf0; color: var(--ink); }
        .address-street { grid-column: 1 / -1; }
        .address-error { padding: 10px; border-radius: 7px; background: #fff7ed; color: #9a3412; border: 1px solid #fdba74; }
        .payment-methods-row { width: 100%; }
        .payment-options { display: flex; flex-direction: row; gap: 10px; flex-wrap: nowrap; width: 100%; }
        .payment-option { flex: 1 1 0; min-width: 140px; padding: 12px 14px; border: 1px solid var(--line); border-radius: 10px; background: #fffaf0; color: var(--brown); font-weight: 700; cursor: pointer; }
        .payment-option.active { background: var(--brown); color: #fff; border-color: var(--brown); }
        .gcash-details { display: none; grid-column: 1 / -1; padding: 16px; border: 1px solid #93c5fd; border-radius: 9px; background: #eff6ff; }
        .place-order-btn { width: 100%; min-width: 180px; padding: 14px 18px; margin-top: 8px; }
        .gcash-details.visible { display: flex; align-items: center; }
        .payment-instructions { flex: 0 1 auto; min-width: 0; }
        .gcash-details strong { display: block; color: #1d4ed8; margin-bottom: 7px; }
        .gcash-number-label { color: #1e3a8a; margin: 8px 0; font-size: 1.15rem; font-weight: 700; }
        .gcash-note { color: #475569; font-size: .82rem; margin: 7px 0 0; }
        .payment-message { margin: 0 0 18px; padding: 12px 14px; border-radius: 7px; background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; font-weight: 600; }
        .status { color: #15803d; font-weight: 700; }
        @media (max-width: 760px) { main { width: 94%; } #featured { min-height: 0; } #orders { position: static; width: 100%; height: 70vh; max-height: 70vh; min-height: 0; margin-top: 14px; } .footer-inner { width: 94%; grid-template-columns: 1fr; gap: 22px; } .welcome, .content-grid { display: grid; grid-template-columns: 1fr; align-items: start; } .products { grid-template-columns: 1fr; } .product-card { display: grid; grid-template-columns: 90px 1fr; } .product-art { height: 100%; } .payment-form, .gcash-details.visible { grid-template-columns: 1fr; display: grid; } .address-fields { grid-template-columns: 1fr; } .address-street { grid-column: auto; } }
    </style>
</head>
<body>
<?php if ($showGreeting || $placedOrder !== null): ?>
<div class="greeting-overlay" id="greeting_overlay">
    <section class="greeting-popup" role="dialog" aria-modal="true" aria-labelledby="greeting_title">
        <?php if ($placedOrder !== null): ?>
            <h1 id="greeting_title">Order placed successfully!</h1>
            <p>Your order for <?php echo htmlspecialchars(implode(', ', array_keys($placedOrder['items'])), ENT_QUOTES, 'UTF-8'); ?> has been recorded. Thank you for ordering from Panaderia Pilipina.</p>
        <?php else: ?>
            <h1 id="greeting_title">Greetings, <?php echo htmlspecialchars($greetingName, ENT_QUOTES, 'UTF-8'); ?>!</h1>
            <p>Welcome to Panaderia Pilipina. We are happy to have you here.</p>
        <?php endif; ?>
        <button type="button" class="greeting-continue" id="greeting_continue">Continue to Dashboard</button>
    </section>
</div>
<?php endif; ?>
<div id="new-order-data" hidden data-order="<?php echo htmlspecialchars(json_encode($placedOrder), ENT_QUOTES, 'UTF-8'); ?>"></div>
<header class="topbar">
    <div class="brand">Panaderia Pilipina<span>Customer Portal</span></div>
    <div class="user-actions">
        <span class="logged-user"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
        <a class="logout-btn" href="customer_dashboard.php?logout=1">Log Out</a>
    </div>
</header>
<main>
    <section class="welcome"><div><h1>Welcome back, <?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>.</h1><p>Fresh favorites and your order activity, all in one place.</p></div></section>
    <div class="content-grid">
        <section class="section" id="featured">
            <div class="section-heading"><h2>Featured today</h2><a class="section-link" id="show_recent_orders" href="#orders" aria-expanded="false">Recent orders</a></div>
            <div class="search-box">
                <form method="GET">
                    <input class="search-input" id="bread_search" type="search" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>" placeholder="Search bread, favorites, or treats..." autocomplete="off">
                </form>
            </div>
            <div class="category-strip">
                <?php foreach ($categories as $slug => $label): ?>
                    <a class="category-pill <?php echo $selectedCategory === $slug ? 'active' : ''; ?>" href="customer_dashboard.php?category=<?php echo urlencode($slug); ?>&search=<?php echo urlencode($slug === 'all' ? '' : $searchQuery); ?>"><?php echo htmlspecialchars($label); ?></a>
                <?php endforeach; ?>
            </div>
            <?php if ($cartError !== ''): ?>
                <p class="payment-message" id="cart_feedback" role="status"><?php echo htmlspecialchars($cartError, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php else: ?>
                <p class="payment-message" id="cart_feedback" role="status" hidden></p>
            <?php endif; ?>
            <div class="products" id="product_list">
                <?php foreach ($featuredProductOrder as $product): ?>
                    <?php $price = $filteredProducts[$product]; ?>
                    <article class="product-card" data-search="<?php echo htmlspecialchars(strtolower($product . ' bread ' . ($productDescriptions[$product] ?? '') . ' ' . str_replace('_', ' ', $productCategories[$product] ?? '') . (isset($bestSellerSet[$product]) ? ' best seller' : ''))); ?>">
                        <?php if (isset($bestSellerSet[$product])): ?>
                            <div class="product-badge">Best Seller</div>
                        <?php endif; ?>
                        <div class="product-art">
                            <img src="<?php echo htmlspecialchars($productImages[$product] ?? '../Image/823728781_1319838950089602_6463677968597898602_n.jpg'); ?>" alt="<?php echo htmlspecialchars($product); ?>">
                        </div>
                        <div class="product-info">
                            <strong><?php echo htmlspecialchars($product); ?></strong>
                            <p class="product-description"><?php echo htmlspecialchars($productDescriptions[$product] ?? 'Freshly baked bread.'); ?></p>
                            <div class="product-rating" <?php echo isset($bestSellerSet[$product]) ? '' : 'aria-hidden="true"'; ?>><?php echo isset($bestSellerSet[$product]) ? '★★★★★' : '&nbsp;'; ?></div>
                            <span class="price">₱<?php echo number_format($price, 2); ?></span>
                            <?php $productStock = (int)($inventoryByName[$product] ?? 0); ?>
                            <div class="product-stock<?php echo $productStock <= 0 ? ' out-of-stock' : ''; ?>" data-product="<?php echo htmlspecialchars($product); ?>"><span>Stock:</span><span class="product-stock-value"><?php echo $productStock; ?> pcs</span><span class="product-stock-status" <?php echo $productStock > 0 ? 'hidden' : ''; ?>>Out of Stock</span></div>
                            <form class="cart-form" method="POST"><input type="hidden" name="product" value="<?php echo htmlspecialchars($product); ?>"><input type="hidden" name="cart_csrf_token" value="<?php echo htmlspecialchars($cartCsrfToken, ENT_QUOTES, 'UTF-8'); ?>"><button class="buy-btn" type="submit" name="add_to_cart" <?php echo $productStock <= 0 ? 'disabled' : ''; ?>>Add to cart</button></form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <p class="search-empty" id="search_empty">No bread found for your search.</p>
        </section>

        <section class="section" id="orders" hidden><div class="section-heading"><h2>Recent orders</h2><a class="section-link" id="see_all_orders" href="#all-orders">View all</a></div><table class="orders"><thead><tr><th>Order</th><th>Bread purchased</th><th>Amount paid</th><th>Date</th><th>Status</th></tr></thead><tbody id="recent_orders_body"></tbody></table><div id="all-orders" hidden><table class="orders"><thead><tr><th>Order</th><th>Bread purchased</th><th>Amount paid</th><th>Date</th><th>Status</th></tr></thead><tbody id="all_orders_body"></tbody></table></div></section>

        <?php include __DIR__ . '/../payment.php'; ?>
    </div>
</main>
<footer class="dashboard-footer">
    <div class="footer-inner">
        <section class="footer-menu">
            <p class="footer-title">Menu Categories</p>
            <nav class="footer-categories" aria-label="Bread menu">
                <ul>
                    <?php foreach ($products as $breadName => $price): ?>
                        <li><a href="customer_dashboard.php?search=<?php echo urlencode($breadName); ?>"><?php echo htmlspecialchars($breadName); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        </section>
        <section class="footer-about" aria-label="About Panaderia Pilipina">
            <p class="footer-title">Panaderia Pilipina</p>
            <p class="footer-description">Panaderia Pilipina serves freshly baked Filipino breads and bakery favorites, made for everyday merienda and sharing.</p>
        </section>
        <aside class="footer-contact" aria-label="Admin contact">
            <p class="footer-title">Admin Contact</p>
            <span>Facebook: <a href="https://www.facebook.com/share/1HePFLeTMX/" target="_blank" rel="noopener noreferrer">Shielo M. Toyco</a></span>
            <span>Phone: <a href="tel:09515773435">09515773435</a></span>
            <span>Location: Brgy Granada, Sitio. Piagao 2</span>
        </aside>
    </div>
</footer>
<script>
    const recentOrdersBody = document.getElementById('recent_orders_body');
    const cartCsrfToken = <?php echo json_encode($cartCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const serverOrders = <?php echo json_encode($customerOrders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const newlyPlacedOrder = <?php echo json_encode($placedOrder, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const customerOrdersStorageKey = 'panaderia_recent_orders_' + encodeURIComponent(<?php echo json_encode($customerEmail, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);

    function readCachedOrders() {
        try {
            const cachedOrders = JSON.parse(localStorage.getItem(customerOrdersStorageKey) || '[]');
            return Array.isArray(cachedOrders) ? cachedOrders.filter((order) => order && typeof order === 'object' && order.id) : [];
        } catch (error) {
            console.error('Could not read locally saved orders.', error);
            return [];
        }
    }

    function mergeOrders(primaryOrders, secondaryOrders) {
        const ordersById = new Map();
        [...primaryOrders, ...secondaryOrders].forEach((order) => {
            if (order && typeof order === 'object' && order.id && !ordersById.has(order.id)) {
                ordersById.set(order.id, order);
            }
        });
        return [...ordersById.values()];
    }

    function saveCachedOrders(orders) {
        try {
            localStorage.setItem(customerOrdersStorageKey, JSON.stringify(orders));
        } catch (error) {
            console.error('Could not save orders for offline viewing.', error);
        }
    }

    function renderProductStocks(sourceDocument = document) {
        const stockByName = new Map();
        sourceDocument.querySelectorAll('.product-stock').forEach((stockElement) => {
            stockByName.set(stockElement.dataset.product, stockElement.querySelector('.product-stock-value')?.textContent || '0 pcs');
        });
        document.querySelectorAll('.product-stock').forEach((stockElement) => {
            const productName = stockElement.dataset.product;
            const stockText = stockByName.get(productName);
            if (stockText === undefined) {
                return;
            }
            const stockValue = Number.parseInt(stockText, 10) || 0;
            const stockValueElement = stockElement.querySelector('.product-stock-value');
            if (stockValueElement) {
                stockValueElement.textContent = stockText;
            }
            stockElement.classList.toggle('out-of-stock', stockValue <= 0);
            const statusElement = stockElement.querySelector('.product-stock-status');
            if (statusElement) {
                statusElement.hidden = stockValue > 0;
            }

            const button = stockElement.parentElement?.querySelector('.buy-btn');
            if (button) {
                button.disabled = stockValue <= 0;
            }
        });
    }

    function formatOrderItems(order) {
        const items = Object.entries(order.items || {});
        return items.length > 0 ? items.map(([product, quantity]) => product + ' x' + quantity).join(', ') : 'Not recorded';
    }

    function formatOrderTotal(order) {
        return order.total !== undefined && order.total !== null ? 'PHP ' + Number(order.total).toFixed(2) : 'Not recorded';
    }

    function createOrderRow(order) {
        const row = document.createElement('tr');
        const values = [order.id, formatOrderItems(order), formatOrderTotal(order), order.date, order.status];
        values.forEach((value, index) => {
            const cell = document.createElement('td');
            cell.textContent = String(value ?? '');
            if (index === 4) {
                cell.className = 'status';
            }
            row.appendChild(cell);
        });
        return row;
    }

    function renderSavedOrders(orders) {
        const normalizedOrders = orders.map((order) => ({
            ...order,
            status: order.status === 'Pending' || !order.status ? 'Success' : order.status
        }));

        recentOrdersBody.replaceChildren();
        const recentOrders = normalizedOrders.slice(0, 7);
        if (recentOrders.length === 0) {
            const emptyRow = document.createElement('tr');
            emptyRow.innerHTML = '<td colspan="5" class="empty-cart">No recent order records yet.</td>';
            recentOrdersBody.appendChild(emptyRow);
        } else {
            recentOrders.forEach((order) => {
                const row = createOrderRow(order);
                row.className = 'saved-order-row';
                recentOrdersBody.appendChild(row);
            });
        }
        const allOrdersBody = document.getElementById('all_orders_body');
        const seeAllOrders = document.getElementById('see_all_orders');
        if (allOrdersBody && seeAllOrders) {
            allOrdersBody.innerHTML = '';
            normalizedOrders.slice(7).forEach((order) => {
                allOrdersBody.appendChild(createOrderRow(order));
            });
            seeAllOrders.textContent = normalizedOrders.length > 7 ? 'View all (' + (normalizedOrders.length - 7) + ')' : 'View all';
        }
    }

    function syncQuantitySummary(responseDocument) {
        const currentPayment = document.getElementById('payment');
        const updatedPayment = responseDocument.getElementById('payment');
        if (!currentPayment || !updatedPayment) {
            return;
        }
        const currentTable = currentPayment.querySelector('.cart-table');
        const updatedTable = updatedPayment.querySelector('.cart-table');
        const currentTotal = currentPayment.querySelector('.cart-total');
        const updatedTotal = updatedPayment.querySelector('.cart-total');
        const emptyCart = currentPayment.querySelector('.empty-cart');
        const updatedEmptyCart = updatedPayment.querySelector('.empty-cart');

        if (currentTable && updatedTable) {
            currentTable.replaceWith(updatedTable);
        } else if (currentTable && updatedEmptyCart) {
            currentTable.replaceWith(updatedEmptyCart);
        } else if (!currentTable && updatedTable) {
            if (emptyCart) {
                emptyCart.replaceWith(updatedTable);
            } else {
                currentPayment.insertBefore(updatedTable, currentPayment.querySelector('.payment-form'));
            }
        }
        if (currentTotal && updatedTotal) {
            currentTotal.replaceWith(updatedTotal);
        } else if (currentTotal && !updatedTotal) {
            currentTotal.remove();
        } else if (!currentTotal && updatedTotal) {
            currentPayment.insertBefore(updatedTotal, currentPayment.querySelector('.payment-form'));
        }
        const placeOrderButton = currentPayment.querySelector('.place-order-btn');
        if (placeOrderButton) {
            placeOrderButton.disabled = !updatedTable;
        }
    }

    let savedOrders = mergeOrders(serverOrders, readCachedOrders());
    if (newlyPlacedOrder !== null) {
        savedOrders = mergeOrders([newlyPlacedOrder], savedOrders);
    }

    saveCachedOrders(savedOrders);
    renderSavedOrders(savedOrders);
    renderProductStocks();
    const cartFeedback = document.getElementById('cart_feedback');
    function showCartFeedback(responseDocument) {
        const responseFeedback = responseDocument.getElementById('cart_feedback');
        if (!cartFeedback || !responseFeedback) {
            return;
        }
        cartFeedback.textContent = responseFeedback.textContent;
        cartFeedback.hidden = responseFeedback.hidden;
    }
    let stockRefreshInProgress = false;
    async function refreshProductStocks() {
        if (stockRefreshInProgress || document.visibilityState === 'hidden') {
            return;
        }
        stockRefreshInProgress = true;
        try {
            const response = await fetch(window.location.pathname + '?inventory_json=1', { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) {
                throw new Error('Inventory refresh failed.');
            }
            const inventory = await response.json();
            const stockByName = new Map(inventory.map((item) => [item.name, Number(item.stock)]));
            document.querySelectorAll('.product-stock').forEach((stockElement) => {
                const stock = stockByName.get(stockElement.dataset.product);
                if (stock === undefined) {
                    return;
                }
                stockElement.querySelector('.product-stock-value').textContent = `${stock} pcs`;
                stockElement.classList.toggle('out-of-stock', stock <= 0);
                const statusElement = stockElement.querySelector('.product-stock-status');
                if (statusElement) {
                    statusElement.hidden = stock > 0;
                }
                const button = stockElement.parentElement?.querySelector('.buy-btn');
                if (button) {
                    button.disabled = stock <= 0;
                }
            });
        } catch (error) {
            console.error('Could not refresh shared inventory.', error);
        } finally {
            stockRefreshInProgress = false;
        }
    }
    refreshProductStocks();
    window.setInterval(refreshProductStocks, 5000);
    document.addEventListener('visibilitychange', refreshProductStocks);
    const breadSearch = document.getElementById('bread_search');
    const productCards = document.querySelectorAll('#product_list .product-card');
    const searchEmpty = document.getElementById('search_empty');
    if (breadSearch) {
        breadSearch.addEventListener('input', () => {
            const searchText = breadSearch.value.trim().toLowerCase();
            let visibleProducts = 0;
            productCards.forEach((card) => {
                const matches = searchText === '' || card.dataset.search.includes(searchText);
                card.hidden = !matches;
                if (matches) {
                    visibleProducts += 1;
                }
            });
            if (searchEmpty) {
                searchEmpty.style.display = visibleProducts === 0 ? 'block' : 'none';
            }
        });
        breadSearch.dispatchEvent(new Event('input'));
    }
    const seeAllOrders = document.getElementById('see_all_orders');
    const allOrdersPanel = document.getElementById('all-orders');
    const showRecentOrders = document.getElementById('show_recent_orders');
    const ordersSection = document.getElementById('orders');
    if (showRecentOrders && ordersSection) {
        showRecentOrders.addEventListener('click', (event) => {
            event.preventDefault();
            ordersSection.hidden = !ordersSection.hidden;
            showRecentOrders.setAttribute('aria-expanded', String(!ordersSection.hidden));
            showRecentOrders.textContent = ordersSection.hidden ? 'Recent orders' : 'Close recent orders';
            if (window.matchMedia('(max-width: 900px)').matches) {
                if (!ordersSection.hidden) {
                    ordersSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
        });
    }
    if (seeAllOrders && allOrdersPanel) {
        seeAllOrders.addEventListener('click', (event) => {
            event.preventDefault();
            allOrdersPanel.hidden = !allOrdersPanel.hidden;
            seeAllOrders.textContent = allOrdersPanel.hidden ? 'View all' : 'Hide older orders';
        });
    }

    document.querySelectorAll('.cart-form, .payment-form').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!form.reportValidity()) {
                return;
            }

            const submitButton = form.querySelector('button[type="submit"]');
            const originalText = submitButton ? submitButton.textContent : '';
            if (submitButton) {
                submitButton.disabled = true;
                if (!form.classList.contains('cart-form')) {
                    submitButton.textContent = 'Placing...';
                }
            }

            try {
                const formData = new FormData(form);
                if (submitButton && submitButton.name) {
                    formData.append(submitButton.name, submitButton.value || '1');
                }
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) {
                    throw new Error('Request failed');
                }
                const responseDocument = new DOMParser().parseFromString(await response.text(), 'text/html');
                renderProductStocks(responseDocument);
                showCartFeedback(responseDocument);

                if (form.classList.contains('cart-form')) {
                    const currentPayment = document.getElementById('payment');
                    const updatedPayment = responseDocument.getElementById('payment');
                    const currentTable = currentPayment ? currentPayment.querySelector('.cart-table') : null;
                    const updatedTable = updatedPayment ? updatedPayment.querySelector('.cart-table') : null;
                    const currentTotal = currentPayment ? currentPayment.querySelector('.cart-total') : null;
                    const updatedTotal = updatedPayment ? updatedPayment.querySelector('.cart-total') : null;
                    const emptyCart = currentPayment ? currentPayment.querySelector('.empty-cart') : null;

                    if (emptyCart) {
                        emptyCart.remove();
                    }
                    if (currentTable && updatedTable) {
                        currentTable.replaceWith(updatedTable);
                    } else if (!currentTable && updatedTable && currentPayment) {
                        currentPayment.insertBefore(updatedTable, currentPayment.querySelector('.payment-form'));
                    }
                    if (currentTotal && updatedTotal) {
                        currentTotal.replaceWith(updatedTotal);
                    } else if (!currentTotal && updatedTotal && currentPayment) {
                        currentPayment.insertBefore(updatedTotal, currentPayment.querySelector('.payment-form'));
                    }
                    const placeOrderButton = currentPayment ? currentPayment.querySelector('.place-order-btn') : null;
                    if (placeOrderButton) {
                        placeOrderButton.disabled = false;
                    }
                    if (currentPayment) {
                        currentPayment.hidden = false;
                    }
                    if (submitButton) {
                        submitButton.textContent = responseDocument.getElementById('cart_feedback')?.hidden === false
                            ? 'Unavailable'
                            : 'Added';
                    }
                    setTimeout(() => {
                        if (submitButton) {
                            submitButton.disabled = false;
                            submitButton.textContent = originalText;
                        }
                    }, 900);
                } else {
                    const newOrderData = responseDocument.getElementById('new-order-data');
                    const orderJson = newOrderData ? newOrderData.dataset.order : '';
                    if (orderJson && orderJson !== 'null') {
                        const confirmedOrder = JSON.parse(orderJson);
                        savedOrders = mergeOrders([confirmedOrder], savedOrders);
                        saveCachedOrders(savedOrders);
                        renderSavedOrders(savedOrders);
                        const paymentProofInput = form.querySelector('input[name="payment_proof"]');
                        if (paymentProofInput) {
                            paymentProofInput.value = '';
                        }
                        const successOverlay = responseDocument.getElementById('greeting_overlay');
                        if (successOverlay) {
                            const currentOverlay = document.getElementById('greeting_overlay');
                            const overlayToShow = successOverlay.cloneNode(true);
                            if (currentOverlay) {
                                currentOverlay.replaceWith(overlayToShow);
                            } else {
                                document.body.appendChild(overlayToShow);
                            }
                            setupGreetingOverlay(overlayToShow);
                        }
                    }
                    const responseMessage = responseDocument.querySelector('.payment-message');
                    const currentPayment = document.getElementById('payment');
                    const currentMessage = currentPayment ? currentPayment.querySelector('.payment-message') : null;
                    if (responseMessage && currentPayment) {
                        if (currentMessage) {
                            currentMessage.replaceWith(responseMessage);
                        } else {
                            currentPayment.insertBefore(responseMessage, currentPayment.querySelector('.cart-table') || currentPayment.firstChild);
                        }
                    }
                    const updatedPayment = responseDocument.getElementById('payment');
                    const currentTable = currentPayment ? currentPayment.querySelector('.cart-table') : null;
                    const currentTotal = currentPayment ? currentPayment.querySelector('.cart-total') : null;
                    const updatedEmptyCart = updatedPayment ? updatedPayment.querySelector('.empty-cart') : null;
                    if (currentTable && updatedEmptyCart) {
                        currentTable.replaceWith(updatedEmptyCart);
                    }
                    if (currentTotal) {
                        currentTotal.remove();
                    }
                    form.reset();
                    if (typeof updatePaymentDetails === 'function') {
                        updatePaymentDetails();
                    }
                    if (submitButton) {
                        submitButton.disabled = true;
                        submitButton.textContent = originalText;
                    }
                }
            } catch (error) {
                if (submitButton?.name) {
                    const fallbackSubmit = document.createElement('input');
                    fallbackSubmit.type = 'hidden';
                    fallbackSubmit.name = submitButton.name;
                    fallbackSubmit.value = submitButton.value || '1';
                    form.appendChild(fallbackSubmit);
                }
                form.submit();
            }
        });
    });

    document.addEventListener('click', async (event) => {
        const quantityButton = event.target.closest('.quantity-btn');
        if (!quantityButton || quantityButton.disabled) {
            return;
        }
        quantityButton.disabled = true;
        const formData = new FormData();
        formData.append('update_cart', '1');
        formData.append('product', quantityButton.dataset.product || '');
        formData.append('change', quantityButton.dataset.change || '0');
        formData.append('cart_csrf_token', cartCsrfToken);
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) {
                throw new Error('Request failed');
            }
            const responseDocument = new DOMParser().parseFromString(await response.text(), 'text/html');
            renderProductStocks(responseDocument);
            showCartFeedback(responseDocument);
            syncQuantitySummary(responseDocument);
        } catch (error) {
            quantityButton.disabled = false;
        }
    });

    const scrollStorageKey = 'panaderia_scroll_position';
    if ('scrollRestoration' in history) {
        history.scrollRestoration = 'manual';
    }
    window.addEventListener('beforeunload', () => {
        sessionStorage.setItem(scrollStorageKey, String(window.scrollY));
    });
    const savedScrollPosition = sessionStorage.getItem(scrollStorageKey);
    if (savedScrollPosition !== null) {
        requestAnimationFrame(() => {
            window.scrollTo(0, Number(savedScrollPosition));
            sessionStorage.removeItem(scrollStorageKey);
        });
    }

    function setupGreetingOverlay(greetingOverlay) {
        const greetingContinue = greetingOverlay.querySelector('#greeting_continue');
        if (!greetingContinue) {
            return;
        }
        document.body.style.overflow = 'hidden';
        greetingContinue.focus();
        const closeGreeting = () => {
            greetingOverlay.hidden = true;
            document.body.style.overflow = '';
        };
        greetingContinue.addEventListener('click', closeGreeting);
        greetingOverlay.addEventListener('click', (event) => {
            if (event.target === greetingOverlay) {
                closeGreeting();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeGreeting();
            }
        });
    }
    const greetingOverlay = document.getElementById('greeting_overlay');
    if (greetingOverlay) {
        setupGreetingOverlay(greetingOverlay);
    }
</script>
</body>
</html>
