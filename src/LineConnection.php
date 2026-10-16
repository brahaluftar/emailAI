<?php
declare(strict_types=1);

namespace EmailAI;

/** Shared socket plumbing for IMAP/POP3. */
abstract class LineConnection
{
    /** @var resource */
    protected $fp;

    protected function connect(string $host, int $port, string $enc): void
    {
        if (!in_array($enc, ['ssl', 'starttls', 'none'], true)) {
            throw new \InvalidArgumentException('Unsupported mail encryption: ' . $enc);
        }
        $scheme = $enc === 'ssl' ? 'ssl://' : 'tcp://';
        $fp = @stream_socket_client($scheme . $host . ':' . $port, $errno, $err, 30);
        if (!$fp) {
            throw new \RuntimeException("Cannot connect to $host:$port: $err");
        }
        stream_set_timeout($fp, 60);
        $this->fp = $fp;
    }

    protected function readLine(): string
    {
        $l = fgets($this->fp);
        if ($l === false) {
            throw new \RuntimeException('Connection closed or timed out');
        }
        return $l;
    }

    protected function write(string $s): void
    {
        fwrite($this->fp, $s);
    }

    protected function enableTls(): void
    {
        if (!stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new \RuntimeException('STARTTLS failed');
        }
    }
}
