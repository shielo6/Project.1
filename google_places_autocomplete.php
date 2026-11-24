<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sendAutocompleteResponse(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SESSION['role'] ?? '') !== 'customer') {
    sendAutocompleteResponse(401, ['error' => 'Please sign in as a customer to search addresses.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    sendAutocompleteResponse(405, ['error' => 'Address search requires a POST request.']);
}

$now = time();
$requestTimes = array_values(array_filter(
    $_SESSION['google_places_search_times'] ?? [],
    static fn($timestamp): bool => is_int($timestamp) && $timestamp > $now - 60
));
if (count($requestTimes) >= 60) {
    sendAutocompleteResponse(429, ['error' => 'Too many address searches. Wait a minute and try again.']);
}
$requestTimes[] = $now;
$_SESSION['google_places_search_times'] = $requestTimes;

require_once __DIR__ . '/app_config.php';
$apiKey = appEnv('GOOGLE_MAPS_API_KEY');
if ($apiKey === '') {
    sendAutocompleteResponse(503, ['error' => 'Google Places is not configured. Add GOOGLE_MAPS_API_KEY to the server .env file.']);
}

$request = json_decode(file_get_contents('php://input') ?: '', true);
$input = trim((string)($request['input'] ?? ''));
if (mb_strlen($input) < 3 || mb_strlen($input) > 250) {
    sendAutocompleteResponse(400, ['error' => 'Search text must be between 3 and 250 characters.']);
}

$payload = json_encode([
    'input' => $input,
    'includedRegionCodes' => ['ph'],
    'languageCode' => 'en',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$curl = curl_init('https://places.googleapis.com/v1/places:autocomplete');
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Goog-Api-Key: ' . $apiKey,
        'X-Goog-FieldMask: suggestions.placePrediction.placeId,suggestions.placePrediction.text,suggestions.placePrediction.structuredFormat',
    ],
]);

$responseBody = curl_exec($curl);
$curlError = curl_error($curl);
$responseStatus = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
curl_close($curl);

if ($responseBody === false) {
    error_log('Google Places autocomplete request failed: ' . $curlError);
    sendAutocompleteResponse(502, ['error' => 'Google Places could not be reached. Try searching again.']);
}

$response = json_decode($responseBody, true);
if ($responseStatus < 200 || $responseStatus >= 300) {
    error_log('Google Places autocomplete returned HTTP ' . $responseStatus . '.');
    sendAutocompleteResponse(502, ['error' => 'Google Places could not complete the search. Check API setup and try again.']);
}

$suggestions = [];
foreach (($response['suggestions'] ?? []) as $suggestion) {
    $place = $suggestion['placePrediction'] ?? null;
    if (!is_array($place)) {
        continue;
    }

    $mainText = trim((string)($place['structuredFormat']['mainText']['text'] ?? $place['text']['text'] ?? ''));
    if ($mainText === '') {
        continue;
    }

    $suggestions[] = [
        'mainText' => $mainText,
        'secondaryText' => trim((string)($place['structuredFormat']['secondaryText']['text'] ?? '')),
    ];
}

sendAutocompleteResponse(200, ['suggestions' => $suggestions]);
