<?php

declare(strict_types=1);

namespace Patuju\Tests\Integration;

use Patuju\Tests\Support\TestCase;

final class AuthTest extends TestCase
{
    public function testEndpointsRequireAuthentication(): void
    {
        $response = $this->get('/ajax/productos.php');

        $this->assertSame(401, $response->status);
        $data = $response->json();
        $this->assertFalse($data['success']);
        $this->assertSame('Sesión expirada', $data['error']);
    }

    public function testLoginRejectsNonPost(): void
    {
        $response = $this->get('/ajax/login.php');

        $this->assertSame(405, $response->status);
        $this->assertSame('Método no permitido', $response->json()['error']);
    }

    public function testLoginRequiresCredentials(): void
    {
        $response = $this->post('/ajax/login.php', []);

        $this->assertSame(400, $response->status);
        $this->assertSame('Credenciales requeridas', $response->json()['error']);
    }

    public function testLoginWithWrongPasswordReturnsGenericError(): void
    {
        $response = $this->post('/ajax/login.php', [
            'username' => 'admin',
            'password' => 'incorrecta',
        ]);

        $this->assertSame(401, $response->status);
        $data = $response->json();
        $this->assertFalse($data['success']);
        $this->assertSame('Credenciales incorrectas', $data['error']);
    }

    public function testLoginUnknownUserReturnsGenericError(): void
    {
        $response = $this->post('/ajax/login.php', [
            'username' => 'usuario_inexistente',
            'password' => 'cualquiera',
        ]);

        $this->assertSame(401, $response->status);
        $this->assertSame('Credenciales incorrectas', $response->json()['error']);
    }

    public function testLoginSucceedsForEachRoleAndRedirectsCorrectly(): void
    {
        $expected = [
            'admin' => 'admin.php',
            'encargado_encargado_central' => 'encargado.php',
            'caja_caja_central' => 'index.php',
        ];

        foreach ($expected as $username => $redirect) {
            $data = $this->loginAs($username);
            $this->assertSame($redirect, $data['redirect'], $username);
        }
    }

    public function testSessionIdChangesAfterLogin(): void
    {
        $this->get('/login.php', 'admin');
        $before = $this->sessionId('admin');
        $this->assertNotNull($before);

        $this->loginAs('admin');

        $after = $this->sessionId('admin');
        $this->assertNotNull($after);
        $this->assertNotSame($before, $after);
    }

    public function testRateLimitBlocksAfterFiveFailures(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->post('/ajax/login.php', [
                'username' => 'caja_caja_central',
                'password' => 'mala',
            ]);
            $this->assertSame(401, $response->status, "Intento fallido {$i}");
        }

        $blocked = $this->post('/ajax/login.php', [
            'username' => 'caja_caja_central',
            'password' => 'patuju2024',
        ]);

        $this->assertSame(429, $blocked->status);
        $this->assertStringContainsString('Demasiados intentos', $blocked->json()['error']);
    }

    public function testSuccessfulLoginClearsFailedAttempts(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->post('/ajax/login.php', [
                'username' => 'caja_caja_central',
                'password' => 'mala',
            ]);
        }

        $this->loginAs('caja_caja_central');

        $this->assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM login_intentos WHERE username = ?',
            ['caja_caja_central']
        ));

        $response = $this->post('/ajax/login.php', [
            'username' => 'caja_caja_central',
            'password' => 'mala',
        ]);
        $this->assertSame(401, $response->status);
    }

    public function testLogoutInvalidatesSession(): void
    {
        $this->loginAs('caja_caja_central');
        $this->logout('caja_caja_central');

        $response = $this->get('/ajax/productos.php', 'caja_caja_central');
        $this->assertSame(401, $response->status);
    }

    public function testSessionCookieIsHttpOnlyAndSameSiteStrict(): void
    {
        $response = $this->post('/ajax/login.php', [
            'username' => 'caja_caja_central',
            'password' => 'patuju2024',
        ], 'caja_caja_central');

        $setCookie = $response->header('set-cookie');
        $this->assertNotNull($setCookie);
        $this->assertStringContainsStringIgnoringCase('HttpOnly', $setCookie);
        $this->assertStringContainsStringIgnoringCase('SameSite=Strict', $setCookie);
    }
}
