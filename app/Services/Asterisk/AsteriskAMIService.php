<?php

namespace App\Services\Asterisk;

use Illuminate\Support\Facades\Log;

/** Minimal AMI client (TCP). Credentials come only from config/.env. */
class AsteriskAMIService
{
    /** @var resource|null */
    private $sock = null;

    public function connect(int $timeout = 5): bool
    {
        $c = config('broadcast.asterisk');
        $this->sock = @stream_socket_client("tcp://{$c['host']}:{$c['ami_port']}", $errno, $err, $timeout);
        if (! $this->sock) {
            Log::warning('AMI connect failed', ['error' => $err]);

            return false;
        }
        stream_set_timeout($this->sock, $timeout);
        fgets($this->sock); // banner

        $r = $this->action(['Action' => 'Login', 'Username' => $c['ami_username'], 'Secret' => $c['ami_password'], 'Events' => 'on']);

        return ($r['Response'] ?? '') === 'Success';
    }

    public function disconnect(): void
    {
        if ($this->sock) {
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    public function originate(array $params): bool
    {
        try {
            if (! $this->connect()) {
                return false;
            }
            $r = $this->action(['Action' => 'Originate'] + $params);

            return ($r['Response'] ?? '') === 'Success';
        } catch (\Throwable $e) {
            Log::error('AMI originate failed', ['error' => $e->getMessage()]);

            return false;
        } finally {
            $this->disconnect();
        }
    }

    public function action(array $fields): array
    {
        $out = '';
        foreach ($fields as $k => $v) {
            foreach ((array) $v as $item) {
                $out .= "$k: $item\r\n";
            }
        }
        fwrite($this->sock, $out."\r\n");

        return $this->readMessage() ?? [];
    }

    /** Read one AMI message (block of Key: Value lines); null on timeout/disconnect. */
    public function readMessage(): ?array
    {
        $msg = [];
        while (($line = fgets($this->sock)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                if ($msg) {
                    return $msg;
                }
                continue;
            }
            if (str_contains($line, ': ')) {
                [$k, $v] = explode(': ', $line, 2);
                $msg[$k] = $v;
            }
        }

        return $msg ?: null;
    }

    public function connected(): bool
    {
        return $this->sock && ! feof($this->sock);
    }
}
