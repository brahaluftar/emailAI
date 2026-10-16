<?php
declare(strict_types=1);

namespace EmailAI;

final class Processor
{
    public function __construct(
        private OpenAIAgent $agent,
        private Calendar $calendar,
        private string $stateFile,
        private \Closure $log
    ) {
    }

    /** @param callable(array):MailClient $factory */
    public function run(array $accounts, callable $factory, int $limit = 20): void
    {
        $state = is_file($this->stateFile) ? (json_decode((string)file_get_contents($this->stateFile), true) ?: []) : [];
        foreach ($accounts as $acc) {
            try {
                $client = $factory($acc);
            } catch (\Throwable $e) {
                ($this->log)("[{$acc['id']}] connect failed: " . $e->getMessage());
                continue;
            }
            try {
                foreach ($client->fetchNew($limit) as $msg) {
                    $key = $acc['id'];
                    if (in_array($msg['id'], $state[$key] ?? [], true)) {
                        continue;
                    }
                    try {
                        $this->handle($client, $acc, $msg['raw']);
                        $state[$key][] = $msg['id'];
                        $state[$key] = array_slice($state[$key], -500);
                        $this->save($state);
                    } catch (\Throwable $e) {
                        ($this->log)("[$key] message {$msg['id']} failed: " . $e->getMessage());
                    }
                }
            } catch (\Throwable $e) {
                ($this->log)("[{$acc['id']}] error: " . $e->getMessage());
            } finally {
                $client->close();
            }
        }
    }

    private function handle(MailClient $client, array $acc, string $raw): void
    {
        $m = MimeMessage::parse($raw);
        $from = $m->header('reply-to') ?: $m->header('from');
        $subject = $m->header('subject');
        $r = $this->agent->analyze($from, $subject, $m->text);
        foreach ($r['events'] as $ev) {
            if ($this->calendar->add($ev, $acc['email'])) {
                ($this->log)("[{$acc['id']}] calendar event added: " . ($ev['title'] ?? ''));
            }
        }
        if ($r['needs_reply'] && trim($r['reply']) !== '') {
            $client->saveDraft(self::buildDraft($acc['email'], $from, $subject, $m->header('message-id'), $r['reply']));
            ($this->log)("[{$acc['id']}] draft saved for: $subject");
        }
    }

    public static function buildDraft(string $me, string $to, string $subject, string $msgId, string $body): string
    {
        $subject = preg_match('/^re:/i', $subject) ? $subject : "Re: $subject";
        $h = "From: $me\r\nTo: " . str_replace(["\r", "\n"], '', $to)
            . "\r\nSubject: " . mb_encode_mimeheader(str_replace(["\r", "\n"], '', $subject), 'UTF-8')
            . "\r\nDate: " . date('r');
        if ($msgId !== '') {
            $id = str_replace(["\r", "\n"], '', $msgId);
            $h .= "\r\nIn-Reply-To: $id\r\nReferences: $id";
        }
        $h .= "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        return $h . chunk_split(base64_encode($body));
    }

    private function save(array $state): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($this->stateFile, json_encode($state), LOCK_EX);
    }
}
