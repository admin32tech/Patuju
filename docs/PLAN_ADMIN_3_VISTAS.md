# Plan de traspaso — Admin con acceso a Caja, Encargado y Admin

**Para:** la IA que implementa (OpenCode / Devsfrom).
**Después:** Claude audita el resultado y lo devuelve hasta que cumpla todo lo de este documento.
**Alcance:** solo lo descrito aquí. No tocar nada más (ver "No hacer").

## Contexto

El usuario `admin` tiene `sucursal_id = NULL` (`database/init_usuarios.php`). Efectos:

- `index.php:5-8` lo manda a `admin.php` si no tiene sucursal, así que nunca llega a caja.
- `admin.php:52` esconde "Ir a caja" porque la sesión no trae sucursal.
- `ajax/registrar_venta.php:30` y `ajax/productos.php:22` usan `?? 1`: sin sucursal venden en Patuju Central sin avisar.
- `encargado.php` entra con `sucursalId = 0` y no tiene enlace de vuelta a `admin.php`.

**Decisión tomada:** crear una sucursal interna `SUC-000 Administración` para el admin. Los permisos siguen siendo la columna `usuarios.rol` (sin tabla de permisos todavía).

**Objetivo:** el admin entra a Caja, Encargado y Admin, y puede volver a Admin desde las otras dos. Esa sucursal no debe aparecer en la landing pública ni sumar en la analítica.

## Cambios (en este orden)

### 1. `actualizar_bd.php` — migración idempotente
Agregar, antes del mensaje final "¡Actualización completada...", usando el mismo estilo del archivo (`$db`, `SHOW COLUMNS`, `echo <p>`):

1. Si no existe `sucursales.es_interna`: `ALTER TABLE sucursales ADD COLUMN es_interna TINYINT(1) NOT NULL DEFAULT 0 AFTER activo`.
2. Si no existe `codigo = 'SUC-000'`: insertar `('SUC-000', 'Administración', 'Oficina central', es_interna = 1, activo = 1)`. Los demás campos usan sus defaults o NULL. No fijar el id a mano: usar el id que devuelva `lastInsertId()` (o leerlo con `SELECT id ... WHERE codigo='SUC-000'`).
3. Sembrar stock: `INSERT IGNORE INTO stock_sucursal (sucursal_id, producto_id, cantidad_disponible, alerta_minima, stock_predeterminado, disponible_venta)` para cada producto con `activo = 1`, con sucursal = id de SUC-000, cantidad 50, alerta 10, predeterminado 0, disponible 1. `INSERT IGNORE` para poder correr el script más de una vez (clave única `uq_suc_prod`).
4. `UPDATE usuarios SET sucursal_id = <id SUC-000> WHERE rol = 'admin' AND (sucursal_id IS NULL OR sucursal_id <> <id SUC-000>)`.

Correr el script dos veces no debe fallar ni duplicar filas.

### 2. `database/schema.sql`
- En `CREATE TABLE sucursales` (línea ~45): agregar `es_interna TINYINT(1) NOT NULL DEFAULT 0` después de `activo`.
- En el `INSERT INTO sucursales` (línea ~237): agregar al final la fila `SUC-000` con id 16, nombre `Administración`, `es_interna = 1`. Como ese INSERT lista columnas explícitas, agregar `es_interna` a la lista de columnas y `0` a las 15 filas existentes (o usar un segundo INSERT solo para SUC-000, que es más simple y menos riesgoso).
- El stock semilla (línea ~281) ya hace `FROM sucursales s CROSS JOIN productos p WHERE s.activo = 1`, así que SUC-000 recibe su stock sola. **No agregar un segundo INSERT de stock.**

### 3. `database/init_usuarios.php`
- La fila del admin (línea ~33) pasa de `NULL` a la sucursal SUC-000. Como los demás usan ids numéricos fijos, usar `16` y dejar un comentario `// SUC-000 Administración`.

### 4. `index.php` (caja)
- Líneas 5-8: borrar el bloque que redirige al admin a `admin.php` cuando no hay sucursal.
- En su lugar: si `empty($_SESSION['sucursal_id'])` para cualquier rol, `header('Location: login.php?error=sin_sucursal'); exit;`.

### 5. `ajax/registrar_venta.php` y `ajax/productos.php`
- Quitar `?? 1`. Si `$_SESSION['sucursal_id']` está vacío: `jsonResponse(['success' => false, 'error' => 'Sin sucursal asociada'], 403)`. Mismo patrón que `ajax/turno_caja.php:126`.

### 6. `encargado.php` — navegación del admin
- En el `topbar__actions` (junto al badge de usuario, ~líneas 33-46), solo si `$rol === 'admin'`, agregar dos enlaces con la clase `btn`:
  - `index.php` → "Ir a Caja"
  - `admin.php` → "Volver a Admin"
- Reutilizar el estilo de `index.php:48-51` (icono `store`/`cart` del sprite `assets/img/icons.svg`). No inventar CSS nuevo.
- `admin.php` e `index.php` ya tienen sus enlaces; no cambiarlos.

### 7. Filtro `es_interna = 0` (solo público y analítica)
- `landing.php:12`: `WHERE activo = 1 AND es_interna = 0`.
- `ajax/analytics.php`: en las consultas de las líneas ~37, ~85 y ~143 que usan `sucursales s`, agregar `AND s.es_interna = 0` (línea ~85 es un `JOIN sucursales s ON s.id = v.sucursal_id` con `WHERE`: agregar la condición ahí). Revisar el archivo completo por si hay otra consulta con `sucursales` que no aparezca en esta lista.

## No hacer

- No crear tabla de permisos, ni pantalla de usuarios, ni función `puede()`. Es el paso siguiente.
- No filtrar `es_interna` en `admin.php:6`, `historial.php`, `turno_caja.php`, `stock.php` ni `crud_productos.php`: el admin debe ver la sucursal interna para probar, y los productos nuevos deben sembrar stock también ahí.
- No cambiar reglas de rol en `config/auth.php`. Ya dejan pasar al admin a todo.
- No tocar el layout ni los estilos más allá de los dos botones.
- No borrar ni reescribir datos existentes. La migración solo agrega.
- No hacer commit. Dejar los cambios en el árbol de trabajo para que Claude audite.

## Criterios de aceptación (los verifica Claude)

1. `actualizar_bd.php` corre dos veces seguidas sin errores y sin duplicar `SUC-000` ni filas de stock.
2. `admin` tiene `sucursal_id` de SUC-000; `SUC-000` tiene `es_interna = 1` y una fila de `stock_sucursal` por producto activo.
3. Login admin → cae en `admin.php` y muestra "Ir a caja".
4. Admin → `index.php` carga la caja con catálogo; puede abrir turno y registrar una venta de prueba (queda en SUC-000).
5. Admin → `encargado.php` muestra "Ir a Caja" y "Volver a Admin" y ambos funcionan. Un encargado y un cajero **no** ven esos enlaces.
6. `landing.php` no lista "Administración". La analítica del admin no incluye SUC-000 ni la venta de prueba.
7. `caja_caja_central` y `encargado_encargado_central` siguen entrando a lo suyo y siguen bloqueados en `admin.php`.
8. Sin sesión, `admin.php`, `index.php` y `encargado.php` redirigen a `login.php`.
9. Una sesión sin `sucursal_id` no puede vender: `registrar_venta.php` y `productos.php` devuelven 403.
10. `schema.sql` importado en limpio + `init_usuarios.php` deja el mismo estado que la migración.
11. `git diff` solo toca: `actualizar_bd.php`, `database/schema.sql`, `database/init_usuarios.php`, `index.php`, `encargado.php`, `landing.php`, `ajax/registrar_venta.php`, `ajax/productos.php`, `ajax/analytics.php`.

## Auditoría de Claude (después de implementar)

Claude revisa el `git diff` contra los 11 criterios, ejecuta lo que pueda (`php -l` en cada archivo tocado, y la BD si hay MySQL disponible), busca consultas a `sucursales` que se hayan escapado del filtro (`grep -rn "sucursales"`), y devuelve la lista de fallos. La IA corrige y Claude repite hasta que los 11 pasen.
