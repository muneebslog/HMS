<?php

namespace App\Services\Zkteco;

use Carbon\CarbonImmutable;

/**
 * Minimal ZKTeco "standalone SDK" protocol client over TCP (port 4370).
 *
 * Supports reading enrolled users and attendance logs. Ported from the
 * behaviour of the widely used pyzk library.
 */
class ZktecoClient
{
    private const CMD_CONNECT = 1000;

    private const CMD_EXIT = 1001;

    private const CMD_ENABLE_DEVICE = 1002;

    private const CMD_DISABLE_DEVICE = 1003;

    private const CMD_AUTH = 1102;

    private const CMD_ACK_OK = 2000;

    private const CMD_ACK_UNAUTH = 2005;

    private const CMD_PREPARE_DATA = 1500;

    private const CMD_DATA = 1501;

    private const CMD_FREE_DATA = 1502;

    private const CMD_PREPARE_BUFFER = 1503;

    private const CMD_READ_BUFFER = 1504;

    private const CMD_GET_FREE_SIZES = 50;

    private const CMD_USER_TEMP_READ = 9;

    private const CMD_ATTENDANCE_LOG_READ = 13;

    private const FCT_USER = 5;

    private const MAX_CHUNK = 0xFFC0;

    private const USHRT_MAX = 65535;

    /** @var resource|null */
    private $socket = null;

    private int $sessionId = 0;

    private int $replyId = self::USHRT_MAX - 1;

    public function __construct(
        public readonly string $host,
        public readonly int $port = 4370,
        public readonly int $commKey = 0,
        public readonly float $timeout = 10.0,
    ) {}

    public function __destruct()
    {
        $this->disconnect();
    }

    public function connect(): void
    {
        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errorCode,
            $errorMessage,
            $this->timeout,
        );

        if ($socket === false) {
            throw new ZktecoException("Cannot reach device at {$this->host}:{$this->port} ({$errorMessage}).");
        }

        stream_set_timeout($socket, (int) ceil($this->timeout));
        $this->socket = $socket;
        $this->sessionId = 0;
        $this->replyId = self::USHRT_MAX - 1;

        $response = $this->sendCommand(self::CMD_CONNECT);
        $this->sessionId = $response['session'];

        if ($response['code'] === self::CMD_ACK_UNAUTH) {
            $response = $this->sendCommand(self::CMD_AUTH, self::makeCommKey($this->commKey, $this->sessionId));
        }

        if ($response['code'] !== self::CMD_ACK_OK) {
            $this->closeSocket();

            throw new ZktecoException($response['code'] === self::CMD_ACK_UNAUTH
                ? 'Device rejected the communication key.'
                : "Device refused the connection (code {$response['code']}).");
        }
    }

    public function disconnect(): void
    {
        if ($this->socket === null) {
            return;
        }

        try {
            $this->sendCommand(self::CMD_EXIT);
        } catch (ZktecoException) {
            // The device may already have dropped the connection.
        }

        $this->closeSocket();
    }

    public function disableDevice(): void
    {
        $this->sendCommand(self::CMD_DISABLE_DEVICE);
    }

    public function enableDevice(): void
    {
        $this->sendCommand(self::CMD_ENABLE_DEVICE);
    }

    /**
     * @return array{users: int, records: int}
     */
    public function readSizes(): array
    {
        $response = $this->sendCommand(self::CMD_GET_FREE_SIZES);

        if ($response['code'] !== self::CMD_ACK_OK || strlen($response['data']) < 80) {
            throw new ZktecoException('Device did not report its storage sizes.');
        }

        $fields = array_values(unpack('V20', substr($response['data'], 0, 80)));

        return [
            'users' => $fields[4],
            'records' => $fields[8],
        ];
    }

    /**
     * @return list<array{uid: int, user_id: string, name: string, privilege: int, card: ?string}>
     */
    public function getUsers(): array
    {
        $userCount = $this->readSizes()['users'];

        if ($userCount === 0) {
            return [];
        }

        $data = $this->readWithBuffer(self::CMD_USER_TEMP_READ, self::FCT_USER);

        if (strlen($data) <= 4) {
            return [];
        }

        $totalSize = unpack('V', substr($data, 0, 4))[1];

        return self::parseUsers(substr($data, 4), intdiv($totalSize, $userCount));
    }

    /**
     * @param  array<int, string>  $userIdsByUid  Device uid => enrolled user id, needed for the compact 8-byte log format.
     * @return list<array{uid: ?int, user_id: string, punched_at: CarbonImmutable, verify_type: int, punch_state: int}>
     */
    public function getAttendance(array $userIdsByUid = []): array
    {
        $recordCount = $this->readSizes()['records'];

        if ($recordCount === 0) {
            return [];
        }

        $data = $this->readWithBuffer(self::CMD_ATTENDANCE_LOG_READ);

        if (strlen($data) <= 4) {
            return [];
        }

        $totalSize = unpack('V', substr($data, 0, 4))[1];

        return self::parseAttendance(substr($data, 4), intdiv($totalSize, $recordCount), $userIdsByUid);
    }

    /**
     * @return list<array{uid: int, user_id: string, name: string, privilege: int, card: ?string}>
     */
    public static function parseUsers(string $data, int $packetSize): array
    {
        if (! in_array($packetSize, [28, 72], true)) {
            throw new ZktecoException("Unsupported user record size ({$packetSize} bytes).");
        }

        $users = [];

        foreach (str_split($data, $packetSize) as $record) {
            if (strlen($record) < $packetSize) {
                break;
            }

            if ($packetSize === 28) {
                $card = unpack('V', substr($record, 16, 4))[1];
                $users[] = [
                    'uid' => unpack('v', substr($record, 0, 2))[1],
                    'privilege' => ord($record[2]),
                    'name' => self::cString(substr($record, 8, 8)),
                    'card' => $card > 0 ? (string) $card : null,
                    'user_id' => (string) unpack('V', substr($record, 24, 4))[1],
                ];

                continue;
            }

            $card = unpack('V', substr($record, 35, 4))[1];
            $users[] = [
                'uid' => unpack('v', substr($record, 0, 2))[1],
                'privilege' => ord($record[2]),
                'name' => self::cString(substr($record, 11, 24)),
                'card' => $card > 0 ? (string) $card : null,
                'user_id' => self::cString(substr($record, 48, 24)),
            ];
        }

        return $users;
    }

    /**
     * @param  array<int, string>  $userIdsByUid
     * @return list<array{uid: ?int, user_id: string, punched_at: CarbonImmutable, verify_type: int, punch_state: int}>
     */
    public static function parseAttendance(string $data, int $recordSize, array $userIdsByUid = []): array
    {
        if (! in_array($recordSize, [8, 16, 40], true)) {
            throw new ZktecoException("Unsupported attendance record size ({$recordSize} bytes).");
        }

        $records = [];

        foreach (str_split($data, $recordSize) as $record) {
            if (strlen($record) < $recordSize) {
                break;
            }

            if ($recordSize === 8) {
                $uid = unpack('v', substr($record, 0, 2))[1];
                $records[] = [
                    'uid' => $uid,
                    'user_id' => $userIdsByUid[$uid] ?? (string) $uid,
                    'verify_type' => ord($record[2]),
                    'punched_at' => self::decodeTime(unpack('V', substr($record, 3, 4))[1]),
                    'punch_state' => ord($record[7]),
                ];

                continue;
            }

            if ($recordSize === 16) {
                $records[] = [
                    'uid' => null,
                    'user_id' => (string) unpack('V', substr($record, 0, 4))[1],
                    'punched_at' => self::decodeTime(unpack('V', substr($record, 4, 4))[1]),
                    'verify_type' => ord($record[8]),
                    'punch_state' => ord($record[9]),
                ];

                continue;
            }

            $records[] = [
                'uid' => unpack('v', substr($record, 0, 2))[1],
                'user_id' => self::cString(substr($record, 2, 24)),
                'verify_type' => ord($record[26]),
                'punched_at' => self::decodeTime(unpack('V', substr($record, 27, 4))[1]),
                'punch_state' => ord($record[31]),
            ];
        }

        return $records;
    }

    /**
     * Decode the device's packed timestamp (local device time).
     */
    public static function decodeTime(int $packed): CarbonImmutable
    {
        $second = $packed % 60;
        $packed = intdiv($packed, 60);
        $minute = $packed % 60;
        $packed = intdiv($packed, 60);
        $hour = $packed % 24;
        $packed = intdiv($packed, 24);
        $day = $packed % 31 + 1;
        $packed = intdiv($packed, 31);
        $month = $packed % 12 + 1;
        $year = intdiv($packed, 12) + 2000;

        return CarbonImmutable::create($year, $month, $day, $hour, $minute, $second, config('app.timezone'));
    }

    /**
     * Inverse of decodeTime(), useful for building test fixtures.
     */
    public static function encodeTime(CarbonImmutable $time): int
    {
        return ((($time->year % 100) * 12 * 31 + (($time->month - 1) * 31) + $time->day - 1)
            * (24 * 60 * 60)) + ($time->hour * 60 + $time->minute) * 60 + $time->second;
    }

    /**
     * Scramble the numeric comm key with the session id, as the device expects for CMD_AUTH.
     */
    public static function makeCommKey(int $key, int $sessionId, int $ticks = 50): string
    {
        $reversed = 0;

        for ($bit = 0; $bit < 32; $bit++) {
            $reversed = ($key & (1 << $bit)) ? ($reversed << 1 | 1) : ($reversed << 1);
        }

        $bytes = array_values(unpack('C4', pack('V', ($reversed + $sessionId) & 0xFFFFFFFF)));
        $bytes = [$bytes[0] ^ ord('Z'), $bytes[1] ^ ord('K'), $bytes[2] ^ ord('S'), $bytes[3] ^ ord('O')];
        $bytes = [$bytes[2], $bytes[3], $bytes[0], $bytes[1]];
        $tick = $ticks & 0xFF;

        return pack('C4', $bytes[0] ^ $tick, $bytes[1] ^ $tick, $tick, $bytes[3] ^ $tick);
    }

    public static function checksum(string $buffer): int
    {
        $sum = 0;
        $length = strlen($buffer);
        $index = 0;

        while ($length - $index > 1) {
            $sum += ord($buffer[$index]) | (ord($buffer[$index + 1]) << 8);

            if ($sum > self::USHRT_MAX) {
                $sum -= self::USHRT_MAX;
            }

            $index += 2;
        }

        if ($index < $length) {
            $sum += ord($buffer[$length - 1]);
        }

        while ($sum > self::USHRT_MAX) {
            $sum -= self::USHRT_MAX;
        }

        $sum = ~$sum;

        while ($sum < 0) {
            $sum += self::USHRT_MAX;
        }

        return $sum;
    }

    /**
     * Read a large data set (users, logs) through the device's buffered transfer.
     */
    private function readWithBuffer(int $command, int $fct = 0, int $ext = 0): string
    {
        $response = $this->sendCommand(
            self::CMD_PREPARE_BUFFER,
            pack('Cv', 1, $command).pack('VV', $fct, $ext),
        );

        if ($response['code'] === self::CMD_DATA) {
            return $response['data'];
        }

        if ($response['code'] !== self::CMD_ACK_OK || strlen($response['data']) < 5) {
            throw new ZktecoException("Device refused buffered read (code {$response['code']}).");
        }

        $size = unpack('V', substr($response['data'], 1, 4))[1];
        $data = '';

        for ($start = 0; $start < $size; $start += self::MAX_CHUNK) {
            $data .= $this->readChunk($start, min(self::MAX_CHUNK, $size - $start));
        }

        $this->sendCommand(self::CMD_FREE_DATA);

        return $data;
    }

    private function readChunk(int $start, int $size): string
    {
        $response = $this->sendCommand(self::CMD_READ_BUFFER, pack('VV', $start, $size));

        if ($response['code'] === self::CMD_DATA) {
            return $response['data'];
        }

        if ($response['code'] !== self::CMD_PREPARE_DATA) {
            throw new ZktecoException("Unexpected response while reading data (code {$response['code']}).");
        }

        $expected = unpack('V', substr($response['data'], 0, 4))[1];
        $data = '';

        while (strlen($data) < $expected) {
            $packet = $this->readPacket();

            if ($packet['code'] !== self::CMD_DATA) {
                throw new ZktecoException("Unexpected packet while reading data (code {$packet['code']}).");
            }

            $data .= $packet['data'];
        }

        $acknowledgement = $this->readPacket();

        if ($acknowledgement['code'] !== self::CMD_ACK_OK) {
            throw new ZktecoException('Device did not confirm the data transfer.');
        }

        return substr($data, 0, $expected);
    }

    /**
     * @return array{code: int, session: int, reply: int, data: string}
     */
    private function sendCommand(int $command, string $payload = ''): array
    {
        if ($this->socket === null) {
            throw new ZktecoException('Not connected to the device.');
        }

        $checksum = self::checksum(pack('v4', $command, 0, $this->sessionId, $this->replyId).$payload);

        $this->replyId++;

        if ($this->replyId >= self::USHRT_MAX) {
            $this->replyId -= self::USHRT_MAX;
        }

        $packet = pack('v4', $command, $checksum, $this->sessionId, $this->replyId).$payload;
        $frame = pack('vvV', 0x5050, 0x7D82, strlen($packet)).$packet;

        if (@fwrite($this->socket, $frame) !== strlen($frame)) {
            throw new ZktecoException('Failed to send command to the device.');
        }

        $response = $this->readPacket();
        $this->replyId = $response['reply'];

        return $response;
    }

    /**
     * Read one framed TCP packet from the device.
     *
     * @return array{code: int, session: int, reply: int, data: string}
     */
    private function readPacket(): array
    {
        $top = $this->readExactly(8);
        $frame = unpack('vmagic1/vmagic2/Vlength', $top);

        if ($frame['magic1'] !== 0x5050 || $frame['magic2'] !== 0x7D82 || $frame['length'] < 8) {
            throw new ZktecoException('Received an invalid packet from the device.');
        }

        $body = $this->readExactly($frame['length']);
        $header = unpack('vcode/vchecksum/vsession/vreply', substr($body, 0, 8));

        return [
            'code' => $header['code'],
            'session' => $header['session'],
            'reply' => $header['reply'],
            'data' => substr($body, 8),
        ];
    }

    private function readExactly(int $length): string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = @fread($this->socket, $length - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);

                throw new ZktecoException($meta['timed_out'] ? 'Timed out waiting for the device.' : 'Device closed the connection.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function closeSocket(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private static function cString(string $raw): string
    {
        $end = strpos($raw, "\0");

        return trim(mb_convert_encoding($end === false ? $raw : substr($raw, 0, $end), 'UTF-8', 'UTF-8'));
    }
}
