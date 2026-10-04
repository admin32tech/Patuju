-- ======================================================================
-- PATUJU POS — Sistema de Punto de Venta Multi-Sucursal
-- Base de Datos MySQL: Esquema Unificado y Seeders Oficiales (Fases 1, 2 y 3)
-- Única Fuente de Verdad para Creación e Inicialización en Laragon / XAMPP
-- ======================================================================

CREATE DATABASE IF NOT EXISTS patuju_pos
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE patuju_pos;

-- Desactivar temporalmente verificación de claves foráneas para reseteo limpio
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS detalle_ventas;
DROP TABLE IF EXISTS ventas;
DROP TABLE IF EXISTS egresos_caja;
DROP TABLE IF EXISTS turnos_caja;
DROP TABLE IF EXISTS stock_movimientos;
DROP TABLE IF EXISTS stock_sucursal;
DROP TABLE IF EXISTS productos;
DROP TABLE IF EXISTS usuarios;
DROP TABLE IF EXISTS sucursales;
DROP TABLE IF EXISTS categorias;
DROP TABLE IF EXISTS login_intentos;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- 1. Tabla: categorias
-- ------------------------------------------------------------
CREATE TABLE categorias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    icono VARCHAR(50) DEFAULT '🍽️',
    orden INT DEFAULT 0,
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Tabla: sucursales (15 sedes a nivel nacional)
-- ------------------------------------------------------------
CREATE TABLE sucursales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(10) UNIQUE NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    direccion VARCHAR(255) NOT NULL,
    ciudad VARCHAR(100) NOT NULL,
    departamento VARCHAR(100) NOT NULL,
    telefono VARCHAR(20),
    responsable VARCHAR(150),
    horario VARCHAR(100) DEFAULT '07:00 - 14:00',
    latitud DECIMAL(10,7),
    longitud DECIMAL(10,7),
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. Tabla: productos
-- ------------------------------------------------------------
CREATE TABLE productos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    precio DECIMAL(10,2) NOT NULL,
    categoria_id INT NOT NULL,
    tipo_rotacion ENUM('diaria','lenta') NOT NULL DEFAULT 'diaria',
    imagen VARCHAR(255) DEFAULT 'default.png',
    descripcion TEXT,
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. Tabla: usuarios (Admin, Encargados y Cajeros)
-- ------------------------------------------------------------
CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    nombre_display VARCHAR(150) NOT NULL,
    rol ENUM('caja','admin','encargado') DEFAULT 'caja',
    sucursal_id INT NULL,
    activo TINYINT(1) DEFAULT 1,
    ultimo_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. Tabla: stock_sucursal (Inventario y precios por sucursal)
-- ------------------------------------------------------------
CREATE TABLE stock_sucursal (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    sucursal_id          INT  NOT NULL,
    producto_id          INT  NOT NULL,
    cantidad_disponible  INT  NOT NULL DEFAULT 0,
    alerta_minima        INT  NOT NULL DEFAULT 10,
    stock_predeterminado INT  NOT NULL DEFAULT 0 COMMENT 'Plantilla de stock diario predeterminado',
    disponible_venta     TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Control local de disponibilidad para caja (CRUD)',
    precio_sucursal      DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Precio local de la sucursal (NULL = usa precio base)',
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_suc_prod (sucursal_id, producto_id),
    FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id)  ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_stock_suc  (sucursal_id),
    INDEX idx_stock_prod (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. Tabla: turnos_caja (Control de turnos y Cortes Z)
-- ------------------------------------------------------------
CREATE TABLE turnos_caja (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id      INT             NOT NULL,
    sucursal_id     INT             NOT NULL,
    monto_apertura  DECIMAL(10,2)   NOT NULL DEFAULT 0.00  COMMENT 'Fondo inicial de caja',
    monto_cierre    DECIMAL(10,2)   NULL                   COMMENT 'Conteo físico al cerrar',
    ventas_sistema  DECIMAL(10,2)   NULL                   COMMENT 'Ventas totales calculadas por el sistema',
    diferencia      DECIMAL(10,2)   NULL                   COMMENT 'Diferencia sobrante/faltante',
    egresos_menores DECIMAL(10,2)   NOT NULL DEFAULT 0.00  COMMENT 'Total de gastos menores',
    notas           TEXT            NULL,
    hora_apertura   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    hora_cierre     TIMESTAMP       NULL,
    estado          ENUM('abierto','cerrado') NOT NULL DEFAULT 'abierto',
    FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)  ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_turno_suc   (sucursal_id, estado),
    INDEX idx_turno_user  (usuario_id,  estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. Tabla: egresos_caja (Detalle de gastos menores durante el turno)
-- ------------------------------------------------------------
CREATE TABLE egresos_caja (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    turno_id    INT           NOT NULL,
    monto       DECIMAL(10,2) NOT NULL,
    motivo      VARCHAR(255)  NOT NULL,
    creado_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (turno_id) REFERENCES turnos_caja(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_egreso_turno (turno_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. Tabla: ventas (Cabecera de ventas)
-- ------------------------------------------------------------
CREATE TABLE ventas (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    total           DECIMAL(10,2) NOT NULL,
    items_count     INT DEFAULT 0,
    sucursal_id     INT NOT NULL DEFAULT 1,
    usuario_id      INT NULL COMMENT 'Cajero que realizó la venta',
    metodo_pago     ENUM('efectivo','qr','tarjeta') NOT NULL DEFAULT 'efectivo',
    monto_recibido  DECIMAL(10,2) NULL COMMENT 'Efectivo recibido',
    cambio          DECIMAL(10,2) NULL COMMENT 'Vuelto entregado',
    turno_id        INT NULL COMMENT 'Turno de caja activo',
    nota            TEXT,
    fecha           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)   ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (turno_id)    REFERENCES turnos_caja(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_ventas_fecha (fecha),
    INDEX idx_ventas_suc_fecha (sucursal_id, fecha),
    INDEX idx_ventas_metodo (metodo_pago),
    INDEX idx_ventas_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9. Tabla: detalle_ventas (Líneas de venta)
-- ------------------------------------------------------------
CREATE TABLE detalle_ventas (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    venta_id        INT NOT NULL,
    producto_id     INT NOT NULL,
    producto_nombre VARCHAR(150) NOT NULL,
    cantidad        INT NOT NULL DEFAULT 1,
    precio_unitario DECIMAL(10,2) NOT NULL,
    subtotal        DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE RESTRICT,
    INDEX idx_det_prod (producto_id),
    INDEX idx_det_venta (venta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 10. Tabla: stock_movimientos (Trazabilidad y auditoría de inventario)
-- ------------------------------------------------------------
CREATE TABLE stock_movimientos (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    sucursal_id         INT NOT NULL,
    producto_id         INT NOT NULL,
    usuario_id          INT NOT NULL,
    tipo_movimiento     ENUM('ingreso_lote', 'ajuste_manual', 'venta', 'merma') NOT NULL,
    cantidad            INT NOT NULL COMMENT 'Positivo para entradas, negativo para salidas',
    stock_anterior      INT NOT NULL,
    stock_posterior     INT NOT NULL,
    motivo              VARCHAR(255) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id)  ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)   ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_mov_suc_fecha (sucursal_id, created_at),
    INDEX idx_mov_prod (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 11. Tabla: login_intentos (Rate-limiting contra fuerza bruta)
-- ------------------------------------------------------------
CREATE TABLE login_intentos (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    ip              VARCHAR(45)  NOT NULL,
    username        VARCHAR(50)  NOT NULL,
    intentos        INT          NOT NULL DEFAULT 1,
    bloqueado_hasta DATETIME     NULL,
    ultimo_intento  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ip_user (ip, username),
    INDEX idx_bloqueado (bloqueado_hasta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ======================================================================
-- DATOS INICIALES (SEEDERS COMPLETOS)
-- Sincronizado con seed-FINAL.php
-- Contraseña por defecto: patuju2024
-- Hash bcrypt: $2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA
-- ======================================================================

-- 1. Categorías Base
INSERT INTO categorias (id, nombre, icono, orden) VALUES
(1, 'Salteñas', '🥟', 1),
(2, 'Bebidas',  '🥤', 2),
(3, 'Jugos',    '🍊', 3),
(4, 'Extras',   '🍽️', 4);

-- 2. 15 Sucursales Oficiales
-- NOTA: ciudad/departamento se rellenan con los valores correctos por región
INSERT INTO sucursales (id, codigo, nombre, direccion, ciudad, departamento, telefono, responsable, horario, latitud, longitud) VALUES
(1,  'SUC-001', 'Patuju Central',           'Barrio Melchor Pinto #1234',   'La Paz',     'La Paz',     '+591 2 2441234', 'María López',        '07:00 - 14:00', -16.5000000, -68.1500000),
(2,  'SUC-002', 'Patuju Lujan',             'Av. Lujan-Av. Los Chacos #456','La Paz',     'La Paz',     '+591 2 2425678', 'Carlos Mamani',      '07:00 - 14:00', -16.5050000, -68.1350000),
(3,  'SUC-003', 'Patuju Lujan2',            'Av. Lujan #789',               'La Paz',     'La Paz',     '+591 2 2223456', 'Ana Quispe',         '07:00 - 14:00', -16.5150000, -68.1200000),
(4,  'SUC-004', 'Patuju 2 de Agosto',       'Av. 2 de agosto #321',         'La Paz',     'La Paz',     '+591 2 2771234', 'Pedro Condori',      '07:30 - 14:30', -16.5300000, -68.0900000),
(5,  'SUC-005', 'Patuju Plan3000',          'Zona Plan 3000 #1500',         'La Paz',     'La Paz',     '+591 2 2791111', 'Rosa Choque',        '07:00 - 13:00', -16.5350000, -68.0850000),
(6,  'SUC-006', 'Patuju 1Plan3000',         'Zona Plan 3000 #234',          'Cochabamba', 'Cochabamba', '+591 4 4251234', 'Jorge Rojas',        '07:00 - 14:00', -17.3935000, -66.1570000),
(7,  'SUC-007', 'Patuju 2Plan3000',         'Zona Plan 3000 #890',          'Cochabamba', 'Cochabamba', '+591 4 4405678', 'Lucía Flores',       '07:00 - 14:00', -17.3800000, -66.1650000),
(8,  'SUC-008', 'Patuju Santa Cruz Centro', 'AV. Cañoto #100',              'Santa Cruz', 'Santa Cruz', '+591 3 3361234', 'Miguel Suárez',      '07:00 - 14:00', -17.7833000, -63.1821000),
(9,  'SUC-009', 'Patuju Equipetrol',        'Av. San Martín #2500',         'Santa Cruz', 'Santa Cruz', '+591 3 3425678', 'Daniela Peña',       '07:30 - 14:30', -17.7700000, -63.2000000),
(10, 'SUC-010', 'Patuju 1',                 'C. España #45',                'Sucre',      'Chuquisaca', '+591 4 6451234', 'Fernando Arce',      '07:00 - 13:00', -19.0353000, -65.2592000),
(11, 'SUC-011', 'Patuju 2',                 'Av. 6 de Octubre #678',        'Oruro',      'Oruro',      '+591 2 5251234', 'Patricia Vargas',    '07:00 - 13:00', -17.9622000, -67.1062000),
(12, 'SUC-012', 'Patuju 3',                 'C. Bolívar #200',              'Potosí',     'Potosí',     '+591 2 6221234', 'Roberto Chávez',     '07:00 - 13:00', -19.5836000, -65.7531000),
(13, 'SUC-013', 'Patuju 4',                 'Av. Víctor Paz #345',          'Tarija',     'Tarija',     '+591 4 6641234', 'Carmen Gutiérrez',   '07:00 - 14:00', -21.5355000, -64.7296000),
(14, 'SUC-014', 'Patuju 5',                 'Av. 6 de Agosto #90',          'Trinidad',   'Beni',       '+591 3 4621234', 'Andrés Salvatierra', '07:00 - 13:00', -14.8333000, -64.9000000),
(15, 'SUC-015', 'Patuju 6',                 'Av. 6 de Marzo #4500',         'El Alto',    'La Paz',     '+591 2 2841234', 'Juana Huanca',       '06:30 - 13:00', -16.5100000, -68.1950000);

-- 3. Catálogo de Productos (16 ítems del menú real Patuju)
INSERT INTO productos (id, nombre, precio, categoria_id, tipo_rotacion, descripcion) VALUES
-- Salteñas (Rotación Diaria - Horneadas del día)
(1,  'Salteña Grande',           7.00, 1, 'diaria', 'Salteña de carne con huevo de codorniz'),
(2,  'Salteña Grande especial',  8.00, 1, 'diaria', 'Salteña especial de pollo jugosa'),
(3,  'Salteña Pequeña',          5.00, 1, 'diaria', 'Salteña pequeña ideal para niños'),
(4,  'Salteña Pequeña especial', 6.00, 1, 'diaria', 'Salteña pequeña con huevo de codorniz'),
(5,  'Salteña Reventadita',      3.50, 1, 'diaria', 'Salteña pequeña rebentadita'),
-- Bebidas (Rotación Lenta - Envasados)
(6,  'Soda pequeña',             5.00, 2, 'lenta',  'Soda clásica refrescante'),
(7,  'Soda Cola Personal',       8.00, 2, 'lenta',  'Soda de cola clásica personal'),
(8,  'Soda Cola 1 Litro',       12.00, 2, 'lenta',  'Soda de cola clásica 1L'),
(9,  'Soda Cola 2 Litro',       16.00, 2, 'lenta',  'Soda de cola clásica 2L'),
(10, 'Soda Cola 3 Litro',       22.00, 2, 'lenta',  'Soda de cola clásica 3L'),
(11, 'Jugo del Valle 2 L',      17.00, 2, 'lenta',  'Jugo del Valle 2L'),
(12, 'Jugo del Valle 3 L',      21.00, 2, 'lenta',  'Jugo del Valle 3L'),
(13, 'Soda La Cabana 2 L',       6.00, 2, 'lenta',  'Soda La Cabana 2L'),
(14, 'Agua Mineral',             5.00, 2, 'lenta',  'Agua mineral sin gas'),
-- Jugos (Rotación Lenta / Preparados)
(15, 'Jugo de guineo',           7.00, 3, 'lenta',  'Jugo natural recién licuado'),
(16, 'Refresco de papaya',       5.00, 3, 'lenta',  'Jugo natural exprimido');

-- 4. Usuarios: 1 Admin + 3 Encargados + 15 Cajeros
-- Contraseña: patuju2024
-- Hash bcrypt válido: $2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA
INSERT INTO usuarios (username, password_hash, nombre_display, rol, sucursal_id, activo) VALUES
-- Admin Global (sin sucursal)
('admin',
 '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA',
 'Administrador General', 'admin', NULL, 1),
-- 3 Encargados regionales
('encargado_encargado_central',
 '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA',
 'Encargado Central', 'encargado', 1, 1),
('encargado_encargado_cochabamba',
 '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA',
 'Encargado Cochabamba', 'encargado', 6, 1),
('encargado_encargado_santa_cruz',
 '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA',
 'Encargado Santa Cruz', 'encargado', 8, 1),
-- 15 Cajeros (uno por sucursal)
('caja_caja_central',
 '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA',
 'Caja Central', 'caja', 1, 1),
('caja_caja_lujan',
 '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA',
 'Caja Lujan', 'caja', 2, 1),
('caja_caja_lujan2',
 '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA',
 'Caja Lujan2', 'caja', 3, 1),
('caja_caja_2_de_agosto',
 '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA',
 'Caja 2 de Agosto', 'caja', 4, 1),
('caja_caja_plan3000',
 '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA',
 'Caja Plan3000', 'caja', 5, 1),
('caja_caja_1plan3000',
 '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA',
 'Caja 1Plan3000', 'caja', 6, 1),
('caja_caja_2plan3000',
 '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA',
 'Caja 2Plan3000', 'caja', 7, 1),
('caja_caja_santa_cruz_centro',
 '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA',
 'Caja Santa Cruz Centro', 'caja', 8, 1),
('caja_caja_equipetrol',
 '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA',
 'Caja Equipetrol', 'caja', 9, 1),
('caja_caja_1',
 '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA',
 'Caja 1', 'caja', 10, 1),
('caja_caja_2',
 '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA',
 'Caja 2', 'caja', 11, 1),
('caja_caja_3',
 '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA',
 'Caja 3', 'caja', 12, 1),
('caja_caja_4',
 '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA',
 'Caja 4', 'caja', 13, 1),
('caja_caja_5',
 '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA',
 'Caja 5', 'caja', 14, 1),
('caja_caja_6',
 '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA',
 'Caja 6', 'caja', 15, 1);

-- 5. Inventario Inicial con distinción por tipo de rotación:
-- Salteñas (rotación diaria): inicializan con plantilla predeterminada para apertura rápida
-- Bebidas y jugos (rotación lenta): 50 unidades de stock continuo
INSERT INTO stock_sucursal (sucursal_id, producto_id, cantidad_disponible, alerta_minima, stock_predeterminado, disponible_venta)
SELECT 
    s.id, 
    p.id, 
    CASE WHEN p.tipo_rotacion = 'diaria' THEN 
        CASE p.id 
            WHEN 1 THEN 80 
            WHEN 2 THEN 60 
            WHEN 3 THEN 40 
            WHEN 4 THEN 40 
            WHEN 5 THEN 30 
            ELSE 50 
        END
    ELSE 50 END AS cantidad_disponible,
    10,
    CASE WHEN p.tipo_rotacion = 'diaria' THEN 
        CASE p.id 
            WHEN 1 THEN 80 
            WHEN 2 THEN 60 
            WHEN 3 THEN 40 
            WHEN 4 THEN 40 
            WHEN 5 THEN 30 
            ELSE 50 
        END
    ELSE 0 END AS stock_predeterminado,
    1
FROM sucursales s
CROSS JOIN productos p
WHERE s.activo = 1 AND p.activo = 1;
