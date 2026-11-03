<?php

namespace App\Services\Email;

/** Byte pipe to an IMAP server (a real socket in production, a script in tests). */
interface ImapTransport
{
    /** One line without the line break, null at end of stream */
    public function readLine(): ?string;

    public function read(int $bytes): string;

    public function write(string $data): void;

    /** Switches an open plain connection to TLS (STARTTLS). */
    public function enableTls(): bool;

    public function close(): void;
}
