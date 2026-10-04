# 🥟 PATUJU POS — Documentación de Actualización y Pruebas

## 📌 Resumen de Evolución del Sistema

El sistema **PATUJU POS** ha evolucionado exitosamente a una plataforma web multi-sucursal modular y segura.

---

## 🔑 Cuentas de Acceso y Credenciales de Prueba

> **Contraseña por defecto para todas las cuentas:** `patuju2024`  
> *(Al iniciar sesión por primera vez, el sistema realiza automáticamente el auto-rehash seguro a bcrypt nativo).*

### 1. Administración General
| Usuario | Contraseña | Rol | Alcance | Vista Redirección |
|---------|------------|-----|---------|-------------------|
| `admin` | `patuju2024` | `admin` | Global (15 sucursales) | `admin.php` |

### 2. Encargados de Sucursal (Ejemplos)
| Usuario | Contraseña | Rol | Sucursal Vinculada | Vista Redirección |
|---------|------------|-----|--------------------|-------------------|
| `encargado_central` | `patuju2024` | `encargado` | Patuju Central (La Paz) | `encargado.php` |
| `encargado_sopocachi` | `patuju2024` | `encargado` | Patuju Sopocachi (La Paz) | `encargado.php` |
| `encargado_cbba` | `patuju2024` | `encargado` | Patuju Cochabamba Centro | `encargado.php` |

### 3. Cajeros por Sucursal (15 Sucursales)
| # | Usuario | Contraseña | Sucursal | Ciudad | Vista Redirección |
|---|---------|------------|----------|--------|-------------------|
| 1 | `caja_central` | `patuju2024` | Patuju Central | La Paz | `index.php` |
| 2 | `caja_sopocachi` | `patuju2024` | Patuju Sopocachi | La Paz | `index.php` |
| 3 | `caja_miraflores` | `patuju2024` | Patuju Miraflores | La Paz | `index.php` |
| 4 | `caja_sanmiguel` | `patuju2024` | Patuju San Miguel | La Paz | `index.php` |
| 5 | `caja_calacoto` | `patuju2024` | Patuju Calacoto | La Paz | `index.php` |
| 6 | `caja_cbba_centro` | `patuju2024` | Patuju Cochabamba Centro | Cochabamba | `index.php` |
| 7 | `caja_cbba_norte` | `patuju2024` | Patuju Cochabamba Norte | Cochabamba | `index.php` |
| 8 | `caja_scz_centro` | `patuju2024` | Patuju Santa Cruz Centro | Santa Cruz | `index.php` |
| 9 | `caja_scz_equip` | `patuju2024` | Patuju Equipetrol | Santa Cruz | `index.php` |
| 10 | `caja_sucre` | `patuju2024` | Patuju Sucre | Sucre | `index.php` |
| 11 | `caja_oruro` | `patuju2024` | Patuju Oruro | Oruro | `index.php` |
| 12 | `caja_potosi` | `patuju2024` | Patuju Potosí | Potosí | `index.php` |
| 13 | `caja_tarija` | `patuju2024` | Patuju Tarija | Tarija | `index.php` |
| 14 | `caja_trinidad` | `patuju2024` | Patuju Trinidad | Trinidad | `index.php` |
| 15 | `caja_elalto` | `patuju2024` | Patuju El Alto | El Alto | `index.php` |

---

## 🧪 Guía Paso a Paso para Pruebas

1. **Importar Esquema:**
   Ejecutar `patuju1000/database/schema.sql` en MySQL.

2. **Probar Registro de Venta y Turno:**
   - Iniciar sesión como `caja_central` (`patuju2024`).
   - El sistema solicitará la **Apertura de Turno** (ingresar Ej: `100.00`).
   - Agregar productos al carrito y presionar **COBRAR**.
   - Ingresar el monto pagado (Ej: `50.00`) y confirmar el cobro.
   - Verificar la impresión de ticket térmico.
   - Al finalizar, presionar **Cierre de Caja** para efectuar el arqueo Corte Z.

3. **Probar Panel de Encargado:**
   - Iniciar sesión como `encargado_central` (`patuju2024`).
   - Probar el **Ingreso de Mercadería por Lote** para aumentar la disponibilidad de stock.
   - Probar el ajuste de precio local por sucursal.

4. **Probar Panel de Administración:**
   - Iniciar sesión como `admin` (`patuju2024`).
   - Consultar el **Stock Consolidado** de las 15 sucursales.
   - Verificar la auditoría de ventas y cierres de turno a nivel nacional.