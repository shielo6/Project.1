<?php

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($requestPath)) {
    http_response_code(400);
    exit();
}

$decodedPath = rawurldecode($requestPath);
if (preg_match('~(?:^|/)\.[^/]*(?:/|$)~', $decodedPath) === 1) {
    http_response_code(404);
    exit();
}

if (preg_match('~(?:^|/)payment-proofs(?:/|$)~i', $decodedPath) === 1) {
    http_response_code(404);
    exit();
}

return false;
