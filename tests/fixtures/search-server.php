<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

switch ($path) {
    case '/api/v3/user':
        // Simulates an expired/revoked access token.
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['message' => 'Bad credentials']);

        return true;
}

http_response_code(404);

return true;
