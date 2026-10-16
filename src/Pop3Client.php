<?php
declare(strict_types=1);

namespace EmailAI;

/** POP3 cannot store drafts on the server, so drafts are written to data/drafts/<account>/. */
final class Pop3Client extends LineConnection implements MailClient
{
    public function __construct(private array $a, private string $draftDir)
    {
        $this->connect($a['host'], $a['port'], $a['encryption']);
        $this->readOk();
        if ($a['encryption'] === 'starttls') {
            $this->cmd('STLS');
            $this->enableTls();
        }
        $this->cmd('USER ' . $a['email']);
        $this->cmd('PASS ' . $a['password']);
    }

    private function readOk(): string
    {
        $l = $this->readLine();
        if ($l[0] !== '+') {
            throw new \RuntimeException('POP3 error: ' . trim($l));
        }
        return $l;
    }

    private function cmd(string $c): string
    {
        $this->write("$c\r\n");
        return $this->readOk();
    }

    private function multiline(): string
    {
        $out = '';
        while (($l = $this->readLine()) !== ".\r\n") {
            $out .= ($l !== '' && $l[0] === '.') ? substr($l, 1) : $l;
        }
        return $out;
    }

    public function fetchNew(int $limit): array
    {
        $this->cmd('UIDL');
        $ids = [];
        foreach (explode("\n", trim($this->multiline())) as $row) {
            $p = preg_split('/\s+/', trim($row));
            if (count($p) === 2) {
                $ids[(int)$p[0]] = $p[1];
            }
        }
        $out = [];
        foreach (array_slice($ids, -$limit, null, true) as $n => $uid) {
            $this->cmd("RETR $n");
            $out[] = ['id' => $uid, 'raw' => $this->multiline()];
        }
        return $out;
    }

    public function saveDraft(string $raw): void
    {
        if (!is_dir($this->draftDir) && !mkdir($this->draftDir, 0700, true)) {
            throw new \RuntimeException('Cannot create ' . $this->draftDir);
        }
        $file = $this->draftDir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
        if (file_put_contents($file, $raw) !== strlen($raw)) {
            @unlink($file);
            throw new \RuntimeException('Cannot write draft: ' . $file);
        }
    }

    public function close(): void
    {
        try {
            $this->cmd('QUIT');
        } catch (\Throwable) {
        }
        @fclose($this->fp);
    }
}
