<?php
declare(strict_types=1);

namespace EmailAI;

final class Env
{
    /** @var array<string,string> */
    private array $vars = [];

    public static function load(string $file): self
    {
        $e = new self();
        if (!is_readable($file)) {
            throw new \RuntimeException("Env file not readable: $file (run setup.sh)");
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $v = trim($v);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
                $v = substr($v, 1, -1);
            }
            $e->vars[trim($k)] = $v;
        }
        return $e;
    }

    public static function fromArray(array $vars): self
    {
        $e = new self();
        $e->vars = array_map('strval', $vars);
        return $e;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $v = $this->vars[$key] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }

    /** @return array<string,array<string,string>> */
    public function accounts(): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $this->get('ACCOUNTS', '')))) as $id) {
            $p = 'ACCOUNT_' . strtoupper($id) . '_';
            $email = $this->get($p . 'EMAIL');
            $pass = $this->get($p . 'PASSWORD');
            $host = $this->get($p . 'HOST');
            if (!$email || !$pass || !$host) {
                fwrite(STDERR, "Skipping account $id: EMAIL, PASSWORD and HOST are required\n");
                continue;
            }
            $proto = strtolower($this->get($p . 'PROTOCOL', 'imap'));
            $enc = strtolower($this->get($p . 'ENCRYPTION', 'ssl'));
            $out[$id] = [
                'id' => $id,
                'weblogin' => $this->get($p . 'WEBLOGIN', ''),
                'email' => $email,
                'password' => $pass,
                'protocol' => $proto,
                'host' => $host,
                'encryption' => $enc,
                'port' => (int)$this->get($p . 'PORT', (string)self::defaultPort($proto, $enc)),
                'drafts' => $this->get($p . 'DRAFTS_FOLDER', 'Drafts'),
            ];
        }
        return $out;
    }

    private static function defaultPort(string $proto, string $enc): int
    {
        if ($proto === 'pop3') {
            return $enc === 'ssl' ? 995 : 110;
        }
        return $enc === 'ssl' ? 993 : 143;
    }
}
