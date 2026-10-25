<?php
declare(strict_types=1);

require __DIR__ . '/../src/Exceptions.php';
require __DIR__ . '/../src/Client.php';

use SimpleVerifier\{Client, PaymentRequiredException, ServiceUnavailableException, BadRequestException};

$script = tempnam(sys_get_temp_dir(), 'svmock');
$port = random_int(20000, 40000);
putenv("MOCK_SCRIPT=$script");
$server = proc_open(['php', '-S', "127.0.0.1:$port", __DIR__ . '/mock_server.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, ['MOCK_SCRIPT' => $script, 'PATH' => getenv('PATH')]);
usleep(600000);

$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    $failures += $ok ? 0 : 1;
}
function script(array $replies): void
{
    global $script;
    file_put_contents($script, json_encode(['i' => 0, 'replies' => $replies, 'log' => []]));
}
function log_(): array
{
    global $script;
    return json_decode(file_get_contents($script), true)['log'];
}
function client(array $extra = []): Client
{
    global $port, $sleeps;
    $sleeps = [];
    return new Client('sv_live_test', ['base_url' => "http://127.0.0.1:$port", 'sleep' => function (float $s) use (&$sleeps) { $sleeps[] = $s; }] + $extra);
}

$ok = ['status' => 200, 'body' => ['email' => 'a@b.co', 'status' => 'risky', 'reason' => 'Accepts all addresses']];

script([$ok]);
$r = client()->verifyEmail(' a@b.co ');
check('verifyEmail parses and sends bearer key', $r['status'] === 'risky' && log_()[0]['auth'] === 'Bearer sv_live_test' && log_()[0]['body'] === '{"email":"a@b.co"}');

script([['status' => 429, 'headers' => ['Retry-After' => '7'], 'body' => ['error' => 'slow', 'retry_after_seconds' => 7]], $ok]);
$r = client()->verifyEmail('a@b.co');
check('429 retried honouring Retry-After', $r['status'] === 'risky' && $sleeps === [7.0] && count(log_()) === 2);

script([['status' => 503, 'body' => ['error' => 'down']]]);
try { client()->verifyEmail('a@b.co'); check('503 gives up', false); } catch (ServiceUnavailableException $e) { check('503 gives up after 3 calls', count(log_()) === 3); }

script([['status' => 402, 'headers' => ['Retry-After' => '3600'], 'body' => ['error' => 'Quota used', 'retry_after_seconds' => 3600]]]);
try { client()->verifyEmail('a@b.co'); check('402 raises', false); } catch (PaymentRequiredException $e) { check('402 raised, not retried, retryAfter set', count(log_()) === 1 && $e->retryAfter === 3600.0 && $e->status === 402); }

script([['status' => 200, 'body' => ['phone_status' => 'valid', 'phone_e164' => '+447911123456']]]);
$r = client()->verifyPhone('07911 123456', 'gb', false);
check('verifyPhone body', $r['phone_e164'] === '+447911123456' && log_()[0]['body'] === '{"phone":"07911 123456","country":"GB","live":false}');

try { client()->verifyEmail('  '); check('empty email', false); } catch (BadRequestException $e) { check('empty email rejected locally', true); }

proc_terminate($server);
unlink($script);
exit($failures ? 1 : 0);
