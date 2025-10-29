<?php

namespace Tests;

use Examine\Sip2\Sip2;
use Examine\Sip2\Sip2Wrapper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\FakeTransport;

class Sip2WrapperTest extends TestCase
{
    private function crcFor(string $data): string
    {
        $sum = 0;
        $len = strlen($data);
        for ($n = 0; $n < $len; $n++) {
            $sum += ord($data[$n]);
        }
        $crc = ($sum & 0xFFFF) * -1;

        return substr(sprintf('%4X', $crc), -4, 4);
    }

    private function ds(): string
    {
        return '20250101    010101'; // 18 chars
    }

    private function makeResponse(string $bodyWithoutAZ): string
    {
        $withAz = $bodyWithoutAZ.'AZ';

        return $withAz.$this->crcFor($withAz)."\r";
    }

    private function setWrapperSip(Sip2Wrapper $wrapper, Sip2 $sip): void
    {
        $ref = new ReflectionClass($wrapper);
        $prop = $ref->getProperty('_sip2');
        $prop->setValue($wrapper, $sip);
    }

    public function test_login_self_check_and_patron_session_flow(): void
    {
        $fake = new FakeTransport;
        $sip = new Sip2($fake);
        $sip->withCrc = true;
        $sip->hostname = 'localhost';
        $sip->port = 6002;

        // Queue login response: 94 + Ok=1
        $fake->queueResponse($this->makeResponse('941'));

        // Queue ACS status response: 98 + flags + timeout + retries + date + protocol
        $acs = '98'
            .'Y' // Online
            .'Y' // Checkin
            .'Y' // Checkout
            .'Y' // Renewal
            .'Y' // PatronUpdate
            .'N' // Offline
            .'600' // Timeout
            .'003' // Retries
            .$this->ds() // Tx date (18)
            .'2.00'; // Protocol (4)
        $fake->queueResponse($this->makeResponse($acs));

        // Queue Patron Status response: 24 + 14 + 3 + 18
        $patronFixed = '24'.str_repeat('0', 14).'001'.$this->ds();
        $patronVars = 'BLY|CQY|BV2.50|AFBlocked|';
        $fake->queueResponse($this->makeResponse($patronFixed.$patronVars));

        // Queue Patron Info (charged) response: 64 ... with two AU items
        $piFixed = '64'.str_repeat('0', 14).'001'.$this->ds()
            .'0000'.'0000'.'0002'.'0000'.'0000'.'0000';
        $piVars = 'AUitem1|AUitem2|';
        $fake->queueResponse($this->makeResponse($piFixed.$piVars));

        // Queue End Session response: 36 + Y + date
        $end = '36Y'.$this->ds();
        $fake->queueResponse($this->makeResponse($end));

        // Build wrapper (no auto-connect) and inject our Sip2
        $wrapper = new Sip2Wrapper(['hostname' => 'host', 'port' => 1234], false);
        $this->setWrapperSip($wrapper, $sip);

        // Connect
        $this->assertTrue($wrapper->connect());
        $this->assertTrue($wrapper->isConnected());

        // Login triggers self-check
        $this->assertInstanceOf(Sip2Wrapper::class, $wrapper->login('user', 'pass', true));
        $this->assertNotNull($wrapper->getAcsStatus());

        // Start patron session
        $this->assertTrue($wrapper->startPatronSession('p1', 'pw1'));
        $this->assertSame(2.50, $wrapper->getPatronFinesTotal());
        $this->assertSame(['Blocked'], $wrapper->getPatronScreenMessages());
        $charged = $wrapper->getPatronChargedItems();
        $this->assertSame(['item1', 'item2'], $charged);

        // End patron session
        $this->assertInstanceOf(Sip2Wrapper::class, $wrapper->endPatronSession());
        $this->expectException(\Exception::class);
        $wrapper->getPatronStatus();
    }
}
