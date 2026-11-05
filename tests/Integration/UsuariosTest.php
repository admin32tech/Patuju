<?php

declare(strict_types=1);

namespace Patuju\Tests\Integration;

use Patuju\Tests\Support\TestCase;

final class UsuariosTest extends TestCase
{
    public function testRequiresAdminRole(): void
    {
        $this->assertSame(401, $this->get('/ajax/usuarios.php')->status);

        $this->loginAs('caja_caja_central');
        $this->assertSame(403, $this->get('/ajax/usuarios.php', 'caja_caja_central')->status);

        $this->loginAs('encargado_encargado_central');
        $this->assertSame(403, $this->get('/ajax/usuarios.php', 'encargado_encargado_central')->status);
    }

    public function testListReturnsUsersBranchesAndKpis(): void
    {
        $this->loginAs('admin');

        $response = $this->get('/ajax/usuarios.php', 'admin');

        $this->assertSame(200, $response->status, $response->body);
        $data = $response->json();
        $this->assertCount(19, $data['usuarios']);
        $this->assertCount(16, $data['sucursales']);
        $this->assertSame(19, $data['kpis']['total']);
        $this->assertSame(19, $data['kpis']['activos']);
        $this->assertSame(1, $data['kpis']['admins']);
        $this->assertSame(3, $data['kpis']['encargados']);
        $this->assertSame(15, $data['kpis']['cajeros']);

        $central = null;
        foreach ($data['usuarios'] as $usuario) {
            if ($usuario['username'] === 'encargado_encargado_central') {
                $central = $usuario;
                break;
            }
        }
        $this->assertNotNull($central);
        $this->assertSame([1, 2, 3, 4, 5], $central['sucursales_asignadas_ids']);
    }

    public function testCreateCajero(): void
    {
        $this->loginAs('admin');

        $response = $this->post('/ajax/usuarios.php', [
            'accion' => 'crear',
            'username' => 'nuevo_cajero',
            'password' => 'secreto123',
            'nombre_display' => 'Nuevo Cajero',
            'rol' => 'caja',
            'sucursal_id' => 2,
        ], 'admin');

        $this->assertSame(200, $response->status, $response->body);
        $data = $response->json();
        $this->assertTrue($data['success']);

        $usuario = $this->row('SELECT * FROM usuarios WHERE id = ?', [$data['usuario_id']]);
        $this->assertNotNull($usuario);
        $this->assertSame('nuevo_cajero', $usuario['username']);
        $this->assertSame('caja', $usuario['rol']);
        $this->assertSame(2, (int) $usuario['sucursal_id']);
        $this->assertSame(1, (int) $usuario['activo']);
        $this->assertTrue(password_verify('secreto123', $usuario['password_hash']));
    }

    public function testCreateRequiresValidData(): void
    {
        $this->loginAs('admin');

        $casos = [
            ['username' => 'ab', 'password' => 'secreto123', 'nombre_display' => 'Nombre', 'rol' => 'caja', 'sucursal_id' => 1],
            ['username' => 'usuario malo', 'password' => 'secreto123', 'nombre_display' => 'Nombre', 'rol' => 'caja', 'sucursal_id' => 1],
            ['username' => 'usuario_ok', 'password' => '123', 'nombre_display' => 'Nombre', 'rol' => 'caja', 'sucursal_id' => 1],
            ['username' => 'usuario_ok', 'password' => 'secreto123', 'nombre_display' => 'X', 'rol' => 'caja', 'sucursal_id' => 1],
            ['username' => 'usuario_ok', 'password' => 'secreto123', 'nombre_display' => 'Nombre', 'rol' => 'superadmin', 'sucursal_id' => 1],
            ['username' => 'usuario_ok', 'password' => 'secreto123', 'nombre_display' => 'Nombre', 'rol' => 'caja'],
        ];

        foreach ($casos as $caso) {
            $response = $this->post('/ajax/usuarios.php', ['accion' => 'crear'] + $caso, 'admin');
            $this->assertSame(400, $response->status, $response->body);
        }

        $this->assertSame(19, (int) $this->scalar('SELECT COUNT(*) FROM usuarios'));
    }

    public function testCreateDuplicateUsernameReturnsConflict(): void
    {
        $this->loginAs('admin');

        $response = $this->post('/ajax/usuarios.php', [
            'accion' => 'crear',
            'username' => 'admin',
            'password' => 'secreto123',
            'nombre_display' => 'Duplicado',
            'rol' => 'admin',
        ], 'admin');

        $this->assertSame(409, $response->status);
    }

    public function testCreateRejectsUnknownBranch(): void
    {
        $this->loginAs('admin');

        $response = $this->post('/ajax/usuarios.php', [
            'accion' => 'crear',
            'username' => 'usuario_sucursal_mala',
            'password' => 'secreto123',
            'nombre_display' => 'Sucursal Mala',
            'rol' => 'caja',
            'sucursal_id' => 999,
        ], 'admin');

        $this->assertSame(400, $response->status);
        $this->assertSame(19, (int) $this->scalar('SELECT COUNT(*) FROM usuarios'));
    }

    public function testCreateEncargadoWithMultipleBranches(): void
    {
        $this->loginAs('admin');

        $response = $this->post('/ajax/usuarios.php', [
            'accion' => 'crear',
            'username' => 'nuevo_encargado',
            'password' => 'secreto123',
            'nombre_display' => 'Nuevo Encargado',
            'rol' => 'encargado',
            'sucursal_id' => 1,
            'sucursales_asignadas' => [1, 2, 3],
        ], 'admin');

        $this->assertSame(200, $response->status, $response->body);
        $id = (int) $response->json()['usuario_id'];

        $asignadas = $this->rows(
            'SELECT sucursal_id FROM usuario_sucursales WHERE usuario_id = ? ORDER BY sucursal_id',
            [$id]
        );
        $this->assertSame([1, 2, 3], array_map(static fn (array $r): int => (int) $r['sucursal_id'], $asignadas));
    }

    public function testCreateAdminDefaultsToInternalBranch(): void
    {
        $this->loginAs('admin');

        $response = $this->post('/ajax/usuarios.php', [
            'accion' => 'crear',
            'username' => 'nuevo_admin',
            'password' => 'secreto123',
            'nombre_display' => 'Nuevo Admin',
            'rol' => 'admin',
        ], 'admin');

        $this->assertSame(200, $response->status, $response->body);
        $id = (int) $response->json()['usuario_id'];
        $this->assertSame(16, (int) $this->scalar('SELECT sucursal_id FROM usuarios WHERE id = ?', [$id]));
    }

    public function testEditUser(): void
    {
        $this->loginAs('admin');

        $response = $this->post('/ajax/usuarios.php', [
            'accion' => 'editar',
            'id' => 5,
            'nombre_display' => 'Cajero Editado',
            'rol' => 'caja',
            'sucursal_id' => 2,
            'activo' => 1,
        ], 'admin');

        $this->assertSame(200, $response->status, $response->body);
        $usuario = $this->row('SELECT * FROM usuarios WHERE id = 5');
        $this->assertNotNull($usuario);
        $this->assertSame('Cajero Editado', $usuario['nombre_display']);
        $this->assertSame(2, (int) $usuario['sucursal_id']);
    }

    public function testEditEncargadoToCajaClearsAssignments(): void
    {
        $this->loginAs('admin');

        $response = $this->post('/ajax/usuarios.php', [
            'accion' => 'editar',
            'id' => 2,
            'nombre_display' => 'Ex Encargado',
            'rol' => 'caja',
            'sucursal_id' => 1,
            'activo' => 1,
        ], 'admin');

        $this->assertSame(200, $response->status, $response->body);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM usuario_sucursales WHERE usuario_id = 2'));
    }

    public function testEditProtectsOwnAdminAccount(): void
    {
        $this->loginAs('admin');

        $desactivar = $this->post('/ajax/usuarios.php', [
            'accion' => 'editar',
            'id' => 1,
            'nombre_display' => 'Administrador',
            'rol' => 'admin',
            'sucursal_id' => 16,
            'activo' => 0,
        ], 'admin');
        $this->assertSame(400, $desactivar->status);

        // Valores raros de 'activo' (2, -1, 'x') cuentan como desactivar: misma guardia, sin dejar al admin fuera
        foreach ([2, -1, 'x'] as $valor) {
            $raro = $this->post('/ajax/usuarios.php', [
                'accion' => 'editar',
                'id' => 1,
                'nombre_display' => 'Administrador',
                'rol' => 'admin',
                'sucursal_id' => 16,
                'activo' => $valor,
            ], 'admin');
            $this->assertSame(400, $raro->status, var_export($valor, true) . ' ' . $raro->body);
        }
        $this->assertSame(1, (int) $this->scalar('SELECT activo FROM usuarios WHERE id = 1'));
        $this->assertSame(200, $this->get('/ajax/usuarios.php?accion=listar', 'admin')->status);

        $degradar = $this->post('/ajax/usuarios.php', [
            'accion' => 'editar',
            'id' => 1,
            'nombre_display' => 'Administrador',
            'rol' => 'caja',
            'sucursal_id' => 16,
            'activo' => 1,
        ], 'admin');
        $this->assertSame(400, $degradar->status);

        $renombrar = $this->post('/ajax/usuarios.php', [
            'accion' => 'editar',
            'id' => 1,
            'nombre_display' => 'Administrador General',
            'rol' => 'admin',
            'sucursal_id' => 16,
            'activo' => 1,
        ], 'admin');
        $this->assertSame(200, $renombrar->status, $renombrar->body);
        $this->assertSame('Administrador General', $this->scalar('SELECT nombre_display FROM usuarios WHERE id = 1'));
    }

    public function testEditRejectsUnknownUserAndBranch(): void
    {
        $this->loginAs('admin');

        $desconocido = $this->post('/ajax/usuarios.php', [
            'accion' => 'editar',
            'id' => 9999,
            'nombre_display' => 'Fantasma',
            'rol' => 'caja',
            'sucursal_id' => 1,
            'activo' => 1,
        ], 'admin');
        $this->assertSame(404, $desconocido->status);

        $sucursalMala = $this->post('/ajax/usuarios.php', [
            'accion' => 'editar',
            'id' => 5,
            'nombre_display' => 'Cajero',
            'rol' => 'caja',
            'sucursal_id' => 999,
            'activo' => 1,
        ], 'admin');
        $this->assertSame(400, $sucursalMala->status);
    }

    public function testAssignBranches(): void
    {
        $this->loginAs('admin');

        $response = $this->post('/ajax/usuarios.php', [
            'accion' => 'asignar_sucursales',
            'usuario_id' => 2,
            'sucursales_ids' => [1, 2],
        ], 'admin');

        $this->assertSame(200, $response->status, $response->body);
        $this->assertSame(2, $response->json()['total_asignadas']);
        $this->assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM usuario_sucursales WHERE usuario_id = 2'));

        $reemplazo = $this->post('/ajax/usuarios.php', [
            'accion' => 'asignar_sucursales',
            'usuario_id' => 2,
            'sucursales_ids' => [3, 4, 5],
        ], 'admin');

        $this->assertSame(200, $reemplazo->status, $reemplazo->body);
        // C3: la principal (1) se conserva aunque no venga en la lista
        $this->assertSame(4, $reemplazo->json()['total_asignadas']);
        $ids = $this->rows('SELECT sucursal_id FROM usuario_sucursales WHERE usuario_id = 2 ORDER BY sucursal_id');
        $this->assertSame([1, 3, 4, 5], array_map(static fn (array $r): int => (int) $r['sucursal_id'], $ids));
    }

    public function testAssignBranchesRejectsNonEncargado(): void
    {
        $this->loginAs('admin');

        $response = $this->post('/ajax/usuarios.php', [
            'accion' => 'asignar_sucursales',
            'usuario_id' => 5,
            'sucursales_ids' => [1],
        ], 'admin');

        $this->assertSame(400, $response->status);
    }

    public function testAssignBranchesRejectsUnknownUserAndBranch(): void
    {
        $this->loginAs('admin');

        $desconocido = $this->post('/ajax/usuarios.php', [
            'accion' => 'asignar_sucursales',
            'usuario_id' => 9999,
            'sucursales_ids' => [1],
        ], 'admin');
        $this->assertSame(404, $desconocido->status);

        $sucursalMala = $this->post('/ajax/usuarios.php', [
            'accion' => 'asignar_sucursales',
            'usuario_id' => 2,
            'sucursales_ids' => [1, 999],
        ], 'admin');
        $this->assertSame(400, $sucursalMala->status);
        $this->assertSame(5, (int) $this->scalar('SELECT COUNT(*) FROM usuario_sucursales WHERE usuario_id = 2'));
    }

    public function testResetPassword(): void
    {
        $this->loginAs('admin');

        $response = $this->post('/ajax/usuarios.php', [
            'accion' => 'reset_password',
            'usuario_id' => 5,
            'password' => 'nuevaClave1',
        ], 'admin');

        $this->assertSame(200, $response->status, $response->body);
        $hash = $this->scalar('SELECT password_hash FROM usuarios WHERE id = 5');
        $this->assertTrue(password_verify('nuevaClave1', $hash));

        $login = $this->loginAs('caja_caja_central', 'nuevaClave1');
        $this->assertTrue($login['success']);
    }

    public function testResetPasswordValidatesInput(): void
    {
        $this->loginAs('admin');

        $corta = $this->post('/ajax/usuarios.php', [
            'accion' => 'reset_password',
            'usuario_id' => 5,
            'password' => '123',
        ], 'admin');
        $this->assertSame(400, $corta->status);

        $desconocido = $this->post('/ajax/usuarios.php', [
            'accion' => 'reset_password',
            'usuario_id' => 9999,
            'password' => 'nuevaClave1',
        ], 'admin');
        $this->assertSame(404, $desconocido->status);
    }

    public function testToggleActivo(): void
    {
        $this->loginAs('admin');

        $desactivar = $this->post('/ajax/usuarios.php', [
            'accion' => 'toggle_activo',
            'usuario_id' => 6,
        ], 'admin');

        $this->assertSame(200, $desactivar->status, $desactivar->body);
        $this->assertSame(0, $desactivar->json()['nuevo_estado']);
        $this->assertSame(0, (int) $this->scalar('SELECT activo FROM usuarios WHERE id = 6'));

        $activar = $this->post('/ajax/usuarios.php', [
            'accion' => 'toggle_activo',
            'usuario_id' => 6,
        ], 'admin');

        $this->assertSame(200, $activar->status, $activar->body);
        $this->assertSame(1, $activar->json()['nuevo_estado']);
    }

    public function testToggleRejectsSelfAndUnknownUser(): void
    {
        $this->loginAs('admin');

        $propio = $this->post('/ajax/usuarios.php', [
            'accion' => 'toggle_activo',
            'usuario_id' => 1,
        ], 'admin');
        $this->assertSame(400, $propio->status);

        $desconocido = $this->post('/ajax/usuarios.php', [
            'accion' => 'toggle_activo',
            'usuario_id' => 9999,
        ], 'admin');
        $this->assertSame(404, $desconocido->status);
    }

    /**
     * C1: crear acepta 'activo' (0|1); un usuario creado inactivo no puede iniciar sesión.
     */
    public function testCreateRespectsActivoFlag(): void
    {
        $this->loginAs('admin');

        $response = $this->post('/ajax/usuarios.php', [
            'accion' => 'crear',
            'username' => 'cajero_inactivo',
            'password' => 'secreto123',
            'nombre_display' => 'Cajero Inactivo',
            'rol' => 'caja',
            'sucursal_id' => 2,
            'activo' => 0,
        ], 'admin');

        $this->assertSame(200, $response->status, $response->body);
        $id = (int) $response->json()['usuario_id'];
        $this->assertSame(0, (int) $this->scalar('SELECT activo FROM usuarios WHERE id = ?', [$id]));

        $login = $this->post('/ajax/login.php', [
            'username' => 'cajero_inactivo',
            'password' => 'secreto123',
        ]);
        $this->assertSame(401, $login->status, $login->body);
    }

    /**
     * C2: crear ya no toma sucursales_asignadas[0] como principal.
     */
    public function testCreateEncargadoRequiresPrincipalBranch(): void
    {
        $this->loginAs('admin');

        $response = $this->post('/ajax/usuarios.php', [
            'accion' => 'crear',
            'username' => 'encargado_sin_principal',
            'password' => 'secreto123',
            'nombre_display' => 'Encargado Sin Principal',
            'rol' => 'encargado',
            'sucursales_asignadas' => [1, 2],
        ], 'admin');

        $this->assertSame(400, $response->status, $response->body);
        $this->assertSame('Debes asignar una sucursal principal para este rol', $response->json()['error']);
        $this->assertSame(19, (int) $this->scalar('SELECT COUNT(*) FROM usuarios'));
    }

    /**
     * SEC-3 / C2: editar caja o encargado sin sucursal principal es 400 y no toca la BD.
     */
    public function testEditRequiresPrincipalBranchForNonAdmin(): void
    {
        $this->loginAs('admin');

        $caja = $this->post('/ajax/usuarios.php', [
            'accion' => 'editar',
            'id' => 5,
            'nombre_display' => 'Caja Sin Sucursal',
            'rol' => 'caja',
            'activo' => 1,
        ], 'admin');
        $this->assertSame(400, $caja->status, $caja->body);
        $this->assertSame('Debes asignar una sucursal principal para este rol', $caja->json()['error']);

        $encargado = $this->post('/ajax/usuarios.php', [
            'accion' => 'editar',
            'id' => 2,
            'nombre_display' => 'Encargado Sin Sucursal',
            'rol' => 'encargado',
            'sucursal_id' => 0,
            'activo' => 1,
        ], 'admin');
        $this->assertSame(400, $encargado->status, $encargado->body);

        $this->assertSame(1, (int) $this->scalar('SELECT sucursal_id FROM usuarios WHERE id = 5'));
        $this->assertSame('Caja Central', $this->scalar('SELECT nombre_display FROM usuarios WHERE id = 5'));
        $this->assertSame(1, (int) $this->scalar('SELECT sucursal_id FROM usuarios WHERE id = 2'));
        $this->assertSame(5, (int) $this->scalar('SELECT COUNT(*) FROM usuario_sucursales WHERE usuario_id = 2'));
    }

    /**
     * SEC-3 / SEC-5 / C2: la sucursal interna (16, SUC-000) solo se asigna a administradores.
     */
    public function testInternalBranchIsReservedForAdmins(): void
    {
        $this->loginAs('admin');

        $rechazos = [
            'crear caja' => [
                'accion' => 'crear', 'username' => 'caja_interna', 'password' => 'secreto123',
                'nombre_display' => 'Caja Interna', 'rol' => 'caja', 'sucursal_id' => 16,
            ],
            'crear encargado' => [
                'accion' => 'crear', 'username' => 'encargado_interno', 'password' => 'secreto123',
                'nombre_display' => 'Encargado Interno', 'rol' => 'encargado', 'sucursal_id' => 16,
            ],
            'crear encargado con asignada interna' => [
                'accion' => 'crear', 'username' => 'encargado_mixto', 'password' => 'secreto123',
                'nombre_display' => 'Encargado Mixto', 'rol' => 'encargado', 'sucursal_id' => 1,
                'sucursales_asignadas' => [1, 16],
            ],
            'editar caja' => [
                'accion' => 'editar', 'id' => 5, 'nombre_display' => 'Caja Central',
                'rol' => 'caja', 'sucursal_id' => 16, 'activo' => 1,
            ],
            'editar encargado' => [
                'accion' => 'editar', 'id' => 2, 'nombre_display' => 'Encargado Central',
                'rol' => 'encargado', 'sucursal_id' => 16, 'activo' => 1,
            ],
            'editar encargado con asignada interna' => [
                'accion' => 'editar', 'id' => 2, 'nombre_display' => 'Encargado Central',
                'rol' => 'encargado', 'sucursal_id' => 1, 'activo' => 1,
                'sucursales_asignadas' => [1, 16],
            ],
            'asignar_sucursales' => [
                'accion' => 'asignar_sucursales', 'usuario_id' => 2, 'sucursales_ids' => [1, 16],
            ],
        ];

        foreach ($rechazos as $caso => $body) {
            $response = $this->post('/ajax/usuarios.php', $body, 'admin');
            $this->assertSame(400, $response->status, "{$caso}: {$response->body}");
        }

        $this->assertSame(19, (int) $this->scalar('SELECT COUNT(*) FROM usuarios'));
        $this->assertSame(1, (int) $this->scalar('SELECT sucursal_id FROM usuarios WHERE id = 5'));
        $this->assertSame(1, (int) $this->scalar('SELECT sucursal_id FROM usuarios WHERE id = 2'));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM usuario_sucursales WHERE sucursal_id = 16'));
        $this->assertSame(5, (int) $this->scalar('SELECT COUNT(*) FROM usuario_sucursales WHERE usuario_id = 2'));

        // Para admin la interna sigue permitida
        $admin = $this->post('/ajax/usuarios.php', [
            'accion' => 'crear',
            'username' => 'admin_interno',
            'password' => 'secreto123',
            'nombre_display' => 'Admin Interno',
            'rol' => 'admin',
            'sucursal_id' => 16,
        ], 'admin');
        $this->assertSame(200, $admin->status, $admin->body);
        $this->assertSame(16, (int) $this->scalar('SELECT sucursal_id FROM usuarios WHERE id = ?', [$admin->json()['usuario_id']]));
    }

    /**
     * C3: asignar_sucursales sin la principal la conserva en usuario_sucursales y el acceso se mantiene.
     */
    public function testAssignBranchesAlwaysKeepsPrincipal(): void
    {
        $this->loginAs('encargado_encargado_central');
        $this->loginAs('admin');

        $response = $this->post('/ajax/usuarios.php', [
            'accion' => 'asignar_sucursales',
            'usuario_id' => 2,
            'sucursales_ids' => [3, 4, 5],
        ], 'admin');

        $this->assertSame(200, $response->status, $response->body);
        $data = $response->json();
        $this->assertSame(4, $data['total_asignadas']);
        $this->assertStringContainsString('Se asignaron 4', $data['message']);
        $this->assertSame([1, 3, 4, 5], $this->asignadas(2));

        // Con la sesión ya abierta: conserva la principal y las nuevas, pierde la 2
        $this->assertSame(200, $this->get('/ajax/stock.php?sucursal_id=1', 'encargado_encargado_central')->status);
        $this->assertSame(200, $this->get('/ajax/stock.php?sucursal_id=3', 'encargado_encargado_central')->status);
        $this->assertSame(403, $this->get('/ajax/stock.php?sucursal_id=2', 'encargado_encargado_central')->status);

        // Lista vacía: queda solo la principal
        $vacia = $this->post('/ajax/usuarios.php', [
            'accion' => 'asignar_sucursales',
            'usuario_id' => 2,
            'sucursales_ids' => [],
        ], 'admin');
        $this->assertSame(200, $vacia->status, $vacia->body);
        $this->assertSame(1, $vacia->json()['total_asignadas']);
        $this->assertSame([1], $this->asignadas(2));
    }

    /**
     * SEC-4 / C4: cambiar la principal de un encargado sin enviar sucursales_asignadas
     * quita la anterior y vincula la nueva.
     */
    public function testChangingEncargadoPrincipalRevokesPreviousBranch(): void
    {
        $this->loginAs('encargado_encargado_central');
        $this->assertSame(200, $this->get('/ajax/stock.php?sucursal_id=1', 'encargado_encargado_central')->status);

        $this->loginAs('admin');
        $editar = [
            'accion' => 'editar',
            'id' => 2,
            'nombre_display' => 'Encargado Central',
            'rol' => 'encargado',
            'sucursal_id' => 9,
            'activo' => 1,
        ];
        $response = $this->post('/ajax/usuarios.php', $editar, 'admin');
        $this->assertSame(200, $response->status, $response->body);
        $this->assertSame([2, 3, 4, 5, 9], $this->asignadas(2));

        $anterior = $this->get('/ajax/stock.php?sucursal_id=1', 'encargado_encargado_central');
        $this->assertSame(403, $anterior->status, $anterior->body);
        $this->assertSame(200, $this->get('/ajax/stock.php?sucursal_id=9', 'encargado_encargado_central')->status);

        // Sin parámetro usa la nueva principal (sesión sincronizada con la BD)
        $propio = $this->get('/ajax/stock.php', 'encargado_encargado_central');
        $this->assertSame(200, $propio->status, $propio->body);
        foreach ($propio->json()['data'] as $row) {
            $this->assertSame(9, (int) $row['sucursal_id']);
        }

        // Repetir la edición no cambia nada
        $this->assertSame(200, $this->post('/ajax/usuarios.php', $editar, 'admin')->status);
        $this->assertSame([2, 3, 4, 5, 9], $this->asignadas(2));
    }

    public function testCajaPromotedToEncargadoOnlyGetsNewPrincipal(): void
    {
        $this->loginAs('admin');

        $response = $this->post('/ajax/usuarios.php', [
            'accion' => 'editar',
            'id' => 6,
            'nombre_display' => 'Nuevo Encargado Lujan',
            'rol' => 'encargado',
            'sucursal_id' => 3,
            'activo' => 1,
        ], 'admin');

        $this->assertSame(200, $response->status, $response->body);
        $this->assertSame([3], $this->asignadas(6));
    }

    /**
     * SEC-6 / C5: sin clave por defecto; reset_password exige 'password'.
     */
    public function testResetPasswordRequiresExplicitPassword(): void
    {
        $this->loginAs('admin');
        $antes = $this->scalar('SELECT password_hash FROM usuarios WHERE id = 5');

        $sinPassword = $this->post('/ajax/usuarios.php', [
            'accion' => 'reset_password',
            'usuario_id' => 5,
        ], 'admin');
        $this->assertSame(400, $sinPassword->status, $sinPassword->body);

        foreach (['', '   '] as $vacia) {
            $response = $this->post('/ajax/usuarios.php', [
                'accion' => 'reset_password',
                'usuario_id' => 5,
                'password' => $vacia,
            ], 'admin');
            $this->assertSame(400, $response->status, $response->body);
        }

        $this->assertSame($antes, $this->scalar('SELECT password_hash FROM usuarios WHERE id = 5'));
    }

    public function testUnknownActionsReturnBadRequest(): void
    {
        $this->loginAs('admin');

        $this->assertSame(400, $this->get('/ajax/usuarios.php?accion=otra', 'admin')->status);
        $this->assertSame(400, $this->post('/ajax/usuarios.php', ['accion' => 'otra'], 'admin')->status);
        $this->assertSame(405, $this->http('PATCH', '/ajax/usuarios.php', null, 'admin')->status);
    }

    /**
     * @return list<int> Sucursales del usuario en usuario_sucursales, ordenadas
     */
    private function asignadas(int $usuarioId): array
    {
        $rows = $this->rows(
            'SELECT sucursal_id FROM usuario_sucursales WHERE usuario_id = ? ORDER BY sucursal_id',
            [$usuarioId]
        );

        return array_map(static fn (array $r): int => (int) $r['sucursal_id'], $rows);
    }
}
