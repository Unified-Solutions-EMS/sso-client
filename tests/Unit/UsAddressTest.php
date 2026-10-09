<?php

namespace Unified\SsoClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Unified\SsoClient\MasterData\Locations\UsAddress;

class UsAddressTest extends TestCase
{
    public function test_it_parses_google_autocomplete_lines(): void
    {
        $this->assertSame(
            ['street' => '120 Lake Flower Ave', 'street2' => null, 'city_name' => 'Saranac Lake', 'state' => '36', 'zip' => '12983', 'country' => 'US'],
            UsAddress::parse('120 Lake Flower Ave, Saranac Lake, NY 12983, USA'),
        );
        $this->assertSame('Suite 2', UsAddress::parse('1 Main St, Suite 2, Burlington, vt 05401-1234')['street2'] ?? null);
        $this->assertSame('50', UsAddress::parse('1 Main St, Suite 2, Burlington, vt 05401-1234')['state'] ?? null);
    }

    public function test_it_gives_up_on_lines_without_a_state_and_zip(): void
    {
        $this->assertNull(UsAddress::parse('Behind the firehouse'));
        $this->assertNull(UsAddress::parse('1 Main St, Saranac Lake'));
        $this->assertNull(UsAddress::parse('1 Main St, Toronto, ON M5V 2T6'));
        $this->assertNull(UsAddress::parse(null));
    }

    public function test_it_formats_sso_addresses_and_phones(): void
    {
        $this->assertSame('120 Lake Flower Ave, Saranac Lake, NY 12983', UsAddress::format(['street' => '120 Lake Flower Ave', 'city_name' => 'Saranac Lake', 'state' => '36', 'zip' => '12983']));
        $this->assertNull(UsAddress::format(['state' => '36']));
        $this->assertNull(UsAddress::format(null));
        $this->assertSame('(518) 555-0142', UsAddress::formatPhone('+15185550142'));
        $this->assertSame('+441234567890', UsAddress::formatPhone('+441234567890'));
    }
}
