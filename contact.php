<?php

declare(strict_types=1);

const MONDAY_BOARD_ID = 5027383033;
const MONDAY_API_URL = 'https://api.monday.com/v2';
const SECRET_FILE = '/home1/pixelwhi/.secrets/monday-token.php';
const CONFIG_FILE = '/home1/pixelwhi/.secrets/monday-config.php';
const SUCCESS_REDIRECT = 'thank-you.html';
const ERROR_REDIRECT = 'index.html?form=error#contact';

function redirectTo(string $location): void
{
    header('Location: ' . $location, true, 302);
    exit;
}

function fail(): void
{
    redirectTo(ERROR_REDIRECT);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$businessName = trim((string) ($_POST['business_name'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));
$website = trim((string) ($_POST['website'] ?? ''));
$formStartedAt = (int) ($_POST['form_started_at'] ?? 0);

if ($website !== '') {
    redirectTo(SUCCESS_REDIRECT);
}

if ($formStartedAt > 0 && (time() - $formStartedAt) < 3) {
    error_log('Monday form spam trap triggered: submitted too quickly');
    fail();
}

if ($name === '' || $email === '' || $message === '') {
    fail();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail();
}

if (!is_file(SECRET_FILE) || !is_file(CONFIG_FILE)) {
    error_log('Monday form config missing');
    fail();
}

require SECRET_FILE;
require CONFIG_FILE;

if (!defined('MONDAY_API_TOKEN') || !defined('MONDAY_COLUMN_NAME') || !defined('MONDAY_COLUMN_EMAIL') || !defined('MONDAY_COLUMN_BUSINESS_NAME') || !defined('MONDAY_COLUMN_MESSAGE')) {
    error_log('Monday form constants missing');
    fail();
}

$itemName = $name !== '' ? $name : 'New website enquiry';
$columnValues = [
    MONDAY_COLUMN_NAME => $name,
    MONDAY_COLUMN_EMAIL => ['email' => $email, 'text' => $email],
    MONDAY_COLUMN_BUSINESS_NAME => $businessName,
    MONDAY_COLUMN_MESSAGE => $message,
];

$mutation = <<<'GRAPHQL'
mutation ($boardId: ID!, $itemName: String!, $columnValues: JSON!) {
  create_item(board_id: $boardId, item_name: $itemName, column_values: $columnValues) {
    id
  }
}
GRAPHQL;

$payload = json_encode([
    'query' => $mutation,
    'variables' => [
        'boardId' => MONDAY_BOARD_ID,
        'itemName' => $itemName,
        'columnValues' => json_encode($columnValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($payload === false) {
    fail();
}

$ch = curl_init(MONDAY_API_URL);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: ' . MONDAY_API_TOKEN,
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => 20,
]);

$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $curlError || $httpCode >= 400) {
    error_log('Monday form transport error: ' . $curlError . ' HTTP ' . $httpCode);
    fail();
}

$decoded = json_decode($response, true);
if (!is_array($decoded) || !empty($decoded['errors'])) {
    error_log('Monday form API error: ' . $response);
    fail();
}

redirectTo(SUCCESS_REDIRECT);
