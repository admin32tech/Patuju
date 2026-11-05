# Documentación Técnica — Fase 2.5: Sistema de Usuarios y Multi-Sucursal para Encargados (1user)

**Fecha:** 4 de Noviembre de 2026  
**Estado:** ✅ Implementado, verificado y documentado (revisado tras la auditoría de integración del 2026-11-05; ver [estado_en_desarrollo.md](estado_en_desarrollo.md))  
**Objetivo:** Permitir que el Administrador gestione usuarios (creación, edición, reseteo de claves, activación) y asigne múltiples sucursales/cajas a cada Encargado (por ejemplo, 3 encargados supervisando las 15 cajas a nivel nacional).

---

## 1. Diagnóstico del Problema Previo

Antes de esta fase:
1. **Relación 1:1 rígida:** Cada usuario tenía una única columna `sucursal_id` en la tabla `usuarios`. Un encargado solo podía estar vinculado a una sola sede.
2. **12 sucursales huérfanas:** De las 15 sucursales oficiales, solo 3 tenían encargado asignado. Las otras 12 solo contaban con un cajero local y dependían del administrador general.
3. **Sin interfaz de usuarios:** No existía pestaña de usuarios ni endpoint AJAX en `admin.php`. Para crear o modificar usuarios era necesario ejecutar scripts manuales (`init_usuarios.php`) o manipular la base de datos por SQL.
4. **Filtros bloqueantes en backend:** En `ajax/stock.php` y `ajax/historial.php`, si el usuario no era `admin`, se forzaba la consulta únicamente al `sucursal_id` de la sesión activa, ignorando si el encargado intentaba supervisar otra de sus sedes.

---

## 2. Archivos Creados y Modificados

| # | Archivo | Tipo | Descripción del Cambio |
|---|---|---|---|
| 1 | [`database/schema.sql`](../database/schema.sql) | Esquema | Agregada tabla `usuario_sucursales` (N:M) y sentencia `DROP TABLE IF EXISTS usuario_sucursales`. |
| 2 | [`actualizar_bd.php`](../actualizar_bd.php) | Migración | **Paso de migración para BD existentes.** Idempotente (pasos 7 y 8): crea `usuario_sucursales` y siembra las asignaciones iniciales **solo si la tabla no existía**, y solo para usuarios con rol `encargado`. Ya no restablece la clave del `admin` salvo que su hash no sea un Bcrypt válido. |
| 3 | [`database/init_usuarios.php`](../database/init_usuarios.php) | Semilla | **Solo para instalaciones nuevas** (tras importar `schema.sql`): restablece las claves de los usuarios semilla a `patuju2024`, crea `usuario_sucursales` si falta y siembra asignaciones solo a encargados recién insertados o si la tabla se acaba de crear. No usarlo para migrar una BD en uso. |
| 4 | [`config/auth.php`](../config/auth.php) | Seguridad | Nuevas funciones `obtenerSucursalesAsignadas($usuarioId)` (encargado = principal + `usuario_sucursales`; caja = solo su principal; admin = todas las activas no internas) y `puede($accion, ...$args)`. `verificarSesion()`/`verificarSesionAjax()` releen rol, estado `activo` y sucursal desde la BD en cada petición: usuario inexistente o desactivado → sesión destruida (AJAX 401 `Sesión expirada`, vistas → `login.php`); un cambio de rol o de sucursal se refleja sin re-login (al admin no se le pisa la sucursal de sesión). |
| 5 | [`ajax/usuarios.php`](../ajax/usuarios.php) | **Nuevo** (API) | Endpoint CRUD exclusivo para rol `admin` con 6 acciones: `GET ?accion=listar` y `POST` `crear`, `editar`, `asignar_sucursales`, `reset_password`, `toggle_activo` (detalle en la sección 5). |
| 6 | [`ajax/stock.php`](../ajax/stock.php) | Backend | Soporte multi-sucursal en `resumen_sucursales` (muestra todas las asignadas al encargado) y validación vía `puede('editar_stock', $sucIdDestino)` en operaciones POST. En GET y POST, un `sucursal_id` que no es un entero positivo (`0`, `abc`, `1.5`) responde 403 para cualquier rol, y caja y encargado también reciben 403 si la sucursal no les corresponde (la caja solo la suya; el encargado, las asignadas). Las consultas sin filtro de sucursal (consolidado de todas y movimientos de todas) son solo para `admin`, que sin parámetro ve el consolidado. |
| 7 | [`ajax/historial.php`](../ajax/historial.php) | Backend | Caja y encargado solo ven las ventas **del día actual** (no se acumulan; los filtros de fecha se ignoran). Sin `sucursal_id`, la caja ve la suya y el encargado todas sus asignadas; con un `sucursal_id` permitido, solo esa; con uno manipulado (`0`, `abc`, ajeno), 403. El admin conserva el histórico completo, los filtros de fecha y `?solo_hoy=1`. |
| 8 | [`ajax/turno_caja.php`](../ajax/turno_caja.php) | Backend | El listado de turnos del encargado abarca todas sus sucursales asignadas; con `sucursal_id` filtra solo si es una de ellas. |
| 9 | [`ajax/admin_sync.php`](../ajax/admin_sync.php) | Backend | La marca de última venta para el polling del encargado considera todas sus sucursales asignadas (caja: solo la suya; admin: todas). |
| 10 | [`admin.php`](../admin.php) | Frontend | Nueva pestaña "Gestión de usuarios" en barra lateral, KPIs de usuarios, formulario CRUD, tabla con badges y modales interactivos para asignar sucursales y resetear claves. |
| 11 | [`assets/js/admin.js`](../assets/js/admin.js) | Frontend | Módulo `AdminUsuarios` con renderizado reactivo, validaciones, modal de checkboxes y sincronización en tiempo real. Integrado con `Admin.cambiarTab('usuarios')`. |
| 12 | [`encargado.php`](../encargado.php) | Frontend | Corrección del badge de usuario en cabecera: muestra dinámicamente `(Administrador)` o `(Encargado)`. |
| 13 | [`assets/js/app.js`](../assets/js/app.js) + [`assets/css/styles.css`](../assets/css/styles.css) | Frontend | Vista de caja: el total del día del topbar usa siempre la sucursal de la caja (`document.body.dataset.sucursalId`). El botón Historial muestra las "Ventas de hoy": al cajero y al admin, de esa caja; al encargado, de todas las cajas que tiene a cargo (sin `sucursal_id`), con columna de sucursal. Un 401 de cualquier endpoint (sesión invalidada) redirige a `login.php`. Sin turno abierto se marca `body.turno-bloqueado`: la barra superior queda por encima del modal de apertura y "Historial" se deshabilita. |

---

## 3. Modelo de Datos y Relación N:M

### Nueva Tabla: `usuario_sucursales`
```sql
CREATE TABLE usuario_sucursales (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT NOT NULL,
    sucursal_id INT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_suc (usuario_id, sucursal_id),
    FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)   ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_us_user (usuario_id),
    INDEX idx_us_suc  (sucursal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Arquitectura de Roles y Sucursales:
- **`admin`**: Acceso total y global. No requiere registros en `usuario_sucursales`. Opera en `SUC-000` (interna) por defecto.
- **`encargado`**:
  - `usuarios.sucursal_id`: Su **sede base principal**.
  - `usuario_sucursales`: Las **sucursales adicionales o grupo de cajas a su cargo**.
  - Al consultar el panel de encargado, se consultan ambas fuentes unificadas. La sede principal **siempre** da acceso, figure o no en `usuario_sucursales` (el backend la mantiene incluida al asignar o editar).
- **`caja`**:
  - `usuarios.sucursal_id`: Su **caja fija única**. No tiene sucursales adicionales.

### Distribución Semilla de los 3 Encargados sobre las 15 Sucursales:
| Encargado | Username | Sucursales Asignadas | Códigos |
|---|---|---|---|
| **Encargado Central** | `encargado_encargado_central` | SUC-001 a SUC-005 (5 sedes) | Central, Lujan, Lujan2, 2 de Agosto, Plan3000 |
| **Encargado Cochabamba** | `encargado_encargado_cochabamba` | SUC-006 a SUC-007 (2 sedes) | 1Plan3000, 2Plan3000 |
| **Encargado Santa Cruz** | `encargado_encargado_santa_cruz` | SUC-008 a SUC-015 (8 sedes) | Santa Cruz Centro, Equipetrol, Patuju 1 al 6 |

---

## 4. Sistema Granular de Capacidades: `puede()`

Ubicado en [`config/auth.php`](../config/auth.php):

```php
function puede(string $accion, ...$args): bool
```

Capacidades definidas:
- `'gestionar_usuarios'`: Solo `admin`.
- `'ver_auditoria'`, `'exportar_reportes'`: Solo `admin`.
- `'ver_sucursal', $sucursalId`: `admin` siempre `true`; `encargado` si `$sucursalId` está en su lista asignada (principal + `usuario_sucursales`); `caja` solo si es su sucursal de sesión (sincronizada con la BD en cada petición).
- `'editar_stock', $sucursalId`: `admin` siempre `true`; `encargado` si la sucursal está a su cargo; `caja` siempre `false`.
- `'vender_en_caja'`: `caja` y `encargado`.

---

## 5. Especificación de la API: `ajax/usuarios.php`

Todas las peticiones requieren sesión activa de Administrador (`verificarSesionAjax('admin')`).

### 1. `GET ?accion=listar`
- **Respuesta:**
  - `usuarios`: Lista de usuarios con rol, estado, sucursal principal y array `sucursales_asignadas` (con id, código y nombre).
  - `sucursales`: Catálogo de sucursales activas para poblar selectores y checklists.
  - `kpis`: Contadores de total, activos, administradores, encargados y cajeros.

### 2. `POST { accion: 'crear', username, password, nombre_display, rol, sucursal_id, sucursales_asignadas, activo }`
- Hasheo con `password_hash($password, PASSWORD_BCRYPT)`.
- Verificación de username único y formato seguro.
- `activo` es opcional (`0` | `1`, por defecto `1`).
- Si `rol !== 'admin'`, `sucursal_id` es obligatorio (400 `Debes asignar una sucursal principal para este rol`) y no puede ser una sucursal interna (`es_interna = 1`) → 400. Para `admin` la sucursal es opcional y puede ser la interna `SUC-000`.
- Transacción atómica que guarda en `usuarios` y en `usuario_sucursales` (si es encargado).

### 3. `POST { accion: 'editar', id, nombre_display, rol, sucursal_id, activo, sucursales_asignadas }`
- Protección: No permite al admin en sesión auto-desactivarse ni degradar su rol de administrador.
- Mismas reglas de sucursal que `crear`: obligatoria y no interna para roles distintos de `admin`.
- Si un encargado cambia de sucursal principal y no se envía `sucursales_asignadas`, se quita la fila N:M de la principal anterior y se inserta la nueva (`INSERT IGNORE`).
- Transacción atómica que actualiza la ficha del usuario y sincroniza `usuario_sucursales`.

### 4. `POST { accion: 'asignar_sucursales', usuario_id, sucursales_ids: [1, 2, 3...] }`
- Exclusivo para usuarios con rol `encargado`.
- Reemplaza atómicamente el grupo de sucursales supervisadas por el encargado.
- Rechaza sucursales internas (400).
- La sucursal principal (`usuarios.sucursal_id`) siempre conserva acceso: el backend la incluye automáticamente aunque no venga en la lista (no es un error). El mensaje de éxito cuenta el total efectivo, incluida la principal.

### 5. `POST { accion: 'reset_password', usuario_id, password }`
- `password` es obligatorio (mínimo 6 caracteres); sin él responde 400. Ya no existe clave predeterminada en el backend.
- Se genera hash Bcrypt nuevo.

### 6. `POST { accion: 'toggle_activo', usuario_id }`
- Alterna el estado activo (1 ↔ 0). Impide auto-bloqueo del administrador en sesión.
- Como la sesión se revalida contra la BD en cada petición, un usuario desactivado pierde su sesión en su siguiente petición.

---

## 6. Interfaz en Panel de Administración (`admin.php` + `admin.js`)

Se agregó la sexta sección al panel de administración:

1. **Botón en Navegación Lateral:**
   - Icono `user` + texto "Gestión de usuarios".
2. **Dashboard de Métricas (KPI Cards):**
   - Total de usuarios registrados.
   - Usuarios activos.
   - Total de Encargados (supervisores multi-sucursal).
   - Total de Cajeros POS.
3. **Barra de Búsqueda y Filtros:**
   - Filtro por Rol (Todos, Administrador, Encargado, Cajero).
   - Filtro de búsqueda en tiempo real por nombre, usuario o sucursal.
   - Botón de refresco manual y botón "Nuevo usuario".
4. **Formulario CRUD Reactivo:**
   - Alta de usuarios con validaciones de longitud y formato.
   - Selección dinámica de sucursal base y rol.
   - Modo edición con bloqueo de username y botón de cancelación.
5. **Tabla de Usuarios Detallada:**
   - Identificación con avatar e ID.
   - Badges distintivos por rol (Morado para Admin, Naranja para Encargado, Gris para Cajero).
   - Columna "Sucursales a cargo": Muestra la cantidad de sucursales supervisadas y botón directo `Asignar (N)`.
   - Badges de estado (Verde = Activo, Rojo = Inactivo).
   - Acciones por fila: Editar, Restablecer clave y Activar/Desactivar.
6. **Modal de Asignación de Sucursales:**
   - Despliega las 15 sucursales oficiales con código, nombre y dirección.
   - Botones rápidos: "Seleccionar todas" y "Deseleccionar todas".
   - Contador dinámico de sucursales marcadas.
   - La sucursal principal del encargado aparece fija (marcada y deshabilitada, con la nota "Principal: se cambia desde Editar"): siempre conserva acceso y se cambia desde Editar.
7. **Modal de Restablecimiento de Contraseña:**
   - Permite escribir una clave personalizada o usar los atajos del modal (incluido el generador de claves aleatorias seguras). La clave siempre se envía explícita: el backend ya no aplica una predeterminada.

---

## 7. Cómo Probar y Ejecutar los Cambios

1. **Ejecutar la migración de base de datos (BD existente):**
   - Abre en el navegador (con MySQL encendido): `http://localhost/Patuju/actualizar_bd.php`
   - Es el único paso de migración: crea `usuario_sucursales` y, si la tabla no existía, siembra las asignaciones de los encargados. No cambia la clave del `admin` salvo que su hash no sea un Bcrypt válido.
   - Si la tabla se acaba de crear, verás los mensajes verdes con las asignaciones automáticas de los 3 encargados; si ya existía, el script lo indica y no toca las asignaciones.
   - **No** uses `database/init_usuarios.php` para migrar una BD en uso: es para instalaciones nuevas (tras importar `schema.sql`) y restablece las claves de todos los usuarios semilla a `patuju2024`.

2. **Ingresar como Administrador:**
   - Login: `admin` / `patuju2024` (o la clave vigente, si ya fue cambiada).
   - Haz clic en la pestaña lateral **"Gestión de usuarios"**.
   - Verás a los 19 usuarios del sistema (1 admin, 3 encargados, 15 cajeros).
   - Observa cómo cada uno de los 3 encargados tiene asignadas sus respectivas sucursales (5, 2 y 8 sucursales).

3. **Probar la Asignación Multi-Sucursal:**
   - En la fila de `Encargado Central`, haz clic en el botón `Asignar (5)`.
   - Marca o desmarca cajas a tu gusto y presiona **"Guardar asignación"**.
   - Cierra sesión y entra como `encargado_encargado_central` / `patuju2024`.
   - En el panel de encargado verás reflejadas **las sucursales que el admin le asignó más su sucursal principal**, que siempre conserva acceso (en el modal aparece fija y se cambia desde Editar). Podrás entrar al detalle de cualquiera de ellas para ver stock, ingresar lotes de salteñas o registrar horneadas matutinas.
