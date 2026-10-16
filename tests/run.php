<?php
// Minimal self-contained tests: php tests/run.php
require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/MimeMessage.php';
require __DIR__ . '/../src/Calendar.php';
require __DIR__ . '/../src/OpenAIAgent.php';
use EmailAI\{Env, MimeMessage, Calendar, OpenAIAgent};

$fail = 0;
function ok(bool $c, string $n): void { global $fail; echo ($c ? 'PASS' : 'FAIL') . " $n\n"; $fail += $c ? 0 : 1; }

$env = Env::fromArray(['ACCOUNTS' => 'a,b', 'ACCOUNT_A_EMAIL' => 'x@y', 'ACCOUNT_A_PASSWORD' => 'p', 'ACCOUNT_A_HOST' => 'h',
    'ACCOUNT_B_EMAIL' => 'q@y', 'ACCOUNT_B_PASSWORD' => 'p', 'ACCOUNT_B_HOST' => 'h2', 'ACCOUNT_B_PROTOCOL' => 'pop3']);
$a = $env->accounts();
ok(count($a) === 2 && $a['a']['port'] === 993 && $a['b']['port'] === 995, 'env accounts + default ports');

$raw = "From: A <a@x>\r\nSubject: Hi\r\nContent-Type: multipart/alternative; boundary=\"B\"\r\n\r\n--B\r\nContent-Type: text/plain\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nHello=20there\r\n--B\r\nContent-Type: text/html\r\n\r\n<p>x</p>\r\n--B--\r\n";
$m = MimeMessage::parse($raw);
ok($m->text === 'Hello there' && $m->header('subject') === 'Hi', 'mime multipart');

$f = tempnam(sys_get_temp_dir(), 'cal');
unlink($f);
$c = new Calendar($f, 'UTC');
$e = ['title' => 'Lunch, team', 'start' => '2030-01-01T12:00:00'];
ok($c->add($e, 'x') && !$c->add($e, 'y'), 'calendar add + dedupe');
ok(substr_count(file_get_contents($f), 'BEGIN:VEVENT') === 1 && str_contains(file_get_contents($f), 'Lunch\\, team'), 'ics content');
unlink($f);

$ag = new OpenAIAgent('k', 'm', 'u', 'i', 'UTC', fn($p) => ['choices' => [['message' => ['content' => '{"needs_reply":true,"reply":"ok","events":[{"title":"T"}]}']]]]);
$r = $ag->analyze('a', 's', 'b');
ok($r['reply'] === 'ok' && count($r['events']) === 1, 'agent parse');
exit($fail ? 1 : 0);
