<?php

declare(strict_types=1);

namespace Patuju\Tests\Integration;

use Patuju\Tests\Support\TestCase;

final class PagesSmokeTest extends TestCase
{
    public function testLoginPageLoads(): void
    {
        $response = $this->get('/login.php');

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Login', $response->body);
    }

    public function testLandingPageLoads(): void
    {
        $response = $this->get('/landing.php');

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Patuj', $response->body);
    }

    public function testLoginRedirectsAuthenticatedUsersByRole(): void
    {
        $this->loginAs('admin');
        $admin = $this->get('/login.php', 'admin');
        $this->assertSame(302, $admin->status);
        $this->assertStringContainsString('admin.php', (string) $admin->header('location'));

        $this->loginAs('encargado_encargado_central');
        $encargado = $this->get('/login.php', 'encargado_encargado_central');
        $this->assertSame(302, $encargado->status);
        $this->assertStringContainsString('encargado.php', (string) $encargado->header('location'));

        $this->loginAs('caja_caja_central');
        $caja = $this->get('/login.php', 'caja_caja_central');
        $this->assertSame(302, $caja->status);
        $this->assertStringContainsString('index.php', (string) $caja->header('location'));
    }

    public function testCajaViewLoadsForCashier(): void
    {
        $this->loginAs('caja_caja_central');

        $response = $this->get('/index.php', 'caja_caja_central');

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('id="catalogo-productos"', $response->body);
        $this->assertStringContainsString('data-sucursal-id="1"', $response->body);
    }

    public function testEncargadoViewLoadsForManager(): void
    {
        $this->loginAs('encargado_encargado_central');

        $response = $this->get('/encargado.php', 'encargado_encargado_central');

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('data-usuario-rol="encargado"', $response->body);
    }

    public function testAdminViewLoadsForAdmin(): void
    {
        $this->loginAs('admin');

        $response = $this->get('/admin.php', 'admin');

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Administraci', $response->body);
    }

    public function testLogoutRedirectsToLandingAndClosesSession(): void
    {
        $this->loginAs('caja_caja_central');

        $logout = $this->get('/logout.php', 'caja_caja_central');
        $this->assertSame(302, $logout->status);
        $this->assertStringContainsString('landing.php', (string) $logout->header('location'));

        $this->assertSame(302, $this->get('/index.php', 'caja_caja_central')->status);
    }

    public function testStaticAssetsAreServed(): void
    {
        foreach (['/assets/css/styles.css', '/assets/js/app.js', '/assets/js/admin.js', '/assets/js/encargado.js'] as $asset) {
            $response = $this->get($asset);
            $this->assertSame(200, $response->status, $asset);
            $this->assertNotSame('', trim($response->body), $asset);
        }
    }
}
