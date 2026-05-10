<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

switch ($path) {
    case '/hello':
        header('Content-Type: text/plain; charset=utf-8');
        header('ETag: "etag-hello"');
        header('Link: <https://example.com/next>; rel="next"');
        echo 'Hello, World!';

        return true;

    case '/echo-headers':
        header('Content-Type: application/json');
        echo json_encode([
            'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
            'user-agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'x-url' => $_SERVER['HTTP_X_URL'] ?? '',
        ]);

        return true;

    case '/etag':
        header('ETag: "v1"');
        if ('"v1"' === ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) {
            http_response_code(304);

            return true;
        }
        header('Content-Type: text/plain');
        echo 'fresh content';

        return true;

    case '/echo-id':
        $id = $_GET['id'] ?? '';
        header('Content-Type: text/plain');
        echo 'id-' . $id;

        return true;

    case '/server-error':
        http_response_code(500);
        echo 'boom';

        return true;
}

http_response_code(404);

return true;
