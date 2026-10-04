# 📋 PATUJU POS — Correcciones Pendientes

**Estado:** ✅ Corregido el 2026-09-20 (Fase 4)
**Versión:** Fase 2 (Multi-Sucursal)
**Prioridad:** Por fases

> **Resumen de sesión 2026-09-20:**
> - ✅ BUG-B: CROSS JOIN → INNER JOIN en `ajax/stock.php`
> - ✅ BUG-C: `activarProducto()` ahora envía `activo:1` correctamente en `admin.js`
> - ✅ BUG-D: PUT de editar producto ahora pasa `?id=X` en la URL en `admin.js`
> - ✅ BUG-E: Creado `ajax/admin_sync.php` + auto-polling cada 30s en `admin.js`
> - ✅ BUG-08: Documentado el comportamiento de auto-rehash en `schema.sql`
> - ✅ Confirmados como YA corregidos: BUG-01, BUG-02, BUG-03, BUG-04, BUG-06, BUG-07

---

## 🔴 CRÍTICO — Bloquea Funcionamiento

### 1. Error DB: `SQLSTATE[42S22]` — Columna `usuario_id` no existe

**Ubicación:** `ajax/registrar_venta.php`  
**Síntoma:** Al intentar registrar una venta, falla con `Unknown column 'usuario_id' in 'field list'`

**Causa raíz:**
- La tabla `ventas` en la BD no tiene la columna `usuario_id`.
- El código fue actualizado a Fase 2 (que exige trazabilidad), pero la BD se creó con un `schema.sql` antiguo.

**Solución:**

*Opción A: Si tienes datos de prueba que no importa perder*
```bash
# Resetear BD completamente
mysql -u root -p -e "DROP DATABASE patuju_pos;"
mysql -u root -p < patuju1000/database/schema.sql
```

*Opción B: Si tienes datos reales que conservar*
```sql
-- Agregar columna si no existe
ALTER TABLE ventas 
ADD COLUMN usuario_id INT NULL AFTER sucursal_id;

-- Agregar foreign key
ALTER TABLE ventas 
ADD CONSTRAINT fk_ventas_usuario 
FOREIGN KEY (usuario_id) REFERENCES usuarios(id) 
ON DELETE SET NULL ON UPDATE CASCADE;

-- Agregar índice
CREATE INDEX idx_ventas_usuario ON ventas(usuario_id);
```

**Verificación post-fix:**
```sql
DESC ventas;  -- Debe mostrar usuario_id
```

---

### 2. Autenticación fallida — Credenciales incorrectas aunque sean correctas

**Ubicación:** `ajax/login.php` y `config/auth.php`  
**Síntoma:** Login rechaza credenciales válidas (`usuario_id` = 1, `contraseña` = correcta) sin motivo aparente

**Causa probable:**
- El hash de contraseña en la BD no coincide con lo que se envía desde el formulario.
- `password_verify()` falla silenciosamente, pero el error no se reporta claramente.
- Las credenciales por defecto en `schema.sql` no son las que estás usando.

**Investigación necesaria:**

1. **Verificar credenciales por defecto en BD:**
```sql
SELECT id, usuario, contraseña FROM usuarios LIMIT 1;
```
Nota qué dice el campo `contraseña` (debe ser un hash bcrypt, no plain text).

2. **Probar hash correcto:**
```bash
# En PHP, generar un hash correto
php -r "echo password_hash('patuju_sep2026', PASSWORD_BCRYPT) . PHP_EOL;"
```

3. **Reemplazar en BD:**
```sql
UPDATE usuarios SET contraseña = 'HASH_GENERADO_ARRIBA' WHERE id = 1;
```

**Solución temporal:** 
En `schema.sql`, línea donde se inserta usuario admin, asegúrate que esté así:
```sql
INSERT INTO usuarios (usuario, contraseña, rol, sucursal_id) 
VALUES ('admin', '$2y$10$...HASH_VALIDO...', 'admin', 1);
```

**Verificación post-fix:**
- Loguéate con las credenciales que aparezcan en `schema.sql`.
- Si aún falla, revisa la consola del navegador (DevTools → Console) para mensajes adicionales.

---

## 🟠 ALTO — Impacta UX

### 3. Vista Encargado — Diseño poco intuitivo y sobrecargado

**Ubicación:** `encargado.php` + `assets/js/encargado.js`  
**Problemas:**
- Muestra muchas opciones simultáneamente (cambiar precio, ingresar lote, reposición, etc.).
- El botón **"Refrescar"** (📜 Movimientos de Inventario🔄) no es útil, solo limpia caché.
- No hay separación clara entre "ver" y "editar".
- Monto inicial de turno caja aparece en lugar incorrecto.

**Solución propuesta — Rediseño por flujo:**

**Nuevo flujo (2 pasos):**

1. **Pantalla principal: Listado de cajas (sucursal actual)**
   - Mostrar cada producto como una tarjeta tipo la imagen de sucursales (nombre, precio actual, stock actual).
   - No editar directamente, solo mostrar estado.

2. **Al hacer clic en una tarjeta: Modal de edición**
   - Opción A: **Ajustar Precio** (campo de entrada + guardar).
   - Opción B: **Ingreso de Mercadería** (cantidad + motivo + guardar).
   - Opción C: **Ver Historial** (últimas 5 movimientos).

**Cambios de código:**

*Eliminar:*
- Botón "Refrescar" (no sirve).
- Campo "Monto inicial de turno" (va en `turno_caja.php`, no aquí).
- Tabla de "Movimientos" en la vista principal (mover a modal).

*Agregar:*
- Grid/Cards de productos (una card por producto).
- Modal popup al hacer clic (editar precio o ingresar stock).
- Validación de permisos (solo encargado de la sucursal puede editar su sucursal).

**Ejemplo visual de lo que debe verse:**
```
┌─────────────────────────────────────────┐
│ Encargado: Patuju Central (La Paz)      │
├─────────────────────────────────────────┤
│  ┌──────────────┐  ┌──────────────┐    │
│  │ Salteña      │  │ Empanada     │    │
│  │ Precio: 3Bs  │  │ Precio: 2.5  │    │
│  │ Stock: 45    │  │ Stock: 30    │    │
│  │ [Click]      │  │ [Click]      │    │
│  └──────────────┘  └──────────────┘    │
│  ┌──────────────┐  ┌──────────────┐    │
│  │ Jugo Natural │  │ Soda Naranja │    │
│  │ Precio: 5Bs  │  │ Precio: 4Bs  │    │
│  │ Stock: 120   │  │ Stock: 80    │    │
│  │ [Click]      │  │ [Click]      │    │
│  └──────────────┘  └──────────────┘    │
└─────────────────────────────────────────┘

[Al hacer clic en "Salteña"]
┌──────────────────────────┐
│ Editar: Salteña          │
├──────────────────────────┤
│ Stock actual: 45         │
│                          │
│ ○ Ajustar Precio        │
│   Nuevo precio: [__Bs]   │
│   [Guardar]              │
│                          │
│ ○ Ingreso de Mercadería │
│   Cantidad: [__] unidades│
│   Motivo: [dropdown]     │
│   [Guardar]              │
│                          │
│ ○ Ver Historial         │
│   [Últimos movimientos]  │
│                          │
│              [Cerrar]    │
└──────────────────────────┘
```

---

### 4. Vista Admin — Problemas de sincronización y datos inconsistentes

**Ubicación:** `admin.php` + `assets/js/admin.js`  
**Problemas:**
- Dashboard muestra datos "desincronizados" (aparecen caché stale).
- El historial de ventas no filtra correctamente por sucursal ni por rango de fechas.
- Los gráficos (Fase 3) aún no están, pero cuando se agreguen, necesitarán datos limpios.

**Causa probable:**
- No hay invalidación de caché cuando cambian los datos.
- Las queries de `ajax/historial.php` no validan correctamente los parámetros.

**Solución:**

1. **Agregar endpoint de validación:**
```php
// ajax/admin_sync.php (nuevo)
GET /ajax/admin_sync.php?action=validate
// Responde: { "status": "ok", "timestamp": "..." }
// Se ejecuta cada 30 seg para verificar si hay cambios
```

2. **Refrescar automáticamente si hay cambios:**
```javascript
// En admin.js, agregar:
setInterval(async () => {
    const resp = await fetch('/ajax/admin_sync.php?action=validate');
    const data = await resp.json();
    if (data.last_change > lastLocalTimestamp) {
        // Refrescar dashboard, historial, etc.
    }
}, 30000);
```

3. **Limpiar caché explícitamente:**
   - Agregar botón **"Refrescar Ahora"** (visual, no oculto).
   - Al hacer clic, limpia localStorage + recarga datos desde BD.

**Verificación post-fix:**
- Crea una venta en Caja.
- Ve a Admin → debe reflejarse en <10 segundos.

---

## 🟡 MEDIO — Mejoras de UX

### 5. Flujo de Apertura de Turno — Monto inicial innecesario o confuso

**Ubicación:** `turno_caja.php` + Modal de apertura  
**Problema:** El campo "Monto inicial" (fondo de caja) aparece en múltiples lugares o no está claro si es obligatorio.

**Solución:**
- Mantener solo en **Modal de Apertura de Turno** (cuando inicia el día).
- Validar que sea número positivo.
- Guardar en tabla `turnos_caja.monto_apertura`.
- No debe aparecer en `encargado.php`.

**Código a revisar:**
```php
// En turno_caja.php, línea de INSERT:
INSERT INTO turnos_caja (usuario_id, sucursal_id, monto_apertura, hora_apertura, estado)
VALUES (?, ?, ?, NOW(), 'abierto')
```

---

### 6. Botones sin propósito claro — "Refrescar", "Sincronizar", etc.

**Ubicación:** Múltiples vistas  
**Problema:** Hay botones que repiten funcionalidad o solo limpian caché sin explicación.

**Solución:**
- **Eliminar:** Botón "Refrescar" de la vista Encargado (no hace nada útil).
- **Reemplazar:** Con un único botón **"Actualizar Datos"** en Admin (si es necesario) con spinner visual.
- **Documentar:** Qué hace cada botón en un tooltip (hover).

---

## 📋 Checklist de Auditoría de Código Recomendado

Para evitar errores como estos en el futuro, sigue este checklist antes de hacer un deployment:

### 1. **Consistencia BD ↔ Código**
- [ ] Ejecuta `DESC tabla;` para cada tabla que el código usa.
- [ ] Verifica que **todas** las columnas en `INSERT/UPDATE` existan en la BD.
- [ ] Ejemplo:
```sql
DESC ventas;  -- ¿Existe usuario_id?
DESC turnos_caja;  -- ¿Existe monto_apertura?
DESC stock_sucursal;  -- ¿Existe alerta_minima?
```

### 2. **Autenticación y Sesión**
- [ ] Prueba login con usuarios por defecto de `schema.sql`.
- [ ] Verifica que `password_verify()` devuelva true.
- [ ] Confirma que `$_SESSION['usuario_id']` se propaga a todos los endpoints.
```php
// En cada ajax/*.php, al inicio:
session_start();
if (!isset($_SESSION['usuario_id'])) {
    die(json_encode(['error' => 'No autenticado']));
}
```

### 3. **Flujos de Datos Clave**
- [ ] **Login:** usuario → sesión → caja.
- [ ] **Venta:** caja → INSERT ventas + descuento stock.
- [ ] **Turno:** apertura → venta → cierre.
- [ ] Intenta cada flujo manualmente en todos los roles (cajero, encargado, admin).

### 4. **Parámetros GET/POST**
- [ ] Revisa cada `$_GET` y `$_POST` — ¿llega lo que esperas?
- [ ] Agrega logs temporales:
```php
error_log("POST recibido: " . json_encode($_POST));
```

### 5. **Foreign Keys y Restricciones**
- [ ] Antes de insertar, verifica que los FK existan:
```php
// Antes de INSERT ventas(..., sucursal_id, ...):
$check = $db->prepare("SELECT id FROM sucursales WHERE id = ?");
$check->execute([$sucursal_id]);
if (!$check->fetch()) {
    die(json_encode(['error' => 'Sucursal no existe']));
}
```

### 6. **Transacciones Atómicas**
- [ ] Revisa que cada `INSERT` crítico (venta + stock) está dentro de `BEGIN TRANSACTION ... COMMIT/ROLLBACK`.
- [ ] Verifica que no hay conexión cerrada a mitad de transacción.

---

## 🛠️ Orden Recomendado de Correcciones

**Semana 1:**
1. Arreglar error `usuario_id` (CRÍTICO).
2. Revisar credenciales de login (CRÍTICO).
3. Refactorizar vista Encargado (ALTO impacto).

**Semana 2:**
4. Sincronización de Admin (MEDIO).
5. Limpiar botones sin propósito (BAJO).

**Después:**
6. Implementar checklist de auditoría para futuros cambios.

---

## 📞 Cómo Comunicar Estos Errores a un Asistente IA (Para la Próxima Vez)

**Mal:**
> "Hay muchos errores, ayúdame a arreglarlo."

**Bien:**
```markdown
Tengo estos errores en PATUJU POS:

1. **CRÍTICO - DB:** SQLSTATE[42S22] Unknown column 'usuario_id' in 'field list'
   - Ocurre en: ajax/registrar_venta.php, línea 3200
   - Contexto: Intento registrar venta, tabla ventas no tiene columna usuario_id
   - BD: patuju_pos, creada con schema.sql antiguo

2. **CRÍTICO - Auth:** Login falla aunque credenciales son correctas
   - Usuario: admin / admin
   - Password_verify() devuelve false sin motivo aparente
   - Revisar: password hash en BD vs formulario

3. **ALTO - UX:** Vista Encargado sobrecargada
   - Quiero: Cards de productos (como la imagen de sucursales adjunta)
   - Actual: Tabla con muchas opciones simultáneamente
   - Cambio deseado: Click en card → modal para editar precio/stock

4. **AUDITORÍA:** ¿Cómo debería revisar mi código para prevenir estos errores?
   - Tengo BD, PHP, JS — ¿qué checklist usar?

Con esto, es mucho más fácil ayudarte correctamente.
```

---

## 📚 Archivos Relacionados

- `schema.sql` — Estructura de BD (verificar que tenga `usuario_id` en `ventas`).
- `ajax/registrar_venta.php` — Donde falla el INSERT.
- `ajax/login.php` — Donde falla autenticación.
- `encargado.php` + `encargado.js` — Vista a refactorizar.
- `admin.php` + `admin.js` — Dashboard con sincronización lenta.



1. Bug de pestañas duplicadas (Caja)
Es prevenible — no es "normal" del sistema. Lo que se ve en la imagen (SUC-001 Patuju Central duplicada) apunta a que el panel de sucursales asignadas se está re-renderizando sin limpiar el contenedor anterior, típico de:
Un fetch/render que se dispara en cada focus de pestaña o en un intervalo, y cada vez agrega tarjetas al DOM en vez de reemplazar el contenido.
Dos pestañas compartiendo la misma sesión de servidor y el backend devolviendo la sucursal dos veces por un JOIN duplicado (ej. left join contra tabla de turnos que trae más de una fila abierta).
Guía para la IA coding:
Revisar la consulta SQL que arma "Sucursales Asignadas" — confirmar que agrupa por sucursal (o usa DISTINCT/GROUP BY) y no duplica filas si hay más de un turno/caja abierto para esa sucursal.
Revisar el JS del panel: antes de pintar las tarjetas, limpiar el contenedor (innerHTML = '' o equivalente) en cada refresco, y evitar que el listener de refresco se registre más de una vez (común si el script se importa dos veces o el evento se liga en cada render).
Simular el caso "misma sucursal, dos pestañas abiertas" en pruebas antes de dar por cerrado.
2. Panel admin editable por sucursal (stock y precios)
Hoy "Auditoría de Ventas" solo crea productos a nivel catálogo general (imagen 2), pero no deja tocar stock ni precio por sucursal. Se necesita recuperar la vista de edición que existía al inicio, pero enganchada al flujo actual multi-sucursal.
Guía para la IA coding:
Ubicar en el historial/versión anterior del proyecto la vista de "panel admin" que permitía editar producto (nombre, precio, imagen, stock) — es la base a reutilizar, no a rehacer desde cero.
Definir el punto de entrada: desde "Ver sucursal →" (imagen 1) o desde "Stock por Sucursal" (pestaña ya existente en Dirección General), debe abrirse una vista de edición que muestre, por producto, el stock y precio de esa sucursal específica — no el precio base general del catálogo.
Diferenciar dos niveles de datos: catálogo general (nombre, categoría, imagen — ya cubierto) vs. inventario por sucursal (cantidad en stock, precio local si aplica, umbral de stock bajo). El formulario de edición debe escribir sobre la tabla de inventario por sucursal, no sobre el catálogo global.
Mantener el mismo diseño visual ya aprobado; solo agregar los campos editables (inputs de cantidad/precio + botón guardar) sobre las filas de producto en esa vista.

lo que se realizo, Tengo el contexto completo. Voy a proceder a corregir todos los bugs pendientes en orden de severidad. Déjame revisar los archivos relevantes primero.
Tengo toda la información. Ahora procedo a corregir los bugs en orden:
BUG-02 — Agregar auth a crud_productos.php y productos.php
BUG-03 — Validar stock en cambiarCantidad()
BUG-04 — Eliminar header duplicado en historial.php
BUG-05 — Cambiar CROSS JOIN a subconsultas e INNER JOIN en stock.php
BUG-06 — Usar sucursal_nombre en lugar de sucursal_id en app.js
Perfecto. Ahora aplico todos los fixes:

desde la fila 242

   // ── Modificar cantidad ────────────────────
   // BUG-03 FIX: Valida el límite de stock disponible antes de incrementar
   function cambiarCantidad(index, delta) {
       if (delta > 0) {
           const item = carrito[index];
           // Buscar el producto en el catálogo para obtener su stock actual
           let stockDisponible = Infinity;
           for (const cat of productos) {
               const prod = cat.productos.find(p => p.id == item.id);
               if (prod) {
                   stockDisponible = parseInt(prod.cantidad_disponible, 10) || 0;
                   break;
               }
           }
           if (item.cantidad >= stockDisponible) {
               toast(`⚠️ Stock máximo disponible: ${stockDisponible} u.`, 'error');
               return;
           }
       }

esto habia fila 650 (                            <td>${v.sucursal_id}</td>
)
se cambio por,                            <td>${escapeHtml(v.sucursal_nombre)}</td>

 en  la carpeta crud_pruductos.php, se cambio lo siguiente require_once __DIR__ . '/../config/database.php';


de fila 9 a 21

require_once __DIR__ . '/../config/auth.php';
verificarSesionAjax();


header('Content-Type: application/json; charset=utf-8');


$method = $_SERVER['REQUEST_METHOD'];


// Para crear, editar o eliminar productos se requiere rol admin
// BUG-02 FIX: Se eliminó acceso GET sin autenticación. Ahora toda la ruta requiere sesión válida.
if ($method !== 'GET' && ($_SESSION['usuario_rol'] ?? '') !== 'admin') {
   jsonResponse(['success' => false, 'error' => 'Acceso denegado. Se requieren permisos de administrador.'], 403);
}








productos.php, se cambio por lo siguiente

require_once __DIR__ . '/../config/database.php';
session_start();
if (!isset($_SESSION['usuario_id'])) {
    jsonResponse(['success' => false, 'error' => 'Sesión expirada'], 401);
}

header('Content-Type: application/json; charset=utf-8');

por
 require_once __DIR__ . '/../config/auth.php';
verificarSesionAjax();
// BUG-04 FIX: El header Content-Type ya lo establece jsonResponse() en database.php.
// No se repite aquí para evitar "headers already sent" con output_buffering desactivado.



