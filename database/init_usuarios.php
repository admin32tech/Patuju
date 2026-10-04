<?php
/**
 * PATUJU POS — Inicialización de Usuarios
 * Genera hashes bcrypt REALES con password_hash()
 * 
 * Uso CLI:    php database/init_usuarios.php
 * Uso web:    abrir en navegador (Laragon/XAMPP)
 * Contraseña: patuju2024 (todos los usuarios)
 * 
 * ⚠️  Requiere que schema.sql ya esté importado (tablas + sucursales deben existir)
 */

require_once __DIR__ . '/../config/database.php';

// Detectar si es CLI o web
$esCli = php_sapi_name() === 'cli';
if (!$esCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<h1>Patuju POS — Inicialización de Usuarios</h1><pre>";
}

try {
    $db = getDB();
    $password = 'patuju2024';
    $hash = password_hash($password, PASSWORD_BCRYPT);

    echo "🔑 Contraseña: $password\n";
    echo "🔒 Hash generado: $hash\n";
    echo "📏 Longitud: " . strlen($hash) . " (válido = 60)\n";
    echo "✅ Verificación: " . (password_verify($password, $hash) ? 'PASS' : 'FAIL') . "\n\n";

    // ── Usuarios: 1 Admin + 3 Encargados + 15 Cajeros ─────────────────────
    $usuarios = [
        // [username, nombre_display, rol, sucursal_id]
        // Admin Global
        ['admin', 'Administrador General', 'admin', NULL],
        // 3 Encargados regionales
        ['encargado_encargado_central',    'Encargado Central',    'encargado', 1],
        ['encargado_encargado_cochabamba',  'Encargado Cochabamba', 'encargado', 6],
        ['encargado_encargado_santa_cruz',  'Encargado Santa Cruz', 'encargado', 8],
        // 15 Cajeros (uno por sucursal)
        ['caja_caja_central',              'Caja Central',              'caja', 1],
        ['caja_caja_lujan',                'Caja Lujan',                'caja', 2],
        ['caja_caja_lujan2',               'Caja Lujan2',               'caja', 3],
        ['caja_caja_2_de_agosto',          'Caja 2 de Agosto',          'caja', 4],
        ['caja_caja_plan3000',             'Caja Plan3000',             'caja', 5],
        ['caja_caja_1plan3000',            'Caja 1Plan3000',            'caja', 6],
        ['caja_caja_2plan3000',            'Caja 2Plan3000',            'caja', 7],
        ['caja_caja_santa_cruz_centro',    'Caja Santa Cruz Centro',    'caja', 8],
        ['caja_caja_equipetrol',           'Caja Equipetrol',           'caja', 9],
        ['caja_caja_1',                    'Caja 1',                    'caja', 10],
        ['caja_caja_2',                    'Caja 2',                    'caja', 11],
        ['caja_caja_3',                    'Caja 3',                    'caja', 12],
        ['caja_caja_4',                    'Caja 4',                    'caja', 13],
        ['caja_caja_5',                    'Caja 5',                    'caja', 14],
        ['caja_caja_6',                    'Caja 6',                    'caja', 15],
    ];

    $stmt = $db->prepare("
        INSERT INTO usuarios (username, password_hash, nombre_display, rol, sucursal_id, activo)
        VALUES (?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)
    ");

    $insertados = 0;
    $actualizados = 0;

    foreach ($usuarios as $u) {
        $stmt->execute([$u[0], $hash, $u[1], $u[2], $u[3]]);
        if ($stmt->rowCount() === 1) {
            $insertados++;
            echo "  ➕ Insertado: {$u[0]} ({$u[2]})\n";
        } elseif ($stmt->rowCount() === 2) {
            // ON DUPLICATE KEY UPDATE cuenta como 2 rows affected
            $actualizados++;
            echo "  🔄 Actualizado hash: {$u[0]} ({$u[2]})\n";
        } else {
            echo "  ⏭️  Sin cambios: {$u[0]}\n";
        }
    }

    echo "\n═══════════════════════════════════════\n";
    echo "✨ Listo: $insertados insertados, $actualizados actualizados\n";
    echo "📝 Total usuarios: " . count($usuarios) . "\n";
    echo "🔑 Login: admin / $password\n";
    echo "═══════════════════════════════════════\n";

} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

if (!$esCli) {
    echo "</pre><p><a href='login.php'>← Ir al Login</a></p>";
}
