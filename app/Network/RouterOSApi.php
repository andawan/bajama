<?php
declare(strict_types=1);

namespace BAJAMA\Network;

final class RouterOSApi
{
    private $socket = null;
    private float $timeout;
    private bool $connected = false;

    public function __construct(float $timeout = 8.0)
    {
        $this->timeout = max(1.0, min(60.0, $timeout));
    }

    public function connect(
        string $host,
        int $port,
        string $username,
        string $password,
        bool $ssl = false
    ): void {
        $host = trim($host);

        if ($host === '') {
            throw new \RuntimeException('Host MikroTik kosong.');
        }

        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('Port MikroTik tidak valid.');
        }

        $scheme = $ssl ? 'tls://' : '';
        $remote = $scheme . $host . ':' . $port;

        $verifyTls = filter_var(
            \env('MIKROTIK_TLS_VERIFY', 'true'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        if ($verifyTls === null) {
            $verifyTls = true;
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $verifyTls,
                'verify_peer_name' => $verifyTls,
                'allow_self_signed' => !$verifyTls,
            ],
        ]);

        $errno = 0;
        $errstr = '';

        $this->socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$this->socket) {
            if ($errno === 110 || stripos($errstr, 'timed out') !== false) {
                throw new \RuntimeException(
                    'Timeout koneksi ke MikroTik. Periksa host, port API ' . $port .
                    ', service api/api-ssl, firewall, dan akses dari server BAJAMA.'
                );
            }
            throw new \RuntimeException(
                'Gagal terhubung ke MikroTik: ' .
                ($errstr ?: 'connection failed') .
                ' (' . $errno . ')'
            );
        }

        stream_set_timeout(
            $this->socket,
            (int)$this->timeout,
            (int)(($this->timeout - (int)$this->timeout) * 1000000)
        );

        /*
         * RouterOS v6/v7:
         * Plain API login diterima pada instalasi yang menggunakan
         * authentication API standar.
         */
        $this->writeSentence([
            '/login',
            '=name=' . $username,
            '=password=' . $password,
        ]);

        $response = $this->readSentence();

        if (isset($response['!trap'])) {
            $message = $response['!trap'][0]['message']
                ?? 'Login MikroTik gagal.';

            $this->disconnect();
            throw new \RuntimeException($message);
        }

        if (isset($response['!done'][0]['ret']) && $response['!done'][0]['ret'] !== '') {
            $challenge = hex2bin((string)$response['!done'][0]['ret']);
            if ($challenge === false) {
                $this->disconnect();
                throw new \RuntimeException('Challenge login MikroTik tidak valid.');
            }

            $responseHash = '00' . md5("\x00" . $password . $challenge);
            $this->writeSentence([
                '/login',
                '=name=' . $username,
                '=response=' . $responseHash,
            ]);
            $response = $this->readSentence();

            if (isset($response['!trap'])) {
                $message = $response['!trap'][0]['message']
                    ?? 'Login challenge MikroTik gagal.';
                $this->disconnect();
                throw new \RuntimeException($message);
            }
        }

        if (!isset($response['!done'])) {
            $this->disconnect();
            throw new \RuntimeException(
                'Respons login MikroTik tidak valid.'
            );
        }

        $this->connected = true;
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }

        $this->socket = null;
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected && is_resource($this->socket);
    }

    public function command(
        string $command,
        array $attributes = [],
        array $queries = []
    ): array {
        if (!$this->isConnected()) {
            throw new \RuntimeException(
                'API MikroTik belum terhubung.'
            );
        }

        $sentence = [$command];

        foreach ($attributes as $key => $value) {
            $key = ltrim((string)$key, '=');
            $sentence[] = '=' . $key . '=' . (string)$value;
        }

        foreach ($queries as $query) {
            $sentence[] = '?' . ltrim((string)$query, '?');
        }

        $this->writeSentence($sentence);

        $rows = [];

        while (true) {
            $response = $this->readSentence();

            if (isset($response['!re'])) {
                foreach ($response['!re'] as $row) {
                    $rows[] = $row;
                }
            }

            if (isset($response['!trap'])) {
                $message = $response['!trap'][0]['message']
                    ?? 'RouterOS API error.';
                throw new \RuntimeException($command . ': ' . $message);
            }

            if (isset($response['!fatal'])) {
                $message = $response['!fatal'][0]['message']
                    ?? 'RouterOS API fatal error.';
                throw new \RuntimeException($message);
            }

            if (isset($response['!done'])) {
                if (isset($response['!done'][0]['ret'])) {
                    $rows[] = [
                        '.id' => (string)$response['!done'][0]['ret'],
                    ];
                }
                break;
            }
        }

        return $rows;
    }

    public function resource(): array
    {
        return $this->command('/system/resource/print');
    }

    public function identity(): array
    {
        return $this->command('/system/identity/print');
    }

    public function health(): array
    {
        return $this->command('/system/health/print');
    }

    public function interfaces(): array
    {
        return $this->command('/interface/print');
    }

    public function ipAddresses(): array
    {
        return $this->command('/ip/address/print');
    }

    public function routes(): array
    {
        return $this->command('/ip/route/print');
    }

    public function arp(): array
    {
        return $this->command('/ip/arp/print');
    }

    public function dhcpServers(): array
    {
        return $this->command('/ip/dhcp-server/print');
    }

    public function dhcpLeases(): array
    {
        return $this->command('/ip/dhcp-server/lease/print');
    }

    public function pppoeSecrets(): array
    {
        return $this->command('/ppp/secret/print');
    }

    public function pppActive(): array
    {
        return $this->command('/ppp/active/print');
    }

    public function pppProfiles(): array
    {
        return $this->command('/ppp/profile/print');
    }

    public function hotspotUsers(): array
    {
        return $this->command('/ip/hotspot/user/print');
    }

    public function hotspotActive(): array
    {
        return $this->command('/ip/hotspot/active/print');
    }

    public function hotspotServers(): array
    {
        return $this->command('/ip/hotspot/print');
    }

    public function simpleQueues(): array
    {
        return $this->command('/queue/simple/print');
    }

    public function firewallFilter(): array
    {
        return $this->command('/ip/firewall/filter/print');
    }

    public function firewallNat(): array
    {
        return $this->command('/ip/firewall/nat/print');
    }

    public function firewallMangle(): array
    {
        return $this->command('/ip/firewall/mangle/print');
    }

    public function firewallAddressList(): array
    {
        return $this->command('/ip/firewall/address-list/print');
    }

    public function firewallRaw(): array
    {
        return $this->command('/ip/firewall/raw/print');
    }

    public function vlans(): array
    {
        return $this->command('/interface/vlan/print');
    }

    public function dns(): array
    {
        return $this->command('/ip/dns/print');
    }

    public function clock(): array
    {
        return $this->command('/system/clock/print');
    }

    public function users(): array
    {
        return $this->command('/user/print');
    }

    public function logs(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        return $this->command(
            '/log/print',
            [],
            []
        );
    }

    public function raw(
        string $command,
        array $attributes = [],
        array $queries = []
    ): array {
        return $this->command(
            $command,
            $attributes,
            $queries
        );
    }

    private function writeSentence(array $words): void
    {
        foreach ($words as $word) {
            $this->writeWord((string)$word);
        }

        $this->writeWord('');
    }

    private function writeWord(string $word): void
    {
        $length = strlen($word);

        if ($length < 0x80) {
            $prefix = chr($length);
        } elseif ($length < 0x4000) {
            $length |= 0x8000;
            $prefix =
                chr(($length >> 8) & 0xFF) .
                chr($length & 0xFF);
        } elseif ($length < 0x200000) {
            $length |= 0xC00000;
            $prefix =
                chr(($length >> 16) & 0xFF) .
                chr(($length >> 8) & 0xFF) .
                chr($length & 0xFF);
        } elseif ($length < 0x10000000) {
            $length |= 0xE0000000;
            $prefix =
                chr(($length >> 24) & 0xFF) .
                chr(($length >> 16) & 0xFF) .
                chr(($length >> 8) & 0xFF) .
                chr($length & 0xFF);
        } else {
            $prefix = chr(0xF0) . pack('N', $length);
        }

        $this->writeAll($prefix . $word);
    }

    private function readSentence(): array
    {
        $sentence = [];

        while (true) {
            $word = $this->readWord();

            if ($word === '') {
                break;
            }

            $sentence[] = $word;
        }

        $type = $sentence[0] ?? '';
        $row = [];

        foreach (array_slice($sentence, 1) as $word) {
            if ($word !== '' && $word[0] === '=') {
                $parts = explode(
                    '=',
                    substr($word, 1),
                    2
                );

                $row[$parts[0]] = $parts[1] ?? '';
            }
        }

        if ($type === '!re') {
            return ['!re' => [$row]];
        }

        if (
            $type === '!trap' ||
            $type === '!fatal' ||
            $type === '!done'
        ) {
            return [$type => [$row]];
        }

        return [
            $type ?: '!unknown' => [$row]
        ];
    }

    private function readWord(): string
    {
        $firstData = $this->readAll(1);

        if ($firstData === '') {
            throw new \RuntimeException(
                'Koneksi API MikroTik terputus.'
            );
        }

        $first = ord($firstData);

        if (($first & 0x80) === 0) {
            $length = $first;
        } elseif (($first & 0xC0) === 0x80) {
            $length =
                (($first & 0x3F) << 8) |
                ord($this->readAll(1));
        } elseif (($first & 0xE0) === 0xC0) {
            $length =
                (($first & 0x1F) << 16) |
                (ord($this->readAll(1)) << 8) |
                ord($this->readAll(1));
        } elseif (($first & 0xF0) === 0xE0) {
            $length =
                (($first & 0x0F) << 24) |
                (ord($this->readAll(1)) << 16) |
                (ord($this->readAll(1)) << 8) |
                ord($this->readAll(1));
        } elseif ($first === 0xF0) {
            $length = unpack(
                'N',
                $this->readAll(4)
            )[1];
        } else {
            throw new \RuntimeException(
                'Format panjang word RouterOS tidak valid.'
            );
        }

        return $length > 0
            ? $this->readAll($length)
            : '';
    }

    private function readAll(int $length): string
    {
        $data = '';

        while (strlen($data) < $length) {
            $chunk = @fread(
                $this->socket,
                $length - strlen($data)
            );

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data(
                    $this->socket
                );

                if (!empty($meta['timed_out'])) {
                    throw new \RuntimeException(
                        'Timeout saat membaca API MikroTik.'
                    );
                }

                throw new \RuntimeException(
                    'Koneksi API MikroTik terputus.'
                );
            }

            $data .= $chunk;
        }

        return $data;
    }

    private function writeAll(string $data): void
    {
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $written = @fwrite(
                $this->socket,
                substr($data, $offset)
            );

            if ($written === false || $written === 0) {
                throw new \RuntimeException(
                    'Gagal mengirim data ke MikroTik.'
                );
            }

            $offset += $written;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
