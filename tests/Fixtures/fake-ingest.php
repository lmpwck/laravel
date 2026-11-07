<?php

// Router for `php -S`: a fake lampwick HTTP ingest. Every request is appended as one JSON line
// to $FAKE_INGEST_DIR/requests.jsonl. Responses come from $FAKE_INGEST_DIR/responses.json, a
// list of [status, retryAfter|null] consumed in order; when it is empty the answer is 202.

$dir = getenv('FAKE_INGEST_DIR');
$body = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];

file_put_contents("$dir/requests.jsonl", json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'authorization' => $headers['Authorization'] ?? null,
    'content_length' => $headers['Content-Length'] ?? null,
    'content_type' => $headers['Content-Type'] ?? null,
    'body' => $body,
])."\n", FILE_APPEND | LOCK_EX);

$responses = is_file("$dir/responses.json") ? json_decode(file_get_contents("$dir/responses.json"), true) : [];
[$status, $retryAfter] = array_shift($responses) ?? [202, null];
file_put_contents("$dir/responses.json", json_encode($responses));

if ($_SERVER['REQUEST_URI'] !== '/ingest' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $status = 404;
}
if (($headers['Authorization'] ?? '') !== 'Bearer '.getenv('FAKE_INGEST_TOKEN')) {
    $status = 401;
}

http_response_code($status);
header('Content-Type: application/json');
if ($retryAfter !== null) {
    header("Retry-After: $retryAfter");
}
$records = json_decode($body, true);
echo $status === 202 ? json_encode(['accepted' => count($records)]) : json_encode(['error' => "status $status"]);
