# 🥟 Patuju POS — Sistema de Punto de Venta Multi-Sucursal

Sistema web de Punto de Venta (POS) e inventario multi-sucursal desarrollado con **PHP (Vanilla)**, **MySQL / MariaDB**, **JavaScript (Vanilla)** y **CSS puro**. Diseñado para optimizar las operaciones de horneada diaria, rotación de stock, registro de ventas y arqueos de caja en tiempo real.

---

## 🚀 Requisitos del Sistema

- **Servidor Web:** Apache (Laragon, XAMPP o servidor Linux).
- **PHP:** Versión 8.1 o superior (con extensiones `pdo`, `pdo_mysql`, `mbstring`). La suite de pruebas requiere PHP 8.2+.
- **Base de Datos:** MySQL 5.7+ / 8.0+ o MariaDB 10.4+.
- **Navegador:** Cualquier navegador moderno compatible con estándares web (Chrome, Firefox, Edge, Safari).

---

## 📂 Estructura del Proyecto

El repositorio está estructurado para ejecutarse directamente en la raíz de tu servidor web:

```text
patuju/
├── .env.example          # Plantilla de variables de entorno (BD)
├── .gitignore            # Reglas de exclusión para Git (ignora .env, logs, etc.)
├── .htaccess             # Reglas de seguridad y encabezados para Apache
├── README.md             # Esta documentación
│
├── index.php             # Punto de venta (POS) para cajeros
├── login.php             # Formulario de autenticación con rate-limiting
├── logout.php            # Cierre seguro de sesión
├── admin.php             # Panel de administración global (reportes, productos, usuarios)
├── encargado.php         # Panel de encargado de sucursal (apertura diaria, stock)
├── landing.php           # Landing page pública institucional
├── actualizar_bd.php     # 🛠️ Script preventivo de diagnóstico y migración de BD
│
├── ajax/                 # Endpoints y controladores de la API interna
│   ├── admin_sync.php
│   ├── analytics.php
│   ├── crud_productos.php
│   ├── historial.php
│   ├── login.php
│   ├── productos.php
│   ├── registrar_venta.php
│   ├── stock.php
│   ├── turno_caja.php
│   └── usuarios.php      # Gestión de usuarios y sucursales asignadas (solo admin)
│
├── assets/               # Recursos estáticos
│   ├── css/              # Estilos visuales y diseño responsive
│   ├── js/               # Lógica del cliente (app.js, admin.js, etc.)
│   └── img/              # Imágenes y gráficos de productos
│
├── config/               # Configuración central
│   ├── database.php      # Conexión PDO y carga de variables de entorno
│   └── auth.php          # Control de sesiones y verificación de roles
│
├── database/             # Definición y semillas de la base de datos
│   ├── schema.sql        # Esquema unificado (12 tablas) + datos iniciales oficiales
│   └── init_usuarios.php # Semilla de usuarios para instalaciones nuevas (hashes Bcrypt)
│
├── tests/                # Suite de integración PHPUnit (ver "Pruebas")
├── composer.json         # Dependencias de desarrollo (PHPUnit)
├── phpunit.xml           # Configuración de la suite
│
└── docs/                 # Documentación técnica, arquitectura y prevención
    ├── 1user.md                    # Fase 2.5: usuarios y multi-sucursal para encargados
    ├── CORRECCIONES-PENDIENTES.md  # ⚠️ Bitácora de prevención y resolución de bugs históricos
    ├── ARQUITECTURA.md             # Especificación técnica del sistema
    ├── GUIA_DESPLIEGUE_Y_PRUEBAS.md# Pruebas y despliegue
    ├── ROADMAP.md                  # Plan de evolución por fases
    └── db/                         # Análisis de arquitectura de datos (ANALISIS-SEED, etc.)
```

---

## ⚙️ Guía de Instalación Rápida

### 1. Ubicación en el servidor local
Clona o copia la carpeta del proyecto directamente dentro de la carpeta pública de tu entorno:
- **Laragon:** `C:\laragon\www\patuju`
- **XAMPP:** `C:\xampp\htdocs\patuju`

Al hacer esto, podrás acceder directamente desde: `http://localhost/patuju`

### 2. Configuración de credenciales (.env)
Copia el archivo de plantilla `.env.example` y renómbralo a `.env`:
```bash
cp .env.example .env
```
Edita `.env` con los datos de tu conexión local a MySQL:
```ini
DB_HOST=localhost
DB_NAME=patuju_pos
DB_USER=root
DB_PASS=
DB_CHARSET=utf8mb4
```

### 3. Crear e importar la Base de Datos
Importa el archivo [`database/schema.sql`](database/schema.sql) mediante phpMyAdmin o desde la consola:
```bash
mysql -u root -p < database/schema.sql
```
> *Este esquema crea automáticamente la base de datos `patuju_pos`, las 12 tablas del sistema y siembra las 4 categorías, las 15 sucursales oficiales (más la sucursal interna `SUC-000` del admin), los 16 productos con sus tipos de rotación y el stock correspondiente.*

### 4. Inicializar contraseñas de usuarios (solo instalación nueva)
Para asegurar que los hashes de contraseña sean 100% compatibles con la función nativa `password_hash()` de PHP en tu entorno:
- **Opción A (Navegador):** Abre `http://localhost/patuju/database/init_usuarios.php`.
- **Opción B (Terminal):** Ejecuta `php database/init_usuarios.php`.

> ⚠️ Este script restablece las claves de todos los usuarios semilla a `patuju2024`. **No lo ejecutes sobre una BD en uso**; para actualizar una BD existente usa [`actualizar_bd.php`](actualizar_bd.php) (ver sección "Herramienta Preventiva" más abajo).

---

## 🔑 Credenciales de Acceso por Defecto

Todas las cuentas se inicializan con la contraseña: **`patuju2024`**

| Rol | Usuario | Sucursal Asignada |
|---|---|---|
| **Administrador General** | `admin` | Todas (Control Global) |
| **Encargado Regional** | `encargado_encargado_central` | SUC-001 (Patuju Central) |
| **Encargado Regional** | `encargado_encargado_cochabamba` | SUC-006 (Cochabamba) |
| **Encargado Regional** | `encargado_encargado_santa_cruz` | SUC-008 (Santa Cruz) |
| **Cajero** | `caja_caja_central` | SUC-001 (Patuju Central) |
| **Cajero** | `caja_caja_lujan` | SUC-002 (Patuju Lujan) |
| *(y 13 cajeros más por cada sucursal)* | `caja_caja_...` | Sucursales 3 a 15 |

> Además de su sucursal principal, cada encargado supervisa las sucursales que el admin le asigna desde la pestaña "Gestión de usuarios" (tabla `usuario_sucursales`). Detalle en [`docs/1user.md`](docs/1user.md).

---

## 🛡️ Herramienta Preventiva: `actualizar_bd.php`

El archivo [`actualizar_bd.php`](actualizar_bd.php) funciona como herramienta de diagnóstico y prevención en caso de inconsistencias en la base de datos (por ejemplo, tras actualizar el código o clonar el proyecto):

> ⚠️ **Al actualizar el código sobre una BD existente, ejecuta `actualizar_bd.php`.** Es el paso de migración: entre otras cosas crea la tabla `usuario_sucursales` (sucursales asignadas a encargados), sin la cual el encargado solo accede a su sucursal principal y la pestaña Usuarios del admin responde "Falta migrar la base de datos". También agrega `es_interna` y la sucursal SUC-000, que necesita `admin.php`. No uses `init_usuarios.php` para esto.

- Puedes ejecutarlo desde el navegador en cualquier momento: `http://localhost/patuju/actualizar_bd.php`
- **¿Qué valida automáticamente sin borrar datos?**
  1. Verifica si la tabla `ventas` tiene la columna `usuario_id` y su clave foránea correspondiente (la agrega si falta).
  2. Verifica el hash de la contraseña del usuario `admin`: solo si no es un Bcrypt válido lo restablece a `patuju2024`. Una clave cambiada por el admin no se toca.
  3. Agrega la columna `sucursales.es_interna`, crea la sucursal interna `SUC-000` con su stock y se la asigna a los usuarios `admin`.
  4. Crea la tabla `usuario_sucursales` y, solo si no existía, siembra las asignaciones iniciales de los encargados.
- ⚠️ El script no exige autenticación. En un servidor accesible desde la red, protégelo o bloquéalo después de migrar (pendiente registrado en [`docs/estado_en_desarrollo.md`](docs/estado_en_desarrollo.md)).

---

## 🧪 Pruebas

Suite de integración con PHPUnit en `tests/Integration`: levanta el servidor embebido de PHP y prueba vistas y endpoints contra una BD aislada que se borra y recrea en cada ejecución desde `database/schema.sql` + `database/init_usuarios.php`.

Requisitos: PHP 8.2+ (solo para la suite), Composer y MySQL encendido.

```bash
composer install
php vendor/bin/phpunit
```

En XAMPP, si `php` no está en el PATH: `C:\xampp\php\php.exe vendor/bin/phpunit`.

- **BD de pruebas:** se configura en `tests/.env.testing` (por defecto `patuju_pos_test`). Su nombre debe terminar en `_test`, porque la suite la borra y la recrea. Nunca apuntes la suite a `patuju_pos`.
- **Variables de entorno:** `PATUJU_DB_HOST`, `PATUJU_DB_NAME`, `PATUJU_DB_USER`, `PATUJU_DB_PASS` y `PATUJU_DB_CHARSET` tienen prioridad sobre el archivo `.env`; las respetan tanto la app como la suite (útil en CI).
- **`PATUJU_ENV_FILE`:** apunta a un archivo `.env` alterno (`phpunit.xml` lo fija en `tests/.env.testing`).

---

## 📚 Documentación Técnica y Prevención de Errores

Para consultar decisiones de arquitectura y evitar volver a cometer errores detectados en fases anteriores:
- **Estado actual del proyecto:** [`docs/estado_en_desarrollo.md`](docs/estado_en_desarrollo.md)
- **Usuarios y multi-sucursal (Fase 2.5):** [`docs/1user.md`](docs/1user.md)
- **Bitácora y lecciones aprendidas:** [`docs/CORRECCIONES-PENDIENTES.md`](docs/CORRECCIONES-PENDIENTES.md)
- **Arquitectura del sistema:** [`docs/ARQUITECTURA.md`](docs/ARQUITECTURA.md)
- **Análisis de Seeds vs SQL:** [`docs/db/ANALISIS-SEED.md`](docs/db/ANALISIS-SEED.md)
- **Guía de despliegue:** [`docs/GUIA_DESPLIEGUE_Y_PRUEBAS.md`](docs/GUIA_DESPLIEGUE_Y_PRUEBAS.md)