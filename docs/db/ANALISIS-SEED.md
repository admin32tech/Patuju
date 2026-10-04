# Análisis: ¿Seed PHP o INSERTs en SQL?

**Contexto Patuju POS** — PHP vanilla, Laragon/XAMPP, 11 tablas, ~280 filas iniciales, beta.

---

## Los 2 Caminos

### Camino A: INSERTs en `schema.sql` (lo actual)

```
database/schema.sql = CREATE TABLEs + INSERT INTOs
```

Un solo archivo. Copias, pegas en phpMyAdmin o `mysql < schema.sql`, y listo.

### Camino B: Seed PHP separado

```
database/schema.sql  = solo CREATE TABLEs (estructura)
database/seed.php    = INSERTs via PHP (datos)
```

Dos pasos: primero importas schema, luego ejecutas `php seed.php`.

---

## Comparación Directa

| Criterio | INSERTs en SQL | Seed PHP |
|---|---|---|
| **Complejidad** | 1 archivo, 1 paso | 2 archivos, 2 pasos |
| **Hash bcrypt** | ❌ No puedes generar hash dinámico en SQL puro | ✅ `password_hash()` genera hash real en cada ejecución |
| **Idempotencia** | ❌ Ejecutar 2 veces = error de duplicados (a menos que uses `INSERT IGNORE` o `ON DUPLICATE KEY`) | ✅ Puedes hacer `TRUNCATE` + re-insert o `ON DUPLICATE KEY` fácilmente |
| **Datos condicionales** | ❌ No hay lógica — SQL es declarativo | ✅ Puedes variar datos por entorno (dev vs prod) |
| **Dependencias** | Ninguna — solo MySQL | Necesita PHP CLI + config/database.php |
| **phpMyAdmin** | ✅ Copy-paste directo | ❌ No se puede ejecutar desde phpMyAdmin |
| **Portabilidad** | ✅ Cualquier herramienta MySQL lo ejecuta | Solo PHP |
| **Deploy en hosting compartido** | ✅ Importar .sql desde cPanel | ❌ Muchos hostings no dan acceso a PHP CLI |
| **Velocidad de setup** | 1 paso | 2 pasos |
| **Mantenimiento** | Editar SQL a mano | Editar arrays PHP |

---

## El Problema Central: Los Hashes

Este es el **único** punto donde SQL puro no puede competir con PHP:

```sql
-- SQL: tienes que pegar un hash pre-generado
INSERT INTO usuarios (..., password_hash, ...) VALUES
('admin', '$2y$10$HASH_QUE_TIENES_QUE_GENERAR_APARTE', ...);
```

```php
// PHP: lo genera al vuelo, siempre correcto
$hash = password_hash('patuju2024', PASSWORD_BCRYPT);
$stmt->execute(['admin', $hash, ...]);
```

Con SQL puro, cada vez que cambias la contraseña tienes que:
1. Abrir una terminal PHP
2. Generar el hash
3. Pegarlo en el SQL
4. Rezar que no se te corte un carácter

Con PHP seed, solo cambias el string `'patuju2024'` y listo.

---

## Tu Situación Específica

| Factor | Implicación |
|---|---|
| Usas Laragon | Tienes PHP CLI disponible localmente |
| 15 sucursales en Bolivia | Deploy probablemente en hosting compartido (cPanel) |
| Beta, equipo pequeño | No necesitas complejidad de migraciones |
| `actualizar_bd.php` ya existe | Ya tienen un patrón de scripts PHP para BD |
| El hash fabricado rompió el login | Esto es directamente causado por usar SQL para algo que requiere PHP |

---

## Veredicto

### → Mantener INSERTs en SQL + 1 micro-seed solo para hashes

**No** migrar todo a seed PHP. **No** cambiar lo que ya funciona. Solo resolver el problema puntual.

**Por qué:**

1. Tu flujo de trabajo es `schema.sql` → phpMyAdmin → funciona. Agregar un paso PHP a la cadena genera fricción innecesaria para un equipo en beta.

2. El 95% de tus datos iniciales (categorías, sucursales, productos, stock) son **estáticos y no necesitan lógica**. SQL los maneja perfecto.

3. El **único** dato que necesita PHP es el hash de contraseña. Matar una mosca con un cañón (migrar todo a seed) por un solo campo no vale la pena.

4. Cuando vayas a producción, los usuarios van a tener contraseñas propias — los INSERTs de usuarios desaparecen del schema de todas formas.

---

## Implementación Recomendada

### Paso 1: Separar usuarios del schema

En `database/schema.sql`, **sacar** los INSERTs de usuarios. Dejar solo estructura + datos estáticos:

```sql
-- schema.sql contiene:
-- CREATE TABLEs (todas)
-- INSERT categorias ✅
-- INSERT sucursales ✅  
-- INSERT productos ✅
-- INSERT stock_sucursal ✅
-- INSERT usuarios ❌ → se mueve a paso 2
```

### Paso 2: Crear `database/init_usuarios.php`

Un script mínimo (no un seed completo) que solo inserta usuarios con hashes reales:

```php
<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$hash = password_hash('patuju2024', PASSWORD_BCRYPT);

$usuarios = [
    ['admin', $hash, 'Administrador General', 'admin', NULL],
    ['encargado_encargado_central', $hash, 'Encargado Central', 'encargado', 1],
    // ... resto de usuarios
];

$stmt = $db->prepare("INSERT IGNORE INTO usuarios 
    (username, password_hash, nombre_display, rol, sucursal_id, activo) 
    VALUES (?, ?, ?, ?, ?, 1)");

foreach ($usuarios as $u) {
    $stmt->execute($u);
}
echo "✅ " . count($usuarios) . " usuarios insertados con hash bcrypt válido.\n";
```

### Paso 3: Setup en 2 comandos

```bash
mysql -u root < database/schema.sql     # estructura + datos estáticos
php database/init_usuarios.php           # usuarios con hash real
```

O desde phpMyAdmin: importar schema.sql, luego abrir `init_usuarios.php` en el navegador.

### Resultado

- ✅ Patuju-publico/database/schema.sql Schema SQL sigue siendo tu fuente de verdad para estructura y datos estáticos
- ✅ Hashes siempre válidos, generados por PHP
- ✅ Solo 1 archivo PHP nuevo (~30 líneas), no un sistema de seed completo
- ✅ `actualizar_bd.php` sigue funcionando para migraciones incrementales
- ✅ Compatible con phpMyAdmin y hosting compartido
