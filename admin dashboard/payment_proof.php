<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../order_store.php';

$orderId = $_GET['order'] ?? null;
if (!is_string($orderId) || $orderId === '') {
    http_response_code(400);
    exit('Order is required.');
}

$proofFilename = null;
foreach (loadAllCustomerOrders() as $order) {
    if (($order['id'] ?? null) === $orderId) {
        $proofFilename = $order['paymentProof'] ?? null;
        break;
    }
}

if (!is_string($proofFilename)
    || preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp)\z/', $proofFilename) !== 1) {
    http_response_code(404);
    exit('Payment proof not found.');
}

$extension = pathinfo($proofFilename, PATHINFO_EXTENSION);
$mimeTypes = [
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
];
$path = appDataDir() . '/payment-proofs/' . $proofFilename;
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('Payment proof not found.');
}

$fileInfo = finfo_open(FILEINFO_MIME_TYPE);
if ($fileInfo === false) {
    http_response_code(500);
    exit('Could not inspect payment proof.');
}

$mimeType = finfo_file($fileInfo, $path);
finfo_close($fileInfo);
if (!isset($mimeTypes[$extension]) || $mimeType !== $mimeTypes[$extension]) {
    http_response_code(404);
    exit('Payment proof not found.');
}

session_write_close();
header('Content-Type: ' . $mimeTypes[$extension]);
header('Content-Disposition: inline; filename="payment-proof.' . $extension . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
readfile($path);
