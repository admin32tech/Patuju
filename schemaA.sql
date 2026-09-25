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
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    sucursal_id         INT  NOT NULL,
    producto_id         INT  NOT NULL,
    cantidad_disponible INT  NOT NULL DEFAULT 0,
    alerta_minima       INT  NOT NULL DEFAULT 10,
    precio_sucursal     DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Precio local de la sucursal (NULL = usa precio base)',
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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

