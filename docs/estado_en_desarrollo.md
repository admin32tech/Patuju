# 📊 Estado Actual del Proyecto: Patujú POS

**Fecha de Estado:** Noviembre 2026 (última revisión: 2026-11-05)  
**Hoja de Ruta Principal:** [ROADMAP.md](ROADMAP.md)  
**Estado General:** **Fase 1, Fase 2, Fase 2.5 y Fase 3 Completadas al 100% 🚀** (Listos para iniciar Fase 4: Resiliencia Offline y PWA)

---

## 🟢 Fase 1: Seguridad y Accesos Base (100% Completada)

- ✅ **Variables de Entorno (`.env`):** Credenciales de BD aisladas del código versionado.
- ✅ **Recordar Último Usuario:** Implementado vía cliente (`localStorage['ultimo_usuario']`) para autocompletar el nombre de usuario sin almacenar contraseñas (máxima comodidad y seguridad).
- ✅ **Rate Limiting:** Control contra ataques de fuerza bruta en login (5 intentos / 15 min de bloqueo).
- ✅ **Protección de Sesión:** `session_regenerate_id(true)` post-login y cookies protegidas (`HttpOnly`, `SameSite=Strict`, `Secure` dinámico).
- ✅ **Assets e Imágenes Locales:** Landing page totalmente independiente de servidores externos.

---

## 🟢 Fase 2: Núcleo Multi-Sucursal e Inventario (100% Completada)

El sistema opera con total independencia por sucursal a través de las 3 vistas requeridas:

### 1. Vista Caja (`index.php` / `app.js` — Rol Cajero)
- ✅ **Aislamiento por Sucursal:** El cajero ve y comercializa únicamente el stock y catálogo de su sucursal (`$_SESSION['sucursal_id']`).
- ✅ **Control de Turnos:** Apertura obligatoria de turno con registro de fondo inicial antes de iniciar la venta.
- ✅ **Cobro en Efectivo:** Pago en efectivo con cálculo dinámico de vuelto en tiempo real en el servidor.
- ✅ **Descuento de Stock Atómico:** Descuento inmediato de stock al registrar la venta con bloqueo `FOR UPDATE` cuando la disponibilidad llega a 0.
- ✅ **Trazabilidad de Movimientos:** Cada venta descuenta stock y registra un movimiento de auditoría en `stock_movimientos`.
- ✅ **Arqueo y Cierre de Caja:** Registro de conteo de cierre (Corte Z) con cálculo automático de sobrantes y faltantes y Corte X en vivo.
- ✅ **Impresión de Ticket Térmico:** Formato optimizado `@media print` para impresoras de 58mm / 80mm.

### 2. Vista Panel Encargado (`encargado.php` / `encargado.js` — Rol Encargado)
- ✅ **Ingreso de Mercadería por Lote:** Registro de recepciones de fábrica u horneadas matutinas.
- ✅ **Precios Locales:** Posibilidad de ajustar precio específico por sucursal o heredar el precio base general.
- ✅ **Alertas de Stock Mínimo:** Configuración de umbrales mínimos por producto para alertas en caja.
- ✅ **Historial de Movimientos:** Trazabilidad de entradas, salidas y ajustes en `stock_movimientos`.

### 3. Vista Panel Admin (`admin.php` / `admin.js` — Rol Dirección General)
- ✅ **Catálogo Global de Productos:** CRUD centralizado de productos y categorías base.
- ✅ **Stock Consolidado:** Monitor en tiempo real de inventario en las 15 sucursales.
- ✅ **Auditoría de Ventas:** Trazabilidad completa de ventas (fecha, hora, cajero, sucursal y detalle).
- ✅ **Auditoría de Cortes Z:** Historial consolidado de arqueos de caja a nivel nacional.
- ✅ **Acceso Fluido a 3 Vistas:** Sucursal interna `SUC-000 Administración` (`es_interna = 1`) y auto-recuperación de sesión (`asegurarSucursalAdmin()`), permitiendo al admin alternar bidireccionalmente entre Caja, Encargado y Admin sin contaminar analíticas ni la landing pública.

---

## 🟢 Fase 2.5: Usuarios y Multi-Sucursal para Encargados (Completada)

- ✅ **Gestión de usuarios:** pestaña "Gestión de usuarios" en `admin.php` respaldada por `ajax/usuarios.php` (alta, edición, asignación de sucursales, reseteo de claves y activación).
- ✅ **Encargados multi-sucursal:** tabla N:M `usuario_sucursales`; la sucursal principal del encargado siempre conserva acceso.
- ✅ **Permisos granulares:** `puede()` y `obtenerSucursalesAsignadas()` en `config/auth.php`.
- 📄 **Documentación técnica:** [1user.md](1user.md).

---

## 🟢 Fase 3: Dashboard de Analítica Gerencial (100% Completada 🚀)

- ✅ **Visualización con Chart.js:**
  - Gráfico 1: Unidades vendidas hoy por sucursal (incremental).
  - Gráfico 2: Distribución geográfica de ventas por departamento (Doughnut).
  - Gráfico 3: Horarios pico de venta hoy (Línea continua de 06:00 a 19:00).
  - Gráfico 4: Top 10 productos más vendidos del mes en unidades e ingresos.
  - Gráfico 5: Tendencia de facturación diaria de los últimos 30 días.
- ✅ **Fidelidad e Integridad de Datos (Fase_3.md):**
  - Polling periódico automático cada 30 segundos.
  - Principio de preservación de estado: Nunca reemplazar datos válidos por errores de red.
  - Indicadores visuales de estado: `🟢 Actualizado`, `🟡 Actualizando`, `🟠 Datos antiguos (>90s)`, `🔴 Error de actualización`.
  - Control contra respuestas fuera de orden mediante versiones de datos (`data_version`).
- ✅ **Reportes Exportables e Imprimibles:**
  - Exportación directa a CSV con codificación UTF-8 BOM (`\xEF\xBB\xBF`) para compatibilidad nativa con Microsoft Excel en español.
  - Reportes disponibles: Ventas por Sucursal, Ranking de Productos y Detalle Diario.
  - Estilos de impresión `@media print` optimizados para generación de PDF formal.

---

## 🧪 Sesión de Verificación — 2026-11-04 (Terminal 2 / Antigravity)

**Alcance:** auditoría de los fixes de modales del panel Encargado y del acceso del admin a la caja.

- ✅ **Modales de Encargado:** los 4 modales (`#modal-stock-diario`/horneada, `#modal-lote`, `#modal-movimientos` y `#modal-ajuste`/editar producto) abren y cierran con `classList.add('active')` / `classList.remove('active')`, sin `style.display`. Verificado en `assets/js/encargado.js` y `encargado.php`.
- ✅ **Acceso del admin a caja:** `asegurarSucursalAdmin()` (`config/auth.php`) recupera y puebla `sucursal_id` (`SUC-000`) en sesiones previas a la migración; se invoca desde `index.php` y `encargado.php`.
- ✅ **Chequeos estáticos:** `php -l` y `node --check` sin errores (según Terminal 2).
- ⚠️ **Prueba en navegador pendiente:** MySQL estaba apagado, por lo que no se validó el flujo real.
- ⚠️ **Botones redundantes:** persisten dos botones "Actualizar" en `encargado.php` que invocan `Encargado.cargarResumenSucursales()` y `Encargado.recargarSucursalActual()`. El ítem 6 de [CORRECCIONES-PENDIENTES.md](CORRECCIONES-PENDIENTES.md) ya pedía retirarlos.
- 🐛 **Cabecera del panel:** mostraba "(Encargado)" incluso al entrar como admin. Corregida: muestra "(Administrador)" o "(Encargado)" según `$rol`.
- ➡️ **Siguiente paso:** el usuario solicitó preparar cambios en el frontend.

> **Nota:** el fix de modales (`assets/js/encargado.js`) está en el commit `99c2fa7`; el fix de cabecera de `encargado.php` se integró junto con la Fase 2.5.

---

## 🧪 Sesión de Auditoría de Integración — 2026-11-05

**Alcance:** auditoría de la integración de la Fase 2.5 (usuarios y multi-sucursal) con el resto del sistema. Hallazgos corregidos:

- ✅ **Sesión revalidada en cada petición:** `verificarSesion()`/`verificarSesionAjax()` releen rol, estado `activo` y sucursal desde la BD; un usuario desactivado o eliminado pierde la sesión (AJAX 401, vistas → `login.php`).
- ✅ **URL manipulada → 403:** `sucursalSolicitadaPermitida()` (`config/auth.php`) valida todo `sucursal_id` que llega del cliente en `stock.php` (GET y POST), `historial.php` y `turno_caja.php`. Para caja y encargado, `0`, `abc`, `-1` o una sucursal que no les corresponde responden 403 en vez de caer en "todas" o en otra sucursal sin avisar; las consultas de todas las sucursales quedan solo para `admin`.
- ✅ **Historial del día:** caja y encargado solo ven las ventas de hoy (no se acumulan; los filtros de fecha se ignoran). En la vista de caja, el botón Historial muestra al encargado las ventas de hoy de todas las cajas a su cargo; el admin conserva el histórico completo en Auditoría.
- ✅ **Validaciones de `ajax/usuarios.php`:** sucursal principal obligatoria y no interna para roles distintos de `admin`, la principal del encargado siempre conserva acceso, `reset_password` exige clave explícita y `crear` acepta `activo`.
- ✅ **Migración:** `actualizar_bd.php` ya no pisa la clave del `admin` (solo la restablece si su hash no es un Bcrypt válido) y siembra `usuario_sucursales` solo cuando la tabla no existía; `database/init_usuarios.php` queda para instalaciones nuevas.
- ✅ **Suite de pruebas endurecida:** BD aislada `patuju_pos_test` (el nombre debe terminar en `_test`), variables `PATUJU_*` respetadas por la app y la suite. Cómo correrla: ver la sección "Pruebas" del [README](../README.md).
- ✅ **Cierre de turno supervisado:** desde el panel del encargado, cada sucursal con turno abierto muestra "Cerrar turno" (Corte X y efectivo contado → Corte Z). El encargado cierra los turnos de sus cajas y el admin los de cualquiera; queda registrado quién lo cerró. Cubre al cajero que no cerró o que fue reasignado con el turno abierto.
- ⏳ **Pendiente:** proteger `actualizar_bd.php` con autenticación (rol `admin`) o bloquearlo tras migrar; hoy cualquiera con acceso a la URL puede ejecutarlo.

---

## 🔴 Fase 4: Resiliencia Offline y PWA (Próxima Fase)

- 📶 **PWA & Service Worker:** Instalación de la aplicación y caché de assets estáticos.
- 💾 **IndexedDB:** Registro local de ventas sin conexión.
- 🔄 **Sincronización:** Sync de ventas acumuladas al restablecer conectividad.
- 📄 **Especificación detallada:** Ver [IT_PWA.md](IT_PWA.md).

---

## 🔴 Fase 5: Infraestructura Cloud y Alta Disponibilidad (Futuro)

- ☁️ Base de datos gestionada en la nube con réplica de lectura.
- 🔑 Sesiones centralizadas en Redis.
- 🌐 API REST monolítica versionada (`/api/v1/`).

---

## 💾 Estructura de la Base de Datos (`database/`)

El directorio `patuju1000/database/` se gestiona bajo el principio de **Única Fuente de Verdad** ("menos es más"):

| Archivo | Propósito | Cuándo se usa |
|---|---|---|
| **`schema.sql`** | Esquema completo unificado y seeders (Fases 1, 2 y 3) con reseteo limpio (`DROP TABLE IF EXISTS`), 15 sucursales, 31 usuarios, inventario inicial sembrado e índices analíticos. | **Instalación o reseteo directo en Laragon / XAMPP / phpMyAdmin.** |

> ℹ️ **Nota de arquitectura:** Como la base de datos se inicializa directamente desde cero en desarrollo/staging, no se utilizan scripts de migración intermedios dispersos. Todo cambio se versiona directamente en `schema.sql`.
>
> ⚠️ **BD existentes:** al actualizar el código sobre una BD en uso, ejecuta `actualizar_bd.php` (migración idempotente: crea `usuario_sucursales`, `es_interna`, `SUC-000`, etc.). No uses `database/init_usuarios.php` para migrar: es para instalaciones nuevas y restablece las claves de los usuarios semilla.

### 🚀 Importación en Laragon / XAMPP / MySQL

```bash
# Importación limpia con creación de BD y seeders
mysql -u root -p < patuju1000/database/schema.sql
```
