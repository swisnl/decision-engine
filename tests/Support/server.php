<?php

declare(strict_types=1);

// Router for the PHP built-in server used in tests.
// Query: status (int), delay_ms (int), body (json string), header_<name>=<value>, echo=1 (echo request as body).

$status = (int) ($_GET['status'] ?? 200);
$delay = (int) ($_GET['delay_ms'] ?? 0);

if ($delay > 0) {
    usleep($delay * 1000);
}

http_response_code($status);
header('Content-Type: application/json');
header('x-typesafe-request-id: req_' . bin2hex(random_bytes(4)));

foreach ($_GET as $key => $value) {
    if (str_starts_with($key, 'header_')) {
        header(substr($key, 7) . ': ' . $value);
    }
}

if (($_GET['echo'] ?? '') === '1') {
    $headers = [];

    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
        }
    }

    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'headers' => $headers,
        'body' => json_decode((string) file_get_contents('php://input'), true),
    ], JSON_THROW_ON_ERROR);

    return;
}

echo $_GET['body'] ?? '{}';
