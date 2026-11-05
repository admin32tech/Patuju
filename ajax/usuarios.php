<?php
/**
 * PATUJU POS — Gestión de Usuarios y Permisos (Fase 2.5)
 * Endpoint CRUD seguro para administración de cuentas y asignación de sucursales.
 * 
 * Requisitos: Rol 'admin' autenticado.
 */

require_once __DIR__ . '/../config/auth.php';

// Verificación estricta de sesión de Administrador
verificarSesionAjax('admin');

$db = getDB();
$metodo = $_SERVER['REQUEST_METHOD'];

// La tabla N:M la crea y siembra solo actualizar_bd.php. Crearla aquí vacía haría
// que la migración creyera que ya existía y no sembrara las asignaciones iniciales.
if (!existeTablaUsuarioSucursales()) {
    jsonResponse(['success' => false, 'error' => 'Falta migrar la base de datos: ejecuta actualizar_bd.php'], 503);
}

function sucursalExiste(PDO $db, int $sucursalId): bool
{
    $stmt = $db->prepare("SELECT 1 FROM sucursales WHERE id = ? AND activo = 1");
    $stmt->execute([$sucursalId]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Indica si la sucursal es interna (SUC-000, es_interna = 1): solo para administradores.
 */
function sucursalEsInterna(PDO $db, int $sucursalId): bool
{
    $stmt = $db->prepare("SELECT es_interna FROM sucursales WHERE id = ?");
    $stmt->execute([$sucursalId]);

    return (int) $stmt->fetchColumn() === 1;
}

const ERROR_SUCURSAL_INTERNA = 'La sucursal interna de administración solo puede asignarse a administradores';

// ══════════════════════════════════════════════════════════════════════════
// GET: Listar usuarios y catálogo de sucursales
// ══════════════════════════════════════════════════════════════════════════
if ($metodo === 'GET') {
    $accion = $_GET['accion'] ?? 'listar';

    if ($accion === 'listar') {
        // 1. Obtener lista de sucursales activas (para selectores y checklists)
        $stmtSuc = $db->query("
            SELECT id, codigo, nombre, direccion, es_interna, activo 
            FROM sucursales 
            WHERE activo = 1 
            ORDER BY es_interna DESC, id ASC
        ");
        $sucursales = $stmtSuc->fetchAll(PDO::FETCH_ASSOC);

        // 2. Obtener usuarios con sucursal principal
        $stmtUsers = $db->query("
            SELECT 
                u.id,
                u.username,
                u.nombre_display,
                u.rol,
                u.sucursal_id,
                s.nombre AS sucursal_principal_nombre,
                s.codigo AS sucursal_principal_codigo,
                u.activo,
                u.ultimo_login,
                u.created_at
            FROM usuarios u
            LEFT JOIN sucursales s ON s.id = u.sucursal_id
            ORDER BY 
                CASE u.rol 
                    WHEN 'admin' THEN 1 
                    WHEN 'encargado' THEN 2 
                    ELSE 3 
                END,
                u.id ASC
        ");
        $usuariosRaw = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

        // 3. Obtener todas las asignaciones N:M de usuario_sucursales
        $stmtAsig = $db->query("
            SELECT 
                us.usuario_id, 
                us.sucursal_id,
                s.codigo AS sucursal_codigo,
                s.nombre AS sucursal_nombre
            FROM usuario_sucursales us
            JOIN sucursales s ON s.id = us.sucursal_id
            WHERE s.activo = 1
            ORDER BY us.usuario_id ASC, s.id ASC
        ");
        $asignaciones = $stmtAsig->fetchAll(PDO::FETCH_ASSOC);

        $mapaSucursalesPorUsuario = [];
        foreach ($asignaciones as $asig) {
            $uId = (int) $asig['usuario_id'];
            if (!isset($mapaSucursalesPorUsuario[$uId])) {
                $mapaSucursalesPorUsuario[$uId] = [];
            }
            $mapaSucursalesPorUsuario[$uId][] = [
                'id'     => (int) $asig['sucursal_id'],
                'codigo' => $asig['sucursal_codigo'],
                'nombre' => $asig['sucursal_nombre'],
            ];
        }

        // Formatear usuarios
        $usuarios = [];
        $kpis = [
            'total'       => count($usuariosRaw),
            'activos'     => 0,
            'admins'      => 0,
            'encargados'  => 0,
            'cajeros'     => 0,
        ];

        foreach ($usuariosRaw as $u) {
            $uId = (int) $u['id'];
            $asigList = $mapaSucursalesPorUsuario[$uId] ?? [];
            $asigIds  = array_column($asigList, 'id');

            // Si es encargado y tiene sucursal_id principal que no esté en la lista, incluirla
            if ($u['rol'] === 'encargado' && !empty($u['sucursal_id']) && !in_array((int)$u['sucursal_id'], $asigIds, true)) {
                $asigList[] = [
                    'id'     => (int) $u['sucursal_id'],
                    'codigo' => $u['sucursal_principal_codigo'] ?? 'SUC',
                    'nombre' => $u['sucursal_principal_nombre'] ?? 'Principal',
                ];
                $asigIds[] = (int) $u['sucursal_id'];
            }

            if ((int)$u['activo'] === 1) $kpis['activos']++;
            if ($u['rol'] === 'admin') $kpis['admins']++;
            if ($u['rol'] === 'encargado') $kpis['encargados']++;
            if ($u['rol'] === 'caja') $kpis['cajeros']++;

            $usuarios[] = [
                'id'                        => $uId,
                'username'                  => $u['username'],
                'nombre_display'            => $u['nombre_display'],
                'rol'                       => $u['rol'],
                'sucursal_id'               => $u['sucursal_id'] ? (int) $u['sucursal_id'] : null,
                'sucursal_nombre'           => $u['sucursal_principal_nombre'] ?? 'Sin asignar',
                'sucursales_asignadas'      => $asigList,
                'sucursales_asignadas_ids'  => $asigIds,
                'activo'                    => (int) $u['activo'],
                'ultimo_login'              => $u['ultimo_login'],
                'created_at'                => $u['created_at'],
            ];
        }

        jsonResponse([
            'success'    => true,
            'usuarios'   => $usuarios,
            'sucursales' => $sucursales,
            'kpis'       => $kpis,
        ]);
    }

    jsonResponse(['success' => false, 'error' => 'Acción GET no reconocida'], 400);
}

// ══════════════════════════════════════════════════════════════════════════
// POST: Crear, Editar, Asignar Sucursales, Reset Password, Toggle Activo
// ══════════════════════════════════════════════════════════════════════════
if ($metodo === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        jsonResponse(['success' => false, 'error' => 'Cuerpo JSON inválido'], 400);
    }

    $accion = $input['accion'] ?? '';

    // ── ACCIÓN: CREAR USUARIO ─────────────────────────────────────────────
    if ($accion === 'crear') {
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $nombre   = trim($input['nombre_display'] ?? '');
        $rol      = trim($input['rol'] ?? 'caja');
        $sucId    = !empty($input['sucursal_id']) ? (int) $input['sucursal_id'] : null;
        $activo   = isset($input['activo']) ? ((int) $input['activo'] === 1 ? 1 : 0) : 1;
        $sucList  = is_array($input['sucursales_asignadas'] ?? null) ? array_map('intval', $input['sucursales_asignadas']) : [];

        // Validaciones
        if (strlen($username) < 3 || strlen($username) > 50) {
            jsonResponse(['success' => false, 'error' => 'El nombre de usuario debe tener entre 3 y 50 caracteres'], 400);
        }
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $username)) {
            jsonResponse(['success' => false, 'error' => 'El usuario solo puede contener letras, números, guiones y puntos'], 400);
        }
        if (strlen($password) < 6) {
            jsonResponse(['success' => false, 'error' => 'La contraseña debe tener al menos 6 caracteres'], 400);
        }
        if (strlen($nombre) < 2 || strlen($nombre) > 150) {
            jsonResponse(['success' => false, 'error' => 'El nombre completo debe tener entre 2 y 150 caracteres'], 400);
        }
        if (!in_array($rol, ['admin', 'encargado', 'caja'], true)) {
            jsonResponse(['success' => false, 'error' => 'Rol inválido'], 400);
        }

        // Si es caja o encargado, la sucursal principal es obligatoria
        if ($rol !== 'admin' && empty($sucId)) {
            jsonResponse(['success' => false, 'error' => 'Debes asignar una sucursal principal para este rol'], 400);
        }

        // Si es admin y no especificó sucursal, asignar la interna SUC-000
        if ($rol === 'admin' && empty($sucId)) {
            $stmt000 = $db->query("SELECT id FROM sucursales WHERE es_interna = 1 LIMIT 1");
            $suc000 = $stmt000->fetchColumn();
            if ($suc000) $sucId = (int)$suc000;
        }

        if ($sucId !== null && !sucursalExiste($db, $sucId)) {
            jsonResponse(['success' => false, 'error' => 'La sucursal principal no existe o está inactiva'], 400);
        }
        if ($rol !== 'admin' && sucursalEsInterna($db, $sucId)) {
            jsonResponse(['success' => false, 'error' => ERROR_SUCURSAL_INTERNA], 400);
        }
        foreach ($sucList as $sId) {
            if ($sId <= 0 || !sucursalExiste($db, $sId)) {
                jsonResponse(['success' => false, 'error' => "La sucursal asignada #{$sId} no existe o está inactiva"], 400);
            }
            if ($rol !== 'admin' && sucursalEsInterna($db, $sId)) {
                jsonResponse(['success' => false, 'error' => ERROR_SUCURSAL_INTERNA], 400);
            }
        }

        // Verificar duplicados
        $stmtCheck = $db->prepare("SELECT id FROM usuarios WHERE username = ? LIMIT 1");
        $stmtCheck->execute([$username]);
        if ($stmtCheck->fetch()) {
            jsonResponse(['success' => false, 'error' => "El nombre de usuario '{$username}' ya está registrado"], 409);
        }

        try {
            $db->beginTransaction();

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmtIns = $db->prepare("
                INSERT INTO usuarios (username, password_hash, nombre_display, rol, sucursal_id, activo)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmtIns->execute([$username, $hash, $nombre, $rol, $sucId, $activo]);
            $nuevoId = (int) $db->lastInsertId();

            // Si es encargado, insertar sucursales asignadas
            if ($rol === 'encargado') {
                if ($sucId && !in_array($sucId, $sucList, true)) {
                    $sucList[] = $sucId;
                }
                if (!empty($sucList)) {
                    $stmtUS = $db->prepare("INSERT IGNORE INTO usuario_sucursales (usuario_id, sucursal_id) VALUES (?, ?)");
                    foreach ($sucList as $sId) {
                        if ($sId > 0) {
                            $stmtUS->execute([$nuevoId, $sId]);
                        }
                    }
                }
            }

            $db->commit();

            jsonResponse([
                'success' => true,
                'message' => "Usuario '{$username}' creado exitosamente",
                'usuario_id' => $nuevoId
            ]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            jsonResponse(['success' => false, 'error' => 'Error al crear usuario: ' . $e->getMessage()], 500);
        }
    }

    // ── ACCIÓN: EDITAR USUARIO ────────────────────────────────────────────
    if ($accion === 'editar') {
        $id      = (int) ($input['id'] ?? 0);
        $nombre  = trim($input['nombre_display'] ?? '');
        $rol     = trim($input['rol'] ?? '');
        $sucId   = !empty($input['sucursal_id']) ? (int) $input['sucursal_id'] : null;
        // Solo 1 activa; cualquier otro valor (0, 2, -1) desactiva, así no salta la guardia de la propia cuenta
        $activo  = isset($input['activo']) ? ((int) $input['activo'] === 1 ? 1 : 0) : 1;
        $sucList = is_array($input['sucursales_asignadas'] ?? null) ? array_map('intval', $input['sucursales_asignadas']) : null;

        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'ID de usuario requerido'], 400);
        }

        // Buscar usuario existente
        $stmtUser = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmtUser->execute([$id]);
        $usuarioActual = $stmtUser->fetch();
        if (!$usuarioActual) {
            jsonResponse(['success' => false, 'error' => 'Usuario no encontrado'], 404);
        }

        // Protección: No desactivar ni degradar al usuario admin en sesión actual
        $esPropioUsuario = ($id === (int)$_SESSION['usuario_id']);
        if ($esPropioUsuario && $activo === 0) {
            jsonResponse(['success' => false, 'error' => 'No puedes desactivar tu propia cuenta en sesión'], 400);
        }
        if ($esPropioUsuario && $rol !== 'admin') {
            jsonResponse(['success' => false, 'error' => 'No puedes cambiar el rol de tu propia cuenta de administrador'], 400);
        }

        if (strlen($nombre) < 2 || strlen($nombre) > 150) {
            jsonResponse(['success' => false, 'error' => 'El nombre completo debe tener entre 2 y 150 caracteres'], 400);
        }
        if (!in_array($rol, ['admin', 'encargado', 'caja'], true)) {
            jsonResponse(['success' => false, 'error' => 'Rol inválido'], 400);
        }
        // Caja y encargado requieren sucursal principal (y no puede ser la interna)
        if ($rol !== 'admin' && $sucId === null) {
            jsonResponse(['success' => false, 'error' => 'Debes asignar una sucursal principal para este rol'], 400);
        }
        if ($sucId !== null && !sucursalExiste($db, $sucId)) {
            jsonResponse(['success' => false, 'error' => 'La sucursal principal no existe o está inactiva'], 400);
        }
        if ($rol !== 'admin' && sucursalEsInterna($db, $sucId)) {
            jsonResponse(['success' => false, 'error' => ERROR_SUCURSAL_INTERNA], 400);
        }
        if ($sucList !== null) {
            foreach ($sucList as $sId) {
                if ($sId <= 0 || !sucursalExiste($db, $sId)) {
                    jsonResponse(['success' => false, 'error' => "La sucursal asignada #{$sId} no existe o está inactiva"], 400);
                }
                if ($rol !== 'admin' && sucursalEsInterna($db, $sId)) {
                    jsonResponse(['success' => false, 'error' => ERROR_SUCURSAL_INTERNA], 400);
                }
            }
        }

        try {
            $db->beginTransaction();

            $stmtUp = $db->prepare("
                UPDATE usuarios 
                SET nombre_display = ?, rol = ?, sucursal_id = ?, activo = ?
                WHERE id = ?
            ");
            $stmtUp->execute([$nombre, $rol, $sucId, $activo, $id]);

            // Si es encargado y se envió lista de sucursales, sincronizar usuario_sucursales
            if ($rol === 'encargado' && $sucList !== null) {
                if ($sucId && !in_array($sucId, $sucList, true)) {
                    $sucList[] = $sucId;
                }
                $db->prepare("DELETE FROM usuario_sucursales WHERE usuario_id = ?")->execute([$id]);
                if (!empty($sucList)) {
                    $stmtUS = $db->prepare("INSERT IGNORE INTO usuario_sucursales (usuario_id, sucursal_id) VALUES (?, ?)");
                    foreach ($sucList as $sId) {
                        if ($sId > 0) {
                            $stmtUS->execute([$id, $sId]);
                        }
                    }
                }
            } elseif ($rol === 'encargado') {
                // Sin lista explícita: la principal siempre conserva acceso. Si cambió,
                // se quita la fila de la principal anterior y se vincula la nueva.
                $sucAnterior = (int) ($usuarioActual['sucursal_id'] ?? 0);
                if ($sucAnterior > 0 && $sucAnterior !== $sucId) {
                    $db->prepare("DELETE FROM usuario_sucursales WHERE usuario_id = ? AND sucursal_id = ?")->execute([$id, $sucAnterior]);
                }
                $db->prepare("INSERT IGNORE INTO usuario_sucursales (usuario_id, sucursal_id) VALUES (?, ?)")->execute([$id, $sucId]);
            } else {
                // Si cambió a admin o caja, limpiar asignaciones múltiples
                $db->prepare("DELETE FROM usuario_sucursales WHERE usuario_id = ?")->execute([$id]);
            }

            $db->commit();

            jsonResponse([
                'success' => true,
                'message' => "Usuario '{$usuarioActual['username']}' actualizado correctamente"
            ]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            jsonResponse(['success' => false, 'error' => 'Error al actualizar usuario: ' . $e->getMessage()], 500);
        }
    }

    // ── ACCIÓN: ASIGNAR SUCURSALES (Multi-sucursales para Encargado) ───────
    if ($accion === 'asignar_sucursales') {
        $id      = (int) ($input['usuario_id'] ?? 0);
        $sucList = is_array($input['sucursales_ids'] ?? null) ? array_map('intval', $input['sucursales_ids']) : [];

        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'ID de usuario requerido'], 400);
        }

        $stmtUser = $db->prepare("SELECT id, username, rol, sucursal_id FROM usuarios WHERE id = ?");
        $stmtUser->execute([$id]);
        $user = $stmtUser->fetch();
        if (!$user) {
            jsonResponse(['success' => false, 'error' => 'Usuario no encontrado'], 404);
        }

        if ($user['rol'] !== 'encargado') {
            jsonResponse(['success' => false, 'error' => 'Solo los usuarios con rol Encargado pueden tener múltiples sucursales asignadas'], 400);
        }

        foreach ($sucList as $sId) {
            if ($sId <= 0 || !sucursalExiste($db, $sId)) {
                jsonResponse(['success' => false, 'error' => "La sucursal asignada #{$sId} no existe o está inactiva"], 400);
            }
            if (sucursalEsInterna($db, $sId)) {
                jsonResponse(['success' => false, 'error' => ERROR_SUCURSAL_INTERNA], 400);
            }
        }

        // La sucursal principal siempre conserva acceso aunque no venga en la lista
        $sucList = array_values(array_unique($sucList));
        if (!empty($user['sucursal_id']) && !in_array((int) $user['sucursal_id'], $sucList, true)) {
            $sucList[] = (int) $user['sucursal_id'];
        }

        try {
            $db->beginTransaction();

            $db->prepare("DELETE FROM usuario_sucursales WHERE usuario_id = ?")->execute([$id]);

            $stmtIns = $db->prepare("INSERT IGNORE INTO usuario_sucursales (usuario_id, sucursal_id) VALUES (?, ?)");
            $insertadas = 0;
            foreach ($sucList as $sId) {
                if ($sId > 0) {
                    $stmtIns->execute([$id, $sId]);
                    $insertadas++;
                }
            }

            // Si el usuario no tiene sucursal_id principal y seleccionó alguna, actualizar la principal
            if (!empty($sucList) && empty($user['sucursal_id'])) {
                $db->prepare("UPDATE usuarios SET sucursal_id = ? WHERE id = ?")->execute([$sucList[0], $id]);
            }

            $db->commit();

            jsonResponse([
                'success' => true,
                'message' => "Se asignaron {$insertadas} sucursales al encargado '{$user['username']}'",
                'total_asignadas' => $insertadas
            ]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            jsonResponse(['success' => false, 'error' => 'Error al guardar asignaciones: ' . $e->getMessage()], 500);
        }
    }

    // ── ACCIÓN: RESETEAR CONTRASEÑA ───────────────────────────────────────
    if ($accion === 'reset_password') {
        $id       = (int) ($input['usuario_id'] ?? 0);
        $nuevaPass = is_string($input['password'] ?? null) ? trim($input['password']) : '';

        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'ID de usuario requerido'], 400);
        }
        // Sin clave por defecto: el administrador debe indicar la nueva contraseña
        if ($nuevaPass === '') {
            jsonResponse(['success' => false, 'error' => 'Debes indicar la nueva contraseña'], 400);
        }
        if (strlen($nuevaPass) < 6) {
            jsonResponse(['success' => false, 'error' => 'La contraseña debe tener al menos 6 caracteres'], 400);
        }

        $stmtUser = $db->prepare("SELECT id, username FROM usuarios WHERE id = ?");
        $stmtUser->execute([$id]);
        $user = $stmtUser->fetch();
        if (!$user) {
            jsonResponse(['success' => false, 'error' => 'Usuario no encontrado'], 404);
        }

        $hash = password_hash($nuevaPass, PASSWORD_BCRYPT);
        $db->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")->execute([$hash, $id]);

        jsonResponse([
            'success' => true,
            'message' => "Contraseña para el usuario '{$user['username']}' restablecida con éxito"
        ]);
    }

    // ── ACCIÓN: TOGGLE ESTADO ACTIVO/INACTIVO ──────────────────────────────
    if ($accion === 'toggle_activo') {
        $id = (int) ($input['usuario_id'] ?? 0);

        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'ID de usuario requerido'], 400);
        }

        if ($id === (int) $_SESSION['usuario_id']) {
            jsonResponse(['success' => false, 'error' => 'No puedes desactivar tu propio usuario en sesión activa'], 400);
        }

        $stmtUser = $db->prepare("SELECT id, username, activo FROM usuarios WHERE id = ?");
        $stmtUser->execute([$id]);
        $user = $stmtUser->fetch();
        if (!$user) {
            jsonResponse(['success' => false, 'error' => 'Usuario no encontrado'], 404);
        }

        $nuevoEstado = ((int)$user['activo'] === 1) ? 0 : 1;
        $db->prepare("UPDATE usuarios SET activo = ? WHERE id = ?")->execute([$nuevoEstado, $id]);

        $estadoTexto = $nuevoEstado === 1 ? 'activado' : 'desactivado';
        jsonResponse([
            'success'      => true,
            'message'      => "Usuario '{$user['username']}' {$estadoTexto}",
            'nuevo_estado' => $nuevoEstado
        ]);
    }

    jsonResponse(['success' => false, 'error' => 'Acción POST no reconocida'], 400);
}

jsonResponse(['success' => false, 'error' => 'Método HTTP no soportado'], 405);
