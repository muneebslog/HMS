<?php

use App\Services\Zkteco\ZktecoClient;
use App\Services\Zkteco\ZktecoException;
use Carbon\CarbonImmutable;

function zkUser72(int $uid, string $userId, string $name, int $privilege = 0, int $card = 0): string
{
    return pack('vC', $uid, $privilege)
        .str_pad('', 8, "\0")
        .str_pad($name, 24, "\0")
        .pack('V', $card)
        ."\0".str_pad('1', 7, "\0")."\0"
        .str_pad($userId, 24, "\0");
}

function zkUser28(int $uid, int $userId, string $name, int $privilege = 0): string
{
    return pack('vC', $uid, $privilege)
        .str_pad('', 5, "\0")
        .str_pad($name, 8, "\0")
        .pack('V', 0)
        ."\0".chr(1).pack('v', 0)
        .pack('V', $userId);
}

function zkPunch40(int $uid, string $userId, string $time, int $verify = 1, int $state = 0): string
{
    return pack('v', $uid)
        .str_pad($userId, 24, "\0")
        .chr($verify)
        .pack('V', ZktecoClient::encodeTime(CarbonImmutable::parse($time)))
        .chr($state)
        .str_pad('', 8, "\0");
}

test('device timestamps round trip through encode and decode', function () {
    $time = CarbonImmutable::parse('2026-10-06 17:07:59');

    expect(ZktecoClient::decodeTime(ZktecoClient::encodeTime($time))->toDateTimeString())
        ->toBe('2026-10-06 17:07:59');
});

test('it parses 72 byte user records', function () {
    $data = zkUser72(1, '1', 'Muneeb', 14).zkUser72(2, '1002', 'Arooj', 0, 998877);

    $users = ZktecoClient::parseUsers($data, 72);

    expect($users)->toHaveCount(2)
        ->and($users[0])->toMatchArray(['uid' => 1, 'user_id' => '1', 'name' => 'Muneeb', 'privilege' => 14, 'card' => null])
        ->and($users[1])->toMatchArray(['uid' => 2, 'user_id' => '1002', 'name' => 'Arooj', 'card' => '998877']);
});

test('it parses 28 byte user records', function () {
    $users = ZktecoClient::parseUsers(zkUser28(7, 42, 'Aneela'), 28);

    expect($users[0])->toMatchArray(['uid' => 7, 'user_id' => '42', 'name' => 'Aneela']);
});

test('it parses 40 byte attendance records', function () {
    $data = zkPunch40(1, '4', '2026-10-06 08:01:02', 1, 0).zkPunch40(2, '4', '2026-10-06 16:05:32', 15, 1);

    $punches = ZktecoClient::parseAttendance($data, 40);

    expect($punches)->toHaveCount(2)
        ->and($punches[0]['user_id'])->toBe('4')
        ->and($punches[0]['punched_at']->toDateTimeString())->toBe('2026-10-06 08:01:02')
        ->and($punches[1]['verify_type'])->toBe(15)
        ->and($punches[1]['punch_state'])->toBe(1);
});

test('compact 8 byte attendance records resolve user ids from the uid map', function () {
    $record = pack('vC', 3, 1)
        .pack('V', ZktecoClient::encodeTime(CarbonImmutable::parse('2026-10-06 09:00:00')))
        .chr(0);

    $punches = ZktecoClient::parseAttendance($record, 8, [3 => '1003']);

    expect($punches[0]['user_id'])->toBe('1003')
        ->and($punches[0]['punched_at']->toDateTimeString())->toBe('2026-10-06 09:00:00');
});

test('it rejects unknown record sizes', function () {
    ZktecoClient::parseAttendance(str_repeat("\0", 12), 12);
})->throws(ZktecoException::class);

test('checksum matches the reference implementation', function () {
    // CMD_CONNECT header with session 0 and reply id 65534, as sent by pyzk.
    expect(ZktecoClient::checksum(pack('v4', 1000, 0, 0, 65534)))->toBe(64535);
});

test('comm key is scrambled with the session id', function () {
    expect(bin2hex(ZktecoClient::makeCommKey(0, 0)))->toBe('6b7d3268')
        ->and(ZktecoClient::makeCommKey(123456, 4321))->not->toBe(ZktecoClient::makeCommKey(123456, 4322));
});

test('connecting to an unreachable device throws a readable error', function () {
    $client = new ZktecoClient('127.0.0.1', 1, 0, 1.0);

    $client->connect();
})->throws(ZktecoException::class, 'Cannot reach device');
