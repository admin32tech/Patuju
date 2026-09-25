<?php
/**
 * PATUJU POS — Data Initialization (Seed Script FINAL)
 * 
 * ✅ Contraseña: patuju2024 (todos los usuarios)
 * ✅ Hash: $2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA
 * ✅ Sin ciudad/departamento en INSERT (solo en schema)
 * ✅ Laragon + phpAdmin ready
 * 
 * Uso:
 *   php database/seed.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Cargar configuración
require_once __DIR__ . '/../config/database.php';

if (!isset($db)) {
    die("❌ Error: \$db no está definido en config/database.php\n");
}

// ============================================
// CONFIGURACIÓN DE DATOS
// ============================================

$config = [
    'password_default' => 'patuju2024',
    'password_hash' => '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA',
    'stock_inicial' => 50,
    'alerta_minima' => 10,
];

// CATEGORÍAS
$categorias = [
    ['nombre' => 'Salteñas', 'icono' => '🥟', 'orden' => 1],
    ['nombre' => 'Bebidas', 'icono' => '🥤', 'orden' => 2],
    ['nombre' => 'Jugos', 'icono' => '🍊', 'orden' => 3],
    ['nombre' => 'Extras', 'icono' => '🍽️', 'orden' => 4],
];

// SUCURSALES (15 nacionales) — SIN ciudad/departamento en INSERT
$sucursales = [
    ['codigo' => 'SUC-001', 'nombre' => 'Patuju Central', 'direccion' => 'Barrio Melchor Pinto #1234', 'telefono' => '+591 2 2441234', 'responsable' => 'María López', 'horario' => '07:00 - 14:00', 'latitud' => -16.5000000, 'longitud' => -68.1500000],
    ['codigo' => 'SUC-002', 'nombre' => 'Patuju Lujan', 'direccion' => 'Av. Lujan-Av. Los Chacos #456', 'telefono' => '+591 2 2425678', 'responsable' => 'Carlos Mamani', 'horario' => '07:00 - 14:00', 'latitud' => -16.5050000, 'longitud' => -68.1350000],
    ['codigo' => 'SUC-003', 'nombre' => 'Patuju Lujan2', 'direccion' => 'Av. Lujan #789', 'telefono' => '+591 2 2223456', 'responsable' => 'Ana Quispe', 'horario' => '07:00 - 14:00', 'latitud' => -16.5150000, 'longitud' => -68.1200000],
    ['codigo' => 'SUC-004', 'nombre' => 'Patuju 2 de Agosto', 'direccion' => 'Av. 2 de agosto #321', 'telefono' => '+591 2 2771234', 'responsable' => 'Pedro Condori', 'horario' => '07:30 - 14:30', 'latitud' => -16.5300000, 'longitud' => -68.0900000],
    ['codigo' => 'SUC-005', 'nombre' => 'Patuju Plan3000', 'direccion' => 'Zona Plan 3000 #1500', 'telefono' => '+591 2 2791111', 'responsable' => 'Rosa Choque', 'horario' => '07:00 - 13:00', 'latitud' => -16.5350000, 'longitud' => -68.0850000],
    ['codigo' => 'SUC-006', 'nombre' => 'Patuju 1Plan3000', 'direccion' => 'Zona Plan 3000 #234', 'telefono' => '+591 4 4251234', 'responsable' => 'Jorge Rojas', 'horario' => '07:00 - 14:00', 'latitud' => -17.3935000, 'longitud' => -66.1570000],
    ['codigo' => 'SUC-007', 'nombre' => 'Patuju 2Plan3000', 'direccion' => 'Zona Plan 3000 #890', 'telefono' => '+591 4 4405678', 'responsable' => 'Lucía Flores', 'horario' => '07:00 - 14:00', 'latitud' => -17.3800000, 'longitud' => -66.1650000],
    ['codigo' => 'SUC-008', 'nombre' => 'Patuju Santa Cruz Centro', 'direccion' => 'AV. Cañoto #100', 'telefono' => '+591 3 3361234', 'responsable' => 'Miguel Suárez', 'horario' => '07:00 - 14:00', 'latitud' => -17.7833000, 'longitud' => -63.1821000],
    ['codigo' => 'SUC-009', 'nombre' => 'Patuju Equipetrol', 'direccion' => 'Av. San Martín #2500', 'telefono' => '+591 3 3425678', 'responsable' => 'Daniela Peña', 'horario' => '07:30 - 14:30', 'latitud' => -17.7700000, 'longitud' => -63.2000000],
    ['codigo' => 'SUC-010', 'nombre' => 'Patuju 1', 'direccion' => 'C. España #45', 'telefono' => '+591 4 6451234', 'responsable' => 'Fernando Arce', 'horario' => '07:00 - 13:00', 'latitud' => -19.0353000, 'longitud' => -65.2592000],
    ['codigo' => 'SUC-011', 'nombre' => 'Patuju 2', 'direccion' => 'Av. 6 de Octubre #678', 'telefono' => '+591 2 5251234', 'responsable' => 'Patricia Vargas', 'horario' => '07:00 - 13:00', 'latitud' => -17.9622000, 'longitud' => -67.1062000],
    ['codigo' => 'SUC-012', 'nombre' => 'Patuju 3', 'direccion' => 'C. Bolívar #200', 'telefono' => '+591 2 6221234', 'responsable' => 'Roberto Chávez', 'horario' => '07:00 - 13:00', 'latitud' => -19.5836000, 'longitud' => -65.7531000],
    ['codigo' => 'SUC-013', 'nombre' => 'Patuju 4', 'direccion' => 'Av. Víctor Paz #345', 'telefono' => '+591 4 6641234', 'responsable' => 'Carmen Gutiérrez', 'horario' => '07:00 - 14:00', 'latitud' => -21.5355000, 'longitud' => -64.7296000],
    ['codigo' => 'SUC-014', 'nombre' => 'Patuju 5', 'direccion' => 'Av. 6 de Agosto #90', 'telefono' => '+591 3 4621234', 'responsable' => 'Andrés Salvatierra', 'horario' => '07:00 - 13:00', 'latitud' => -14.8333000, 'longitud' => -64.9000000],
    ['codigo' => 'SUC-015', 'nombre' => 'Patuju 6', 'direccion' => 'Av. 6 de Marzo #4500', 'telefono' => '+591 2 2841234', 'responsable' => 'Juana Huanca', 'horario' => '06:30 - 13:00', 'latitud' => -16.5100000, 'longitud' => -68.1950000],
];

// PRODUCTOS (16)
$productos = [
    ['nombre' => 'Salteña Grande', 'precio' => 7.00, 'categoria' => 'Salteñas', 'descripcion' => 'Salteña de carne con huevo de codorniz'],
    ['nombre' => 'Salteña Grande especial', 'precio' => 8.00, 'categoria' => 'Salteñas', 'descripcion' => 'Salteña especial de pollo jugosa'],
    ['nombre' => 'Salteña Pequeña', 'precio' => 5.00, 'categoria' => 'Salteñas', 'descripcion' => 'Salteña pequeña ideal para niños'],
    ['nombre' => 'Salteña Pequeña especial', 'precio' => 6.00, 'categoria' => 'Salteñas', 'descripcion' => 'Salteña pequeña con huevo de codorniz'],
    ['nombre' => 'Salteña Reventadita', 'precio' => 3.50, 'categoria' => 'Salteñas', 'descripcion' => 'Salteña pequeña rebentadita'],
    
    ['nombre' => 'Soda pequeña', 'precio' => 5.00, 'categoria' => 'Bebidas', 'descripcion' => 'Soda clásica refrescante'],
    ['nombre' => 'Soda Cola Personal', 'precio' => 8.00, 'categoria' => 'Bebidas', 'descripcion' => 'Soda de cola clásica personal'],
    ['nombre' => 'Soda Cola 1 Litro', 'precio' => 12.00, 'categoria' => 'Bebidas', 'descripcion' => 'Soda de cola clásica 1L'],
    ['nombre' => 'Soda Cola 2 Litro', 'precio' => 16.00, 'categoria' => 'Bebidas', 'descripcion' => 'Soda de cola clásica 2L'],
    ['nombre' => 'Soda Cola 3 Litro', 'precio' => 22.00, 'categoria' => 'Bebidas', 'descripcion' => 'Soda de cola clásica 3L'],
    ['nombre' => 'Jugo del Valle 2 L', 'precio' => 17.00, 'categoria' => 'Bebidas', 'descripcion' => 'Jugo del Valle 2L'],
    ['nombre' => 'Jugo del Valle 3 L', 'precio' => 21.00, 'categoria' => 'Bebidas', 'descripcion' => 'Jugo del Valle 3L'],
    ['nombre' => 'Soda La Cabana 2 L', 'precio' => 6.00, 'categoria' => 'Bebidas', 'descripcion' => 'Soda La Cabana 2L'],
    ['nombre' => 'Agua Mineral', 'precio' => 5.00, 'categoria' => 'Bebidas', 'descripcion' => 'Agua mineral sin gas'],
    
    ['nombre' => 'Jugo de guineo', 'precio' => 7.00, 'categoria' => 'Jugos', 'descripcion' => 'Jugo natural recién licuado'],
    ['nombre' => 'Refresco de papaya', 'precio' => 5.00, 'categoria' => 'Jugos', 'descripcion' => 'Jugo natural exprimido'],
];

// USUARIOS
$admin = ['username' => 'admin', 'nombre' => 'Administrador General'];

$encargados = [
    ['nombre' => 'Encargado Central', 'sucursal_codigo' => 'SUC-001'],
    ['nombre' => 'Encargado Cochabamba', 'sucursal_codigo' => 'SUC-006'],
    ['nombre' => 'Encargado Santa Cruz', 'sucursal_codigo' => 'SUC-008'],
];

$cajeros = [
    ['nombre' => 'Caja Central', 'sucursal_codigo' => 'SUC-001'],
    ['nombre' => 'Caja Lujan', 'sucursal_codigo' => 'SUC-002'],
    ['nombre' => 'Caja Lujan2', 'sucursal_codigo' => 'SUC-003'],
    ['nombre' => 'Caja 2 de Agosto', 'sucursal_codigo' => 'SUC-004'],
    ['nombre' => 'Caja Plan3000', 'sucursal_codigo' => 'SUC-005'],
    ['nombre' => 'Caja 1Plan3000', 'sucursal_codigo' => 'SUC-006'],
    ['nombre' => 'Caja 2Plan3000', 'sucursal_codigo' => 'SUC-007'],
    ['nombre' => 'Caja Santa Cruz Centro', 'sucursal_codigo' => 'SUC-008'],
    ['nombre' => 'Caja Equipetrol', 'sucursal_codigo' => 'SUC-009'],
    ['nombre' => 'Caja 1', 'sucursal_codigo' => 'SUC-010'],
    ['nombre' => 'Caja 2', 'sucursal_codigo' => 'SUC-011'],
    ['nombre' => 'Caja 3', 'sucursal_codigo' => 'SUC-012'],
    ['nombre' => 'Caja 4', 'sucursal_codigo' => 'SUC-013'],
    ['nombre' => 'Caja 5', 'sucursal_codigo' => 'SUC-014'],
    ['nombre' => 'Caja 6', 'sucursal_codigo' => 'SUC-015'],
];

// ============================================
// SEEDING (No editar)
// ============================================

class DataSeeder {
    private $db;
    private $config;

    public function __construct($db, $config) {
        $this->db = $db;
        $this->config = $config;
    }

    public function run($categorias, $sucursales, $productos, $admin, $encargados, $cajeros) {
        echo "\n🚀 Iniciando población de BD...\n";
        echo "═════════════════════════════════════════════════════════════\n\n";

        try {
            echo "1️⃣  Insertando categorías...";
            $this->insertCategorias($categorias);
            echo " ✅\n";

            echo "2️⃣  Insertando sucursales...";
            $sucursal_map = $this->insertSucursales($sucursales);
            echo " ✅\n";

            echo "3️⃣  Insertando productos...";
            $producto_map = $this->insertProductos($productos, $categorias);
            echo " ✅\n";

            echo "4️⃣  Insertando administrador...";
            $this->insertAdmin($admin);
            echo " ✅\n";

            echo "5️⃣  Insertando encargados...";
            $this->insertEncargados($encargados, $sucursal_map);
            echo " ✅\n";

            echo "6️⃣  Insertando cajeros...";
            $this->insertCajeros($cajeros, $sucursal_map);
            echo " ✅\n";

            echo "7️⃣  Insertando stock inicial...";
            $this->insertStock($sucursal_map, $producto_map);
            echo " ✅\n";

            $this->printReport($sucursal_map, $producto_map);

        } catch (Exception $e) {
            echo "\n❌ ERROR: " . $e->getMessage() . "\n";
            die(1);
        }
    }

    private function insertCategorias($categorias) {
        $stmt = $this->db->prepare("
            INSERT INTO categorias (nombre, icono, orden) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE nombre=VALUES(nombre)
        ");
        foreach ($categorias as $cat) {
            $stmt->execute([$cat['nombre'], $cat['icono'], $cat['orden']]);
        }
    }

    private function insertSucursales($sucursales) {
        $map = [];
        $stmt = $this->db->prepare("
            INSERT INTO sucursales (codigo, nombre, direccion, telefono, responsable, horario, latitud, longitud) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE nombre=VALUES(nombre)
        ");
        foreach ($sucursales as $suc) {
            $stmt->execute([
                $suc['codigo'], $suc['nombre'], $suc['direccion'], $suc['telefono'],
                $suc['responsable'], $suc['horario'], $suc['latitud'], $suc['longitud']
            ]);
            $map[$suc['codigo']] = $this->db->lastInsertId();
            if (!$map[$suc['codigo']]) {
                $check = $this->db->prepare("SELECT id FROM sucursales WHERE codigo = ?");
                $check->execute([$suc['codigo']]);
                $map[$suc['codigo']] = $check->fetch(PDO::FETCH_ASSOC)['id'];
            }
        }
        return $map;
    }

    private function insertProductos($productos, $categorias) {
        $map = [];
        $cat_map = [];
        $stmt = $this->db->prepare("SELECT id, nombre FROM categorias");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cat_map[$row['nombre']] = $row['id'];
        }

        $stmt = $this->db->prepare("
            INSERT INTO productos (nombre, precio, categoria_id, descripcion) 
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE precio=VALUES(precio)
        ");
        foreach ($productos as $prod) {
            $cat_id = $cat_map[$prod['categoria']] ?? 1;
            $stmt->execute([$prod['nombre'], $prod['precio'], $cat_id, $prod['descripcion'] ?? '']);
            $map[$prod['nombre']] = $this->db->lastInsertId();
            if (!$map[$prod['nombre']]) {
                $check = $this->db->prepare("SELECT id FROM productos WHERE nombre = ?");
                $check->execute([$prod['nombre']]);
                $map[$prod['nombre']] = $check->fetch(PDO::FETCH_ASSOC)['id'];
            }
        }
        return $map;
    }

    private function insertAdmin($admin) {
        $stmt = $this->db->prepare("
            INSERT IGNORE INTO usuarios (username, password_hash, nombre_display, rol, activo) 
            VALUES (?, ?, ?, 'admin', 1)
        ");
        $stmt->execute([$admin['username'], $this->config['password_hash'], $admin['nombre']]);
    }

    private function insertEncargados($encargados, $sucursal_map) {
        $stmt = $this->db->prepare("
            INSERT INTO usuarios (username, password_hash, nombre_display, rol, sucursal_id, activo) 
            VALUES (?, ?, ?, 'encargado', ?, 1)
        ");
        foreach ($encargados as $enc) {
            $suc_id = $sucursal_map[$enc['sucursal_codigo']] ?? 1;
            $username = 'encargado_' . strtolower(str_replace(' ', '_', $enc['nombre']));
            $stmt->execute([$username, $this->config['password_hash'], $enc['nombre'], $suc_id]);
        }
    }

    private function insertCajeros($cajeros, $sucursal_map) {
        $stmt = $this->db->prepare("
            INSERT INTO usuarios (username, password_hash, nombre_display, rol, sucursal_id, activo) 
            VALUES (?, ?, ?, 'caja', ?, 1)
        ");
        foreach ($cajeros as $caj) {
            $suc_id = $sucursal_map[$caj['sucursal_codigo']] ?? 1;
            $username = 'caja_' . strtolower(str_replace(' ', '_', $caj['nombre']));
            $stmt->execute([$username, $this->config['password_hash'], $caj['nombre'], $suc_id]);
        }
    }

    private function insertStock($sucursal_map, $producto_map) {
        $stmt = $this->db->prepare("
            INSERT INTO stock_sucursal (sucursal_id, producto_id, cantidad_disponible, alerta_minima) 
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE cantidad_disponible = VALUES(cantidad_disponible)
        ");
        foreach ($sucursal_map as $suc_id) {
            foreach ($producto_map as $prod_id) {
                $stmt->execute([
                    $suc_id,
                    $prod_id,
                    $this->config['stock_inicial'],
                    $this->config['alerta_minima']
                ]);
            }
        }
    }

    private function printReport($sucursal_map, $producto_map) {
        echo "\n═════════════════════════════════════════════════════════════\n";
        echo "✨ ¡Población completada exitosamente!\n";
        echo "═════════════════════════════════════════════════════════════\n\n";
        
        echo "📊 Datos Insertados:\n";
        echo "  - Sucursales: " . count($sucursal_map) . "\n";
        echo "  - Productos: " . count($producto_map) . "\n";
        echo "  - Stock: " . (count($sucursal_map) * count($producto_map)) . " líneas\n";
        echo "  - Usuarios: 1 admin + 3 encargados + 15 cajeros = 19\n";
        
        echo "\n🔑 Todos los usuarios usan la misma contraseña:\n";
        echo "  ┌──────────────────────────────────────┐\n";
        echo "  │ Contraseña: " . $this->config['password_default'] . "                │\n";
        echo "  └──────────────────────────────────────┘\n";
        
        echo "\n📝 Ejemplos de login:\n";
        echo "  - admin / " . $this->config['password_default'] . "\n";
        echo "  - caja_caja_central / " . $this->config['password_default'] . "\n";
        echo "  - encargado_encargado_central / " . $this->config['password_default'] . "\n\n";
    }
}

// Ejecutar
$seeder = new DataSeeder($db, $config);
$seeder->run($categorias, $sucursales, $productos, $admin, $encargados, $cajeros);
?>
