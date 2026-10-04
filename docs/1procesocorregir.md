# ⚙️ Ajustes Localhost vs Producción — PATUJU POS

Documento técnico de referencia para diferenciar qué funciona en desarrollo local (sin HTTPS) de lo que se activa solo en producción.

---

## Contexto Real del Entorno

| Parámetro | Estado Actual |
|---|---|
| Protocolo | **HTTP** (red interna / localhost) |
| Servidor | Apache en LAN o `localhost` |
| HTTPS | ❌ No disponible en este entorno |
| Servidor de correo | ❌ No configurado |
| Dominio público | ❌ No aplica aún |

> ℹ️ Este sistema opera en una **red interna sin TLS**. Las decisiones de seguridad están adaptadas a esta realidad.

---

## 🟢 Bloqueante ahora — Ya implementado y funcional

### Cookies de Sesión (`config/auth.php`)

```php
$secureFlag = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $secureFlag,   // false en HTTP, true automático en HTTPS
    'httponly' => true,          // ✅ Siempre activo — protege contra XSS
    'samesite' => 'Strict',      // ✅ Siempre activo — protege contra CSRF
]);
```

**¿Por qué es correcto así?**
- `httponly: true` → La cookie **nunca es accesible desde JavaScript**. Protege contra XSS sin depender de HTTPS.
- `samesite: Strict` → El navegador **no envía la cookie en requests de otros sitios**. Funciona en HTTP.
- `secure: false` en HTTP → **Correcto y necesario.** Si fuera `true` en HTTP, el navegador descartaría la cookie y ningún usuario podría iniciar sesión.
- La detección automática `$secureFlag` activará `secure: true` **sin ningún cambio de código** cuando se despliegue con HTTPS.

### Autenticación y Sesiones

- ✅ `session_regenerate_id(true)` tras login (previene session fixation)
- ✅ Rate limiting en login (bloqueo tras intentos fallidos)
- ✅ Auto-rehash bcrypt al iniciar sesión
- ✅ Todos los endpoints AJAX protegidos por `verificarSesionAjax()`

---

## 🟡 Diferido — Se activa en producción con HTTPS

### Cookie `Secure`
- **Acción requerida:** Ninguna. El código ya detecta HTTPS automáticamente y activa el flag.
- **Cuándo:** Al desplegar con certificado SSL/TLS.

### Cabeceras HTTP de Seguridad (`.htaccess`)
Las siguientes cabeceras están en `.htaccess` pero **solo tienen efecto real en producción con HTTPS**:
```
Strict-Transport-Security (HSTS)
Content-Security-Policy
X-Frame-Options
X-Content-Type-Options
```
- **Acción requerida:** Ninguna. El `.htaccess` ya las incluye; en localhost simplemente no tienen impacto visible.

---

## ❌ Fuera de scope — No se implementará

| Función | Razón |
|---|---|
| Recuperación de contraseña vía correo | Sin servidor de mail + fuera de scope del sistema |
| Cambio de contraseña por usuario | Eliminado — administración de credenciales vía DB directamente |
| Reset tokens / links de recuperación | Ídem |

> **Nota:** Las contraseñas se gestionan directamente en la base de datos con `password_hash()` por el administrador del sistema. Esta es la política definida para este proyecto.

---

## Referencia

- [`config/auth.php`](../config/auth.php) — Implementación de cookies y verificación de sesión
- [`ROADMAP.md`](./ROADMAP.md) — Hoja de ruta maestra del proyecto