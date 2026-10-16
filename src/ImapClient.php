<?php
declare(strict_types=1);

namespace EmailAI;

final class ImapClient extends LineConnection implements MailClient
{
    private int $tag = 0;
    private string $drafts;

    public function __construct(array $a)
    {
        $this->drafts = $a['drafts'];
        $this->connect($a['host'], $a['port'], $a['encryption']);
        $this->readLine(); // greeting
        if ($a['encryption'] === 'starttls') {
            $this->cmd('STARTTLS');
            $this->enableTls();
        }
        $this->cmd('LOGIN ' . self::quote($a['email']) . ' ' . self::quote($a['password']));
    }

    private static function quote(string $s): string
    {
        return '"' . addcslashes($s, "\"\\") . '"';
    }

    /** @return array{0:string,1:string} [untagged text, status line] */
    private function cmd(string $cmd, ?string $literal = null): array
    {
        $tag = 'A' . ++$this->tag;
        $this->write("$tag $cmd\r\n");
        if ($literal !== null) {
            $l = $this->readLine();
            if ($l[0] !== '+') {
                throw new \RuntimeException("IMAP refused literal: $l");
            }
            $this->write($literal . "\r\n");
        }
        $buf = '';
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, "$tag ")) {
                if (!preg_match("/^$tag OK/i", $line)) {
                    throw new \RuntimeException('IMAP error: ' . trim($line));
                }
                return [$buf, $line];
            }
            $buf .= $line;
            // literal {n}
            if (preg_match('/\{(\d+)\}\r\n$/', $line, $m)) {
                $n = (int)$m[1];
                $data = '';
                while (strlen($data) < $n) {
                    $chunk = fread($this->fp, $n - strlen($data));
                    if ($chunk === false || $chunk === '') {
                        throw new \RuntimeException('IMAP read failed');
                    }
                    $data .= $chunk;
                }
                $buf .= $data;
            }
        }
    }

    public function fetchNew(int $limit): array
    {
        [$selected] = $this->cmd('SELECT INBOX');
        if (!preg_match('/\[UIDVALIDITY (\d+)\]/i', $selected, $validity)) {
            throw new \RuntimeException('IMAP SELECT did not return UIDVALIDITY');
        }
        [$res] = $this->cmd('UID SEARCH UNSEEN');
        if (!preg_match('/\* SEARCH ([\d ]+)/', $res, $m)) {
            return [];
        }
        $uids = array_slice(array_map('intval', explode(' ', trim($m[1]))), -$limit);
        $out = [];
        foreach ($uids as $n) {
            // BODY.PEEK keeps the message unread
            [$r] = $this->cmd("UID FETCH $n (BODY.PEEK[])");
            if (preg_match('/\{(\d+)\}\r\n/', $r, $mm, PREG_OFFSET_CAPTURE)) {
                $start = $mm[0][1] + strlen($mm[0][0]);
                $out[] = ['id' => $validity[1] . ':' . $n, 'raw' => substr($r, $start, (int)$mm[1][0])];
            }
        }
        return $out;
    }

    public function saveDraft(string $raw): void
    {
        $this->cmd('APPEND ' . self::quote($this->drafts) . ' (\\Draft) {' . strlen($raw) . '}', $raw);
    }

    public function close(): void
    {
        try {
            $this->cmd('LOGOUT');
        } catch (\Throwable) {
        }
        @fclose($this->fp);
    }
}
