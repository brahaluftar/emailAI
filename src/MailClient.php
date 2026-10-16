<?php
declare(strict_types=1);

namespace EmailAI;

interface MailClient
{
    /** @return array<int,array{id:string,raw:string}> unseen/new messages */
    public function fetchNew(int $limit): array;

    /** Store a raw RFC822 draft. */
    public function saveDraft(string $raw): void;

    public function close(): void;
}
