<?php

namespace Unified\SsoClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Unified\SsoClient\Security\CorsOrigins;

class CorsOriginsTest extends TestCase
{
    public function test_unset_setting_yields_sso_and_the_app_itself(): void
    {
        $this->assertSame(
            ['https://sso.example.test', 'https://app.example.test'],
            CorsOrigins::allowed(null, 'https://sso.example.test/', 'https://app.example.test'),
        );
    }

    public function test_star_is_never_returned(): void
    {
        $this->assertSame(
            ['https://sso.example.test'],
            CorsOrigins::allowed('*', 'https://sso.example.test'),
        );
        $this->assertSame([], CorsOrigins::allowed('*'));
    }

    public function test_configured_origins_are_normalized_deduplicated_and_added_to_the_defaults(): void
    {
        $this->assertSame(
            ['https://sso.example.test', 'https://app.example.test', 'https://other.example.test', 'http://localhost:5173'],
            CorsOrigins::allowed(
                ' https://other.example.test/path?q=1 , https://SSO.example.test, http://localhost:5173, ftp://nope.test, nonsense, ',
                'https://sso.example.test',
                'https://app.example.test',
                null,
            ),
        );
    }

    public function test_wildcard_subdomains_are_refused(): void
    {
        $this->assertNull(CorsOrigins::origin('https://*.example.test'));
    }
}
