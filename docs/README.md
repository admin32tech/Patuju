# 🥟 PATUJU POS — Sistema de Punto de Venta Multi-Sucursal

Sistema web de **Punto de Venta (POS)** e inventarios para salteñería boliviana con arquitectura multi-sucursal, control de turnos de caja, panel de encargados y administración general.

---

## 🚀 Despliegue en Entorno Local (Desarrollo)

### Requisitos Previos
- PHP 7.4 o superior con extensión PDO MySQL habilitada.
- Servidor MySQL / MariaDB.
- Servidor Web Apache (Laragon, XAMPP o PHP Built-in server).

### Pasos de Instalación

1. **Clonar o Copiar el Proyecto:**
   Copiar la carpeta `patuju1000` dentro del directorio web de tu servidor (`C:\laragon\www\` o `C:\xampp\htdocs\`).

2. **Configurar la Base de Datos:**
   - Abrir gestor MySQL (phpMyAdmin, HeidiSQL, etc.).
   - Importar el archivo unificado:
     ```bash
     mysql -u root -p < patuju1000/database/schema.sql
     ```
   - Este comando creará la base de datos `patuju_pos` con las 15 sucursales, productos iniciales y 17 cuentas de usuario pre-configuradas.

3. **Variables de Entorno (`.env`):**
   Verificar o crear el archivo `.env` en la raíz de `patuju1000/`:
   ```ini
   DB_HOST=localhost
   DB_NAME=patuju_pos
   DB_USER=root
   DB_PASS=
   ```

4. **Acceso al Sistema:**
   Navegar a `http://localhost/patuju1000/`. El sistema cargará por defecto la **Landing Page pública**.
   - Haz clic en **"Ingresar al Sistema"** para iniciar sesión con cualquier usuario de prueba (contraseña por defecto: `patuju2024`).

---

## 📁 Estructura del Proyecto (`patuju1000/`)

```
patuju1000/
├── landing.php            ← Portal público
├── login.php              ← Vista de autenticación
├── index.php              ← Vista POS (Caja)
├── encargado.php          ← Vista Panel Encargado (Inventario sucursal)
├── admin.php              ← Vista Panel Admin (Global)
├── logout.php             ← Cierre de sesión
├── config/
│   ├── database.php       ← Conexión PDO con lectura de .env
│   └── auth.php           ← Helpers de autenticación y permisos
├── ajax/
│   ├── login.php          ← Endpoint de login y auto-rehash
│   ├── productos.php      ← Catálogo por sucursal
│   ├── registrar_venta.php← Registro de venta y descuento de stock
│   ├── historial.php      ← Historial de ventas filtrado por sucursal
│   ├── turno_caja.php     ← Apertura y cierre de caja (Corte Z)
│   ├── stock.php          ← Gestión de lotes e inventario por encargado
│   ├── crud_productos.php ← CRUD global de productos
│   └── analytics.php      ← Dashboard de analítica gerencial y reportes CSV
├── database/
│   └── schema.sql         ← Esquema unificado + 15 sucursales + seeders
└── docs/                  ← Repositorio de documentación en Markdown
```

---

## 📄 Documentación Relacionada

Para el mapa completo de documentación y guías especializadas, consulta el archivo [RELACION_DOCUMENTOS.md](file:///home/vboxuser/Documents/adwn1/adown/patuju1000/docs/RELACION_DOCUMENTOS.md).
