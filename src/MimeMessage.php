<?php
declare(strict_types=1);

namespace EmailAI;

final class MimeMessage
{
    /** @var array<string,string> lower-cased header => value (decoded) */
    public array $headers = [];
    public string $text = '';

    public static function parse(string $raw): self
    {
        $m = new self();
        [$h, $b] = self::split($raw);
        $m->headers = self::headers($h);
        $m->text = self::bodyText($m->headers, $b);
        return $m;
    }

    public function header(string $n): string
    {
        return $this->headers[strtolower($n)] ?? '';
    }

    private static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $p = strpos($raw, "\n\n");
        return $p === false ? [$raw, ''] : [substr($raw, 0, $p), substr($raw, $p + 2)];
    }

    private static function headers(string $h): array
    {
        $h = preg_replace('/\n[ \t]+/', ' ', $h);
        $out = [];
        foreach (explode("\n", $h) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $k = strtolower(trim($k));
                $out[$k] ??= self::decodeHeader(trim($v));
            }
        }
        return $out;
    }

    public static function decodeHeader(string $v): string
    {
        return function_exists('mb_decode_mimeheader') ? mb_decode_mimeheader($v) : $v;
    }

    private static function bodyText(array $headers, string $body): string
    {
        $ct = $headers['content-type'] ?? 'text/plain';
        if (preg_match('/^multipart\/[^;]+;.*boundary="?([^";]+)"?/is', $ct, $m)) {
            $plain = '';
            $html = '';
            foreach (explode('--' . $m[1], $body) as $part) {
                if (trim($part) === '' || str_starts_with(trim($part), '--')) {
                    continue;
                }
                [$ph, $pb] = self::split(ltrim($part, "\n"));
                $hs = self::headers($ph);
                if (stripos($hs['content-disposition'] ?? '', 'attachment') === 0) {
                    continue;
                }
                $t = self::bodyText($hs, $pb);
                if (stripos($hs['content-type'] ?? 'text/plain', 'text/html') === 0 || str_contains($hs['content-type'] ?? '', 'multipart/') && $plain === '') {
                    $html .= $t;
                } elseif (stripos($hs['content-type'] ?? 'text/plain', 'text/plain') === 0) {
                    $plain .= $t;
                } else {
                    $plain .= $t;
                }
            }
            return trim($plain !== '' ? $plain : $html);
        }
        $mediaType = strtolower(trim(explode(';', $ct, 2)[0]));
        if (!in_array($mediaType, ['text/plain', 'text/html'], true)) {
            return '';
        }
        $enc = strtolower(trim($headers['content-transfer-encoding'] ?? '7bit'));
        $body = match ($enc) {
            'base64' => (string)base64_decode($body),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
        if (preg_match('/charset="?([\w-]+)/i', $ct, $c) && strcasecmp($c[1], 'utf-8') !== 0 && function_exists('mb_convert_encoding')) {
            $conv = @mb_convert_encoding($body, 'UTF-8', $c[1]);
            $body = $conv !== false ? $conv : $body;
        }
        if (stripos($ct, 'text/html') === 0) {
            $body = html_entity_decode(strip_tags(preg_replace('/<(style|script)\b.*?<\/\1>/is', '', $body)));
        }
        return trim($body);
    }
}
