<?php
// Router for `php -S`: replays the scripted replies in $MOCK_SCRIPT (JSON file) in order and logs requests.
$script = getenv('MOCK_SCRIPT');
$state = json_decode(file_get_contents($script), true);
$i = $state['i']++;
$reply = $state['replies'][min($i, count($state['replies']) - 1)];
$state['log'][] = ['path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '', 'body' => file_get_contents('php://input')];
file_put_contents($script, json_encode($state));
http_response_code($reply['status']);
foreach ($reply['headers'] ?? [] as $k => $v) header("$k: $v");
header('Content-Type: application/json');
echo json_encode($reply['body']);
