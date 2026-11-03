<?php

namespace App\Services\Email;

class SocketImapTransport implements ImapTransport
{
    /** @var resource */
    private $fp;

    /** @throws EmailException */
    public function __construct(string $host, int $port, bool $implicitTls, int $timeout)
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $fp = @stream_socket_client(($implicitTls ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $err, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new EmailException(__('IMAP: could not connect to :host:port (:error)', ['host' => $host, 'port' => $port, 'error' => $err ?: 'timeout']));
        }
        stream_set_timeout($fp, $timeout);
        $this->fp = $fp;
    }

    public function readLine(): ?string
    {
        $l = fgets($this->fp, 65536);

        return $l === false ? null : rtrim($l, "\r\n");
    }

    public function read(int $bytes): string
    {
        $out = '';
        while (strlen($out) < $bytes) {
            $chunk = fread($this->fp, min(65536, $bytes - strlen($out)));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $out .= $chunk;
        }

        return $out;
    }

    public function write(string $data): void
    {
        fwrite($this->fp, $data);
    }

    public function enableTls(): bool
    {
        return (bool) @stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    }

    public function close(): void
    {
        if (is_resource($this->fp)) {
            fclose($this->fp);
        }
    }
}
