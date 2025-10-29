<?php

namespace Tests;

use Examine\Sip2\Sip2;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeTransport;

class Sip2MessageTest extends TestCase
{
    private function makeSip2(bool $withSeq = false, bool $withCrc = false): Sip2
    {
        $sip = new Sip2(new FakeTransport);
        $sip->withSeq = $withSeq;
        $sip->withCrc = $withCrc;
        $sip->fldTerminator = '|';
        $sip->msgTerminator = "\r";
        $sip->AO = 'LIB';
        $sip->patron = 'patron123';
        $sip->AC = 'terminal';
        $sip->patronpwd = 'secret';
        // sensible defaults for connect
        $sip->hostname = 'localhost';
        $sip->port = 6002;

        return $sip;
    }

    public function test_msg_login_contains_credentials_and_terminates(): void
    {
        $sip = $this->makeSip2();
        $msg = $sip->msgLogin('user', 'pass');
        $this->assertStringStartsWith('93', $msg);
        $this->assertStringContainsString('CNuser|', $msg);
        $this->assertStringContainsString('COpass|', $msg);
        $this->assertStringEndsWith("\r", $msg);
    }

    public function test_msg_sc_status_validates_status_and_builds_message(): void
    {
        $sip = $this->makeSip2();
        $msg = $sip->msgSCStatus(0, 80, 2);
        $this->assertNotFalse($msg);
        $this->assertStringStartsWith('99', $msg);
        // status at pos 2
        $this->assertSame('0', substr($msg, 2, 1));
        // width at pos 3-5 (right-aligned, space-padded)
        $this->assertSame(' 80', substr($msg, 3, 3));
        // protocol at pos 6-9
        $this->assertSame('2.00', substr($msg, 6, 4));
    }

    public function test_msg_patron_information_includes_keys(): void
    {
        $sip = $this->makeSip2();
        $msg = $sip->msgPatronInformation('charged');
        $this->assertStringStartsWith('63', $msg);
        $this->assertStringContainsString('AOLIB|', $msg);
        $this->assertStringContainsString('AApatron123|', $msg);
        $this->assertStringContainsString('ACterminal|', $msg);
        $this->assertStringContainsString('ADsecret|', $msg);
    }

    public function test_return_message_adds_seq_and_crc_when_enabled(): void
    {
        $sip = $this->makeSip2(true, true);
        // Build a message via public API that should include AY/AZ when flags are enabled
        $msg = $sip->msgSCStatus(0, 80, 2);
        $this->assertMatchesRegularExpression('/AY\dAZ[0-9A-F]{4}\r$/', $msg);
    }

    public function test_get_message_reads_until_cr(): void
    {
        $fake = new FakeTransport([
            // Minimal response with CR; CRC disabled so any payload is accepted
            '30YUU'.str_repeat('0', 18)."\r",
        ]);
        $sip = new Sip2($fake);
        $sip->withCrc = false;
        $sip->hostname = 'localhost';
        $sip->port = 6002;
        $this->assertTrue($sip->connect());
        $resp = $sip->get_message('dummy');
        $this->assertSame('30YUU'.str_repeat('0', 18)."\r", $resp);
        $this->assertNotEmpty($fake->written);
    }
}
