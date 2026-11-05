<?php
/**
 * Script de Actualización de Base de Datos - PATUJU POS
 * Corrige problemas estructurales en la BD local sin pérdida de datos.
 */
require_once __DIR__ . '/config/database.php';

echo "<h1>Actualización de Base de Datos - Patuju POS</h1>";

try {
    $db = getDB();

    // 1. Verificar y agregar 'usuario_id' a la tabla 'ventas'
    echo "<p>Verificando tabla <b>ventas</b>...</p>";
    $stmt = $db->query("SHOW COLUMNS FROM ventas LIKE 'usuario_id'");
    if ($stmt->rowCount() == 0) {
        $db->exec("ALTER TABLE ventas ADD COLUMN usuario_id INT NULL COMMENT 'Cajero que realizó la venta' AFTER sucursal_id");
        echo "<p style='color:green;'>Columna 'usuario_id' agregada a 'ventas'.</p>";

        $db->exec("ALTER TABLE ventas ADD CONSTRAINT fk_ventas_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL ON UPDATE CASCADE");
        echo "<p style='color:green;'>Clave foránea 'fk_ventas_usuario' agregada.</p>";
    } else {
        echo "<p style='color:gray;'>La columna 'usuario_id' ya existe.</p>";
    }

    // 2. Fijar contraseña de admin solo si no tiene un hash utilizable
    //    (una clave ya cambiada por el usuario nunca se restablece)
    echo "<p>Verificando usuario <b>admin</b>...</p>";
    $stmt = $db->query("SELECT id, password_hash FROM usuarios WHERE username = 'admin' LIMIT 1");
    $admin = $stmt->fetch();

    if ($admin) {
        $hashActual = (string) ($admin['password_hash'] ?? '');
        if ($hashActual === '' || empty(password_get_info($hashActual)['algo'])) {
            $hashValido = password_hash('patuju2024', PASSWORD_BCRYPT);
            $db->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")->execute([$hashValido, $admin['id']]);
            echo "<p style='color:green;'>La contraseña de admin estaba vacía o sin un hash reconocible: se fijó 'patuju2024' (Bcrypt). Cámbiala tras iniciar sesión.</p>";
        } else {
            echo "<p style='color:gray;'>La contraseña de admin ya tiene un hash válido; no se modifica.</p>";
        }
    }

    // 3. Verificar y agregar 'es_interna' a 'sucursales'
    echo "<p>Verificando columna <b>es_interna</b> en 'sucursales'...</p>";
    $stmt = $db->query("SHOW COLUMNS FROM sucursales LIKE 'es_interna'");
    if ($stmt->rowCount() == 0) {
        $db->exec("ALTER TABLE sucursales ADD COLUMN es_interna TINYINT(1) NOT NULL DEFAULT 0 AFTER activo");
        echo "<p style='color:green;'>Columna 'es_interna' agregada a 'sucursales'.</p>";
    } else {
        echo "<p style='color:gray;'>La columna 'es_interna' ya existe en 'sucursales'.</p>";
    }

    // 4. Verificar o crear sucursal interna SUC-000
    echo "<p>Verificando sucursal <b>SUC-000</b>...</p>";
    $stmt = $db->query("SELECT id FROM sucursales WHERE codigo = 'SUC-000' LIMIT 1");
    $suc000 = $stmt->fetch();
    if (!$suc000) {
        $stmtIns = $db->prepare("INSERT INTO sucursales (codigo, nombre, direccion, es_interna, activo) VALUES ('SUC-000', 'Administración', 'Oficina central', 1, 1)");
        $stmtIns->execute();
        $suc000Id = (int)$db->lastInsertId();
        echo "<p style='color:green;'>Sucursal 'SUC-000' creada con ID {$suc000Id}.</p>";
    } else {
        $suc000Id = (int)$suc000['id'];
        echo "<p style='color:gray;'>La sucursal 'SUC-000' ya existe con ID {$suc000Id}.</p>";
    }

    // 5. Sembrar stock para SUC-000
    echo "<p>Sembrando stock para sucursal <b>SUC-000</b>...</p>";
    $sqlStock = "INSERT IGNORE INTO stock_sucursal (sucursal_id, producto_id, cantidad_disponible, alerta_minima, stock_predeterminado, disponible_venta)
                 SELECT ?, id, 50, 10, 0, 1 FROM productos WHERE activo = 1";
    $stmtStock = $db->prepare($sqlStock);
    $stmtStock->execute([$suc000Id]);
    echo "<p style='color:green;'>Stock sembrado/verificado para SUC-000.</p>";

    // 6. Asignar SUC-000 a usuarios admin
    echo "<p>Asignando sucursal SUC-000 a usuarios <b>admin</b>...</p>";
    $stmtAdmin = $db->prepare("UPDATE usuarios SET sucursal_id = ? WHERE rol = 'admin' AND (sucursal_id IS NULL OR sucursal_id <> ?)");
    $stmtAdmin->execute([$suc000Id, $suc000Id]);
    echo "<p style='color:green;'>Usuarios admin actualizados (afectados: " . $stmtAdmin->rowCount() . ").</p>";

    // 7. Verificar y crear tabla 'usuario_sucursales' (N:M para Encargados)
    echo "<p>Verificando tabla <b>usuario_sucursales</b>...</p>";
    $tablaUsExistia = $db->query("SHOW TABLES LIKE 'usuario_sucursales'")->fetchColumn() !== false;
    $db->exec("
        CREATE TABLE IF NOT EXISTS usuario_sucursales (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id  INT NOT NULL,
            sucursal_id INT NOT NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_suc (usuario_id, sucursal_id),
            FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)   ON DELETE CASCADE ON UPDATE CASCADE,
            FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE CASCADE ON UPDATE CASCADE,
            INDEX idx_us_user (usuario_id),
            INDEX idx_us_suc  (sucursal_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<p style='color:green;'>Tabla 'usuario_sucursales' verificada/creada.</p>";

    // 8. Sembrar asignaciones iniciales solo si la tabla se acaba de crear
    //    (si ya existía, aunque esté vacía, se respetan las asignaciones actuales)
    echo "<p>Verificando asignaciones de sucursales para encargados...</p>";
    if (!$tablaUsExistia) {
        $distribucionEncargados = [
            'encargado_encargado_central'    => [1, 2, 3, 4, 5],
            'encargado_encargado_cochabamba'  => [6, 7],
            'encargado_encargado_santa_cruz'  => [8, 9, 10, 11, 12, 13, 14, 15],
        ];

        $stmtInsUS = $db->prepare("INSERT IGNORE INTO usuario_sucursales (usuario_id, sucursal_id) VALUES (?, ?)");
        $totalAsignados = 0;
        foreach ($distribucionEncargados as $uName => $sucList) {
            // Solo usuarios que sigan siendo encargados (la N:M no aplica a caja/admin)
            $uStmt = $db->prepare("SELECT id FROM usuarios WHERE username = ? AND rol = 'encargado' LIMIT 1");
            $uStmt->execute([$uName]);
            $uId = $uStmt->fetchColumn();
            if ($uId) {
                foreach ($sucList as $sId) {
                    $stmtInsUS->execute([$uId, $sId]);
                    $totalAsignados += $stmtInsUS->rowCount();
                }
            }
        }
        echo "<p style='color:green;'>Asignaciones iniciales de encargados sembradas ({$totalAsignados} relaciones).</p>";
    } else {
        $countUs = (int)$db->query("SELECT COUNT(*) FROM usuario_sucursales")->fetchColumn();
        echo "<p style='color:gray;'>La tabla 'usuario_sucursales' ya existía: se respetan las asignaciones actuales ({$countUs} registros).</p>";
    }

    echo "<h3>¡Actualización completada exitosamente!</h3>";
    echo "<p>Ahora puedes volver al <a href='login.php'>Login</a>.</p>";

} catch (Exception $e) {
    echo "<h3 style='color:red;'>Error durante la actualización:</h3>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
