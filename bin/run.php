#!/usr/bin/env php
<?php
declare(strict_types=1);

spl_autoload_register(function (string $c) {
    $f = __DIR__ . '/../src/' . substr($c, strlen('EmailAI\\')) . '.php';
    if (str_starts_with($c, 'EmailAI\\') && is_file($f)) {
        require $f;
    }
});

use EmailAI\{Env, OpenAIAgent, Calendar, Processor, ImapClient, Pop3Client};

$root = dirname(__DIR__);
$lock = fopen($root . '/data/.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // previous run still active
}
$log = function (string $m) use ($root) {
    $line = '[' . date('c') . "] $m\n";
    echo $line;
    @file_put_contents($root . '/data/emailai.log', $line, FILE_APPEND);
};

try {
    $env = Env::load($argv[1] ?? $root . '/.env');
    $tz = $env->get('TIMEZONE', 'UTC');
    date_default_timezone_set($tz);
    $key = $env->get('OPENAI_API_KEY');
    if (!$key) {
        throw new RuntimeException('OPENAI_API_KEY missing');
    }
    $cal = $env->get('CALENDAR_FILE', 'data/calendar.ics');
    if ($cal[0] !== '/') {
        $cal = $root . '/' . $cal;
    }
    $agent = new OpenAIAgent($key, $env->get('OPENAI_MODEL', 'gpt-4o-mini'), $env->get('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        $env->get('AGENT_INSTRUCTIONS', 'Write concise, polite replies.'), $tz);
    $p = new Processor($agent, new Calendar($cal, $tz), $root . '/data/state.json', $log);
    $p->run($env->accounts(), fn(array $a) => $a['protocol'] === 'pop3'
        ? new Pop3Client($a, $root . '/data/drafts/' . $a['id'])
        : new ImapClient($a));
} catch (Throwable $e) {
    $log('FATAL: ' . $e->getMessage());
    exit(1);
}
