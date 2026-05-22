<?php

namespace App\WebSocket;

/**
 * Standalone PHP WebSocket server using native stream_socket functions.
 * Runs on two ports:
 *   - $wsPort (8080): public WebSocket for browser clients
 *   - $pushPort (8081): internal TCP for push messages from PHP controllers
 */
class Server
{
    /** @var resource[] WebSocket client connections, keyed by resource ID */
    private array $clients = [];

    /** @var array<int, int> Maps resource ID → authenticated user ID */
    private array $userMap = [];

    /** @var array<int, resource[]> Maps user ID → list of client resources */
    private array $userClients = [];

    /** @var resource|null */
    private $wsServer;
    /** @var resource|null */
    private $pushServer;

    public function __construct(
        private string $wsHost = '127.0.0.1',
        private int $wsPort = 8090,
        private int $pushPort = 8091,
    ) {}

    public function run(): void
    {
        $this->wsServer = stream_socket_server(
            "tcp://{$this->wsHost}:{$this->wsPort}",
            $errno, $errstr
        );
        if (!$this->wsServer) {
            throw new \RuntimeException("WebSocket server failed: $errstr ($errno)");
        }

        $this->pushServer = stream_socket_server(
            "tcp://127.0.0.1:{$this->pushPort}",
            $errno, $errstr
        );
        if (!$this->pushServer) {
            throw new \RuntimeException("Push server failed: $errstr ($errno)");
        }

        stream_set_blocking($this->wsServer, false);
        stream_set_blocking($this->pushServer, false);

        echo "[WS] WebSocket server listening on ws://{$this->wsHost}:{$this->wsPort}\n";
        echo "[WS] Internal push server listening on tcp://127.0.0.1:{$this->pushPort}\n";

        while (true) {
            $read = array_merge(
                [$this->wsServer, $this->pushServer],
                $this->clients
            );
            $write = $except = null;

            if (@stream_select($read, $write, $except, 0, 200000) === false) {
                continue;
            }

            // New WebSocket connection
            if (in_array($this->wsServer, $read)) {
                $newClient = @stream_socket_accept($this->wsServer, 0);
                if ($newClient) {
                    stream_set_blocking($newClient, false);
                    $this->handleNewWsClient($newClient);
                }
            }

            // New push connection (internal)
            if (in_array($this->pushServer, $read)) {
                $pushClient = @stream_socket_accept($this->pushServer, 0);
                if ($pushClient) {
                    $this->handlePushConnection($pushClient);
                }
            }

            // Data from existing WS clients
            foreach ($this->clients as $id => $client) {
                if (in_array($client, $read)) {
                    $data = @fread($client, 8192);
                    if ($data === false || $data === '') {
                        $this->removeClient($id);
                        continue;
                    }
                    $this->handleWsData($id, $data);
                }
            }
        }
    }

    private function handleNewWsClient(mixed $socket): void
    {
        $headers = '';
        $timeout = microtime(true) + 2;

        while (microtime(true) < $timeout) {
            $line = @fread($socket, 4096);
            if ($line) {
                $headers .= $line;
            }
            if (str_contains($headers, "\r\n\r\n")) {
                break;
            }
            usleep(1000);
        }

        if (!preg_match('/Sec-WebSocket-Key:\s*(.+)/i', $headers, $m)) {
            @fclose($socket);
            return;
        }

        $key = trim($m[1]);
        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-5AB5DC11CE56', true));

        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n\r\n";

        @fwrite($socket, $response);

        $id = (int) $socket;
        $this->clients[$id] = $socket;
        echo "[WS] Client connected (id: $id)\n";
    }

    private function handleWsData(int $id, string $raw): void
    {
        $decoded = $this->decodeWsFrame($raw);
        if ($decoded === null) {
            return;
        }

        if ($decoded['opcode'] === 8) {
            // Close frame
            $this->removeClient($id);
            return;
        }

        if ($decoded['opcode'] === 9) {
            // Ping → Pong
            $this->sendWsFrame($id, $decoded['payload'], 10);
            return;
        }

        // Text frame — expect JSON: {"type":"auth","userId":123}
        $msg = @json_decode($decoded['payload'], true);
        if (!$msg || !isset($msg['type'])) {
            return;
        }

        if ($msg['type'] === 'auth' && isset($msg['userId'])) {
            $userId = (int) $msg['userId'];
            $this->userMap[$id] = $userId;
            $this->userClients[$userId][$id] = $this->clients[$id];
            echo "[WS] Client $id authenticated as user $userId\n";

            // Send confirmation
            $this->sendWsFrame($id, json_encode(['type' => 'auth_ok']));
        }
    }

    private function handlePushConnection(mixed $socket): void
    {
        $data = '';
        $timeout = microtime(true) + 2;

        while (microtime(true) < $timeout) {
            $chunk = @fread($socket, 65536);
            if ($chunk) {
                $data .= $chunk;
            }
            if ($chunk === '' || $chunk === false) {
                break;
            }
            usleep(1000);
        }

        @fclose($socket);

        if (empty($data)) {
            return;
        }

        $msg = @json_decode($data, true);
        if (!$msg || !isset($msg['userId'])) {
            return;
        }

        $userId = (int) $msg['userId'];
        $payload = json_encode($msg['payload'] ?? $msg);

        echo "[WS] Push to user $userId: $payload\n";
        $this->pushToUser($userId, $payload);
    }

    public function pushToUser(int $userId, string $jsonPayload): void
    {
        if (!isset($this->userClients[$userId])) {
            return;
        }

        foreach ($this->userClients[$userId] as $id => $client) {
            if (!$this->sendWsFrame($id, $jsonPayload)) {
                $this->removeClient($id);
            }
        }
    }

    private function sendWsFrame(int $id, string $payload, int $opcode = 1): bool
    {
        if (!isset($this->clients[$id])) {
            return false;
        }

        $frame = $this->encodeWsFrame($payload, $opcode);
        $result = @fwrite($this->clients[$id], $frame);
        return $result !== false;
    }

    private function removeClient(int $id): void
    {
        if (isset($this->clients[$id])) {
            @fclose($this->clients[$id]);
            unset($this->clients[$id]);
        }

        if (isset($this->userMap[$id])) {
            $userId = $this->userMap[$id];
            unset($this->userClients[$userId][$id]);
            if (empty($this->userClients[$userId])) {
                unset($this->userClients[$userId]);
            }
            unset($this->userMap[$id]);
            echo "[WS] Client $id (user $userId) disconnected\n";
        } else {
            echo "[WS] Client $id disconnected\n";
        }
    }

    private function decodeWsFrame(string $data): ?array
    {
        if (strlen($data) < 2) {
            return null;
        }

        $firstByte = ord($data[0]);
        $secondByte = ord($data[1]);
        $opcode = $firstByte & 0x0F;
        $masked = ($secondByte & 0x80) !== 0;
        $len = $secondByte & 0x7F;
        $offset = 2;

        if ($len === 126) {
            if (strlen($data) < 4) return null;
            $len = unpack('n', substr($data, 2, 2))[1];
            $offset = 4;
        } elseif ($len === 127) {
            if (strlen($data) < 10) return null;
            $len = unpack('J', substr($data, 2, 8))[1];
            $offset = 10;
        }

        if ($masked) {
            if (strlen($data) < $offset + 4 + $len) return null;
            $mask = substr($data, $offset, 4);
            $offset += 4;
            $payload = '';
            for ($i = 0; $i < $len; $i++) {
                $payload .= chr(ord($data[$offset + $i]) ^ ord($mask[$i % 4]));
            }
        } else {
            if (strlen($data) < $offset + $len) return null;
            $payload = substr($data, $offset, $len);
        }

        return ['opcode' => $opcode, 'payload' => $payload];
    }

    private function encodeWsFrame(string $payload, int $opcode = 1): string
    {
        $len = strlen($payload);
        $frame = chr(0x80 | $opcode);

        if ($len < 126) {
            $frame .= chr($len);
        } elseif ($len < 65536) {
            $frame .= chr(126) . pack('n', $len);
        } else {
            $frame .= chr(127) . pack('J', $len);
        }

        return $frame . $payload;
    }
}
