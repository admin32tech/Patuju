# 🥟 PATUJU POS — Arquitectura Técnica

Documentación técnica integral de la arquitectura del sistema, modelo de datos, flujos operativos, endpoints API y mecanismos de seguridad.

---

## 🌐 Arquitectura General de 3 Nivel

El sistema se organiza en 3 capas de acceso jerárquicas:

```
                  ┌────────────────────────────────────────┐
                  │       LANDING PAGE (Pública)           │
                  │             landing.php                │
                  └──────────────────┬─────────────────────┘
                                     │
                             (Botón "Ingresar")
                                     │
                  ┌──────────────────▼─────────────────────┐
                  │           LOGIN SEGURO                 │
                  │              login.php                 │
                  └───────────┬───────────┬────────────────┘
                              │           │
                     (Rol: "caja")     (Rol: "encargado" / "admin")
                              │           │
         ┌────────────────────▼─────┐ ┌───▼────────────────────────┐
         │     CAJA POS (Mostrador) │ │ PANEL ENCARGADO / ADMIN    │
         │           index.php      │ │ encargado.php / admin.php  │
         │ (Aislado por sucursal)   │ │ (Gestión local y global)   │
         └──────────────────────────┘ └────────────────────────────┘
```

### Componentes de Software

| Componente | Archivo(s) | Función / Permiso |
|------------|------------|-------------------|
| **Landing Page** | `landing.php`, `assets/css/landing.css` | Portal público con historia, menú visual e información de 15 sucursales. |
| **Login** | `login.php`, `ajax/login.php` | Autenticación con hash bcrypt, auto-rehash y rate limiting. |
| **Caja POS** | `index.php`, `assets/js/app.js` | Rol `caja`: Cobro rápido, turnos, stock de sucursal e impresión térmica. |
| **Panel Encargado** | `encargado.php`, `assets/js/encargado.js` | Rol `encargado`: Ingreso de lotes, precios por sucursal y alertas. |
| **Panel Admin** | `admin.php`, `assets/js/admin.js` | Rol `admin`: CRUD global de productos, stock consolidado y auditorías Z. |
| **Logout** | `logout.php` | Cierre seguro de sesión y destrucción de cookies. |
| **Seguridad / Auth** | `config/auth.php` | Helpers `verificarSesion()`, `verificarSesionAjax()`, `obtenerSucursalActual()`. |
| **Base de datos** | `config/database.php` | Conexión PDO Singleton con soporte para variables de entorno (`.env`). |

---

## 🗄️ Modelo de Datos (Diagrama Entidad-Relación)

```
 ┌──────────────┐     ┌──────────────────┐     ┌──────────────────┐
 │  categorias  │     │    productos     │     │   sucursales     │
 ├──────────────┤     ├──────────────────┤     ├──────────────────┤
 │ id (PK)      │◄────│ categoria_id (FK)│     │ id (PK)          │
 │ nombre       │     │ id (PK)          │     │ codigo (UNIQUE)  │
 │ icono        │     │ nombre           │     │ nombre           │
 │ orden        │     │ precio           │     │ direccion        │
 │ activo       │     │ imagen           │     │ ciudad           │
 └──────────────┘     │ descripcion      │     │ departamento     │
                      │ activo           │     │ telefono         │
                      └────────┬─────────┘     │ responsable      │
                               │               └────────┬─────────┘
                               │                        │
                      ┌────────▼─────────┐              │
                      │  stock_sucursal  │              │
                      ├──────────────────┤              │
                      │ id (PK)          │              │
                      │ sucursal_id (FK) │◄─────────────┤
                      │ producto_id (FK) │              │
                      │ cantidad         │              │
                      │ alerta_minima    │              │
                      │ precio_local     │              │
                      └──────────────────┘              │
                                                        │
 ┌──────────────┐     ┌──────────────────┐              │
 │   usuarios   │     │     ventas       │              │
 ├──────────────┤     ├──────────────────┤              │
 │ id (PK)      │     │ id (PK)          │              │
 │ username     │     │ total            │              │
 │ password_hash│     │ items_count      │◄─────────────┤
 │ nombre_display│    │ sucursal_id (FK) │    (FK sucursales)
 │ rol (ENUM)   │──►  │ usuario_id (FK)  │
 │ sucursal_id  │     │ nota, fecha      │
 └──────────────┘     └────────┬─────────┘
                               │
                      ┌────────▼─────────┐
                      │ detalle_ventas   │
                      ├──────────────────┤
                      │ id (PK)          │
                      │ venta_id (FK)    │ (ON DELETE CASCADE)
                      │ producto_id (FK) │
                      │ cantidad         │
                      │ precio_unitario  │
                      │ subtotal         │
                      └──────────────────┘
```

---

## 🔌 Endpoints API (AJAX)

| Método | Endpoint | Permiso Requerido | Descripción |
|--------|----------|:-----------------:|-------------|
| `POST` | `ajax/login.php` | Público ❌ | Autenticación con verificación bcrypt y rate-limiting. |
| `GET` | `ajax/productos.php` | Sesión activa ✅ | Productos activos con stock y precio de la sucursal del usuario. |
| `POST` | `ajax/registrar_venta.php` | Rol `caja` / `admin` ✅ | Registro atómico de venta y descuento de stock. |
| `GET` | `ajax/historial.php` | Sesión activa ✅ | Historial de ventas (filtrado por sucursal para cajeros). |
| `GET/POST` | `ajax/turno_caja.php` | Rol `caja` / `admin` ✅ | Control de apertura y cierre de turno de caja (Corte Z). |
| `GET/POST` | `ajax/stock.php` | Rol `encargado` / `admin` ✅ | Ajustes de stock, lotes, precios por sucursal y movimientos. |
| `GET/POST/PUT/DELETE` | `ajax/crud_productos.php` | Solo `admin` 🔒 | Administración global del catálogo de productos. |
| `GET` | `ajax/analytics.php` | Solo `admin` 🔒 | Dashboard de analítica gerencial (Chart.js) y exportación CSV. |

---

## 🛡️ Mecanismos de Seguridad Implementados

1. **Hashing de Contraseñas:** Algoritmo `bcrypt` con actualización dinámica (auto-rehash) al iniciar sesión.
2. **Protección de Sesiones:** Regresión de sesión mediante `session_regenerate_id(true)` tras el login y cookies `HttpOnly`, `SameSite=Strict`.
3. **Variables de Entorno:** Credenciales de MySQL almacenadas en `.env` (fuera del repositorio git).
4. **Protección XSS:** Escapado riguroso con `escapeHtml()` en JS y `htmlspecialchars()` en PHP.
5. **SQL Injection:** Prepared Statements con PDO en el 100% de las consultas a la base de datos.
6. **Protección contra Fuerza Bruta:** Registro de intentos en `login_intentos` (bloqueo automático de 15 minutos tras 10 fallos).
