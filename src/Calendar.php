<?php
declare(strict_types=1);

namespace EmailAI;

/** One unified calendar (ICS file) shared by every mail account. Duplicates are ignored by UID. */
final class Calendar
{
    public function __construct(private string $file, private string $timezone = 'UTC')
    {
    }

    /** @param array<string,string> $e @return bool true if added */
    public function add(array $e, string $account): bool
    {
        $title = trim($e['title'] ?? '');
        try {
            $tz = new \DateTimeZone($this->timezone);
            $start = new \DateTimeImmutable($e['start'] ?? '', $tz);
            $end = !empty($e['end']) ? new \DateTimeImmutable($e['end'], $tz) : $start->modify('+1 hour');
        } catch (\Throwable) {
            return false;
        }
        if ($title === '') {
            return false;
        }
        $utc = new \DateTimeZone('UTC');
        $uid = sha1($title . $start->getTimestamp()) . '@emailai';
        $ics = is_file($this->file) ? (string)file_get_contents($this->file) : '';
        if (str_contains($ics, "UID:$uid")) {
            return false;
        }
        $f = fn(\DateTimeImmutable $d) => $d->setTimezone($utc)->format('Ymd\THis\Z');
        $vevent = "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\nDTSTART:" . $f($start)
            . "\r\nDTEND:" . $f($end) . "\r\nSUMMARY:" . self::esc('[TBD] ' . $title)
            . "\r\nSTATUS:TENTATIVE\r\nLOCATION:" . self::esc($e['location'] ?? '')
            . "\r\nDESCRIPTION:" . self::esc(trim(($e['description'] ?? '') . "\nSource account: $account")) . "\r\nEND:VEVENT\r\n";
        if ($ics === '') {
            $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//emailAI//EN\r\nEND:VCALENDAR\r\n";
        }
        $ics = preg_replace('/END:VCALENDAR\s*$/', '', $ics) . $vevent . "END:VCALENDAR\r\n";
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->file, $ics, LOCK_EX);
        return true;
    }

    private static function esc(string $s): string
    {
        $s = str_replace(["\\", ";", ",", "\r\n", "\n", "\r"], ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"], $s);
        return $s;
    }
}
