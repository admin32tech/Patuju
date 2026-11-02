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

    // 2. Verificar y resetear contraseña de admin si es necesario
    echo "<p>Verificando usuario <b>admin</b>...</p>";
    $stmt = $db->query("SELECT id, password_hash FROM usuarios WHERE username = 'admin' LIMIT 1");
    $admin = $stmt->fetch();

    if ($admin) {
        if (!password_verify('patuju2024', $admin['password_hash'])) {
            $hashValido = password_hash('patuju2024', PASSWORD_BCRYPT);
            $db->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")->execute([$hashValido, $admin['id']]);
            echo "<p style='color:green;'>Contraseña de admin reseteada a 'patuju2024' con Hash Bcrypt válido.</p>";
        } else {
            echo "<p style='color:gray;'>La contraseña de admin ya está correcta (Bcrypt).</p>";
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

    echo "<h3>¡Actualización completada exitosamente!</h3>";
    echo "<p>Ahora puedes volver al <a href='login.php'>Login</a>.</p>";

} catch (Exception $e) {
    echo "<h3 style='color:red;'>Error durante la actualización:</h3>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
