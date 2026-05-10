<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$baseUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');

$hitsFile = getenv('FETCHER_HITS_FILE');
if ($hitsFile) {
    file_put_contents($hitsFile, ($_SERVER['REQUEST_URI'] ?? '') . "\n", FILE_APPEND | LOCK_EX);
}

switch ($path) {
    case '/json':
        header('Content-Type: application/json');
        $id = $_GET['id'] ?? '0';
        echo json_encode(['id' => $id, 'value' => 'val-' . $id]);

        return true;

    case '/paginated':
        // 3 pages of 2 items each, chained via Link: rel="next".
        header('Content-Type: application/json');
        $page = (int) ($_GET['p'] ?? 1);
        $total = 3;
        if ($page < $total) {
            header('Link: <' . $baseUrl . '/paginated?p=' . ($page + 1) . '>; rel="next"');
        }
        echo json_encode([
            ['page' => $page, 'item' => ($page - 1) * 2 + 1],
            ['page' => $page, 'item' => ($page - 1) * 2 + 2],
        ]);

        return true;

    case '/etag':
        $value = $_GET['v'] ?? 'default';
        $etag = '"' . $value . '"';
        header('ETag: ' . $etag);
        header('Content-Type: application/json');
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);

            return true;
        }
        echo json_encode(['value' => $value]);

        return true;

    case '/items-wrapper':
        header('Content-Type: application/json');
        echo json_encode([
            'total_count' => 2,
            'items' => [
                ['name' => 'one'],
                ['name' => 'two'],
            ],
        ]);

        return true;

    case '/picky':
        // Rich object for the field-whitelist test.
        header('Content-Type: application/json');
        echo json_encode([
            'sha' => 'abc',
            'message' => 'msg',
            'extra' => 'should-be-stripped',
            'commit' => [
                'message' => 'commit-msg',
                'author' => [
                    'name' => 'Alice',
                    'date' => '2024-01-01',
                ],
            ],
        ]);

        return true;

    case '/non-json':
        header('Content-Type: text/plain');
        echo 'Hello, World!';

        return true;

    case '/error500':
        http_response_code(500);
        echo 'boom';

        return true;

    case '/slow':
        usleep(100_000);
        header('Content-Type: application/json');
        echo json_encode(['id' => $_GET['id'] ?? '']);

        return true;
}

http_response_code(404);

return true;
