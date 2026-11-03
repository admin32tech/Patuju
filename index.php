<?php
require_once __DIR__ . '/config/auth.php';
verificarSesion('caja');

if (!asegurarSucursalAdmin()) {
    if ($_SESSION['usuario_rol'] === 'admin') {
        http_response_code(503);
        echo '<!DOCTYPE html><meta charset="UTF-8"><title>Caja no disponible</title>'
           . '<body style="font-family:sans-serif;max-width:32rem;margin:4rem auto;line-height:1.5">'
           . '<h1>Caja no disponible</h1>'
           . '<p>No existe la sucursal interna de administración. Ejecuta <code>actualizar_bd.php</code> y vuelve a intentar.</p>'
           . '<p><a href="admin.php">Volver a Admin</a></p>';
        exit;
    }
    header('Location: logout.php?error=sin_sucursal');
    exit;
}

$sucursal = obtenerSucursalActual();
$sucursalNombre = $sucursal ? $sucursal['nombre'] : ($_SESSION['sucursal_nombre'] ?? 'Admin');
$sucursalId = $_SESSION['sucursal_id'] ?? 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Patuju POS — Sistema de Caja para Salteñería">
    <title>Patuju POS — Caja</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
</head>
<body data-sucursal-id="<?= $sucursalId ?>" data-sucursal-nombre="<?= htmlspecialchars($sucursalNombre) ?>">

<div class="app">

    <!-- ── Topbar ────────────────────────────── -->
    <header class="topbar" id="topbar">
        <div class="topbar__brand">
            <div>
                <div class="topbar__title">PATUJU POS</div>
                <div class="topbar__subtitle"><?= htmlspecialchars($sucursalNombre) ?></div>
            </div>
        </div>
        <div class="topbar__actions">
            <div class="topbar__sucursal" style="display:flex;align-items:center;gap:0.4rem;padding:0.35rem 0.8rem;background:var(--color-surface-2);border-radius:var(--radius-full);font-size:0.85rem;">
                <svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#pin"></use></svg>
                <span><?= htmlspecialchars($sucursalNombre) ?></span>
            </div>
            <div class="topbar__total-dia" id="total-dia-wrap">
                <span id="total-dia">Bs. 0.00 (0 ventas)</span>
            </div>
            <span class="topbar__clock" id="clock">00:00:00</span>
            <button class="btn btn--primary" onclick="App.abrirModalGestionTurno()" id="btn-turno" style="background:var(--color-primary);"><svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#clock"></use></svg> Turno</button>
            <button class="btn" onclick="App.verHistorial()" id="btn-historial"><svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#history"></use></svg> Historial</button>
            <?php if ($_SESSION['usuario_rol'] === 'admin' || $_SESSION['usuario_rol'] === 'encargado'): ?>
            <a href="encargado.php" class="btn" id="btn-encargado" title="Panel de Sucursal"><svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#store"></use></svg> Sucursal</a>
            <?php endif; ?>
            <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
            <a href="admin.php" class="btn" id="btn-admin">Admin</a>
            <?php endif; ?>
            <a href="logout.php" class="btn" style="color:var(--color-danger)"><svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#logout"></use></svg> Salir</a>
        </div>
    </header>

    <!-- ── Catálogo (Izquierda) ──────────────── -->
    <main class="catalogo" id="catalogo">
        <div class="cat-filter" id="cat-filters"></div>
        <div id="catalogo-productos">
            <p style="color:var(--color-text-muted);text-align:center;padding:3rem;">
                Cargando productos...
            </p>
        </div>
    </main>

    <!-- ── Caja / Carrito (Derecha) ──────────── -->
    <div class="caja__backdrop" id="caja-backdrop"></div>
    <aside class="caja" id="caja" aria-label="Carrito de venta">
        <button type="button" class="caja__handle" id="btn-toggle-carrito" aria-expanded="false" aria-controls="caja-items">
            <span class="caja__handle-info">
                <span class="caja__handle-label">Carrito <span class="caja__badge" id="caja-badge-mobile">0</span></span>
                <span class="caja__handle-total" id="caja-total-mobile">Bs. 0.00</span>
            </span>
            <span class="caja__handle-action" aria-hidden="true"></span>
        </button>
        <div class="caja__header">
            <h2 class="caja__title">
                Caja
                <span class="caja__badge" id="caja-badge">0</span>
            </h2>
        </div>

        <div class="caja__items" id="caja-items">
            <div class="caja__empty">
                <span class="caja__empty-icon"><svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#cart"></use></svg></span>
                <span>Toca un producto para agregarlo</span>
            </div>
        </div>

        <div class="caja__footer">
            <div class="caja__total-row">
                <span class="caja__total-label">Total a cobrar</span>
                <span class="caja__total-amount" id="caja-total">0.00</span>
            </div>
            <div class="caja__actions">
                <button class="btn-cobrar" id="btn-cobrar" onclick="App.registrarVenta(false)" disabled>
                    <span class="btn-cobrar__icon"><svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#cash"></use></svg></span> COBRAR
                </button>
                <button class="btn-cobrar btn-cobrar--print" id="btn-cobrar-imprimir" onclick="App.registrarVenta(true)" disabled>
                    <span class="btn-cobrar__icon"><svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#printer"></use></svg></span> COBRAR E IMPRIMIR
                </button>
            </div>
        </div>
    </aside>

</div>

<!-- ── Ticket imprimible para miniprinter (Oculto en pantalla) ── -->
<div id="ticket-print" class="ticket-print"></div>

<!-- ── Modal (Historial) ─────────────────────── -->
<div class="modal-overlay" id="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="modal">
        <div class="modal__header">
            <h3 class="modal__title" id="modal-title">Historial</h3>
            <button class="modal__close" onclick="App.cerrarModal()" aria-label="Cerrar historial"><svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#x"></use></svg></button>
        </div>
        <div class="modal__body" id="modal-body"></div>
    </div>
</div>

<!-- ── Toasts ────────────────────────────────── -->
<div class="toast-container" id="toast-container" role="status" aria-live="polite" aria-atomic="false"></div>


<!-- ── Modal Apertura Turno ────────────────────── -->
<div class="modal-overlay" id="modal-abrir-turno" role="dialog" aria-modal="true" aria-labelledby="titulo-abrir-turno">
    <div class="modal" style="max-width:400px;text-align:center;">
        <div class="modal__header">
            <h3 class="modal__title" id="titulo-abrir-turno">Apertura de Caja</h3>
        </div>
        <div class="modal__body" style="padding:2rem;">
            <p style="margin-bottom:1.5rem;color:var(--color-text-dim);">Debes abrir un turno para empezar a vender.</p>
            <label style="display:block;text-align:left;font-size:0.9rem;margin-bottom:0.5rem;">Fondo Inicial (Bs.)</label>
            <input type="number" id="turno-monto-apertura" step="0.50" min="0" value="0.00" style="width:100%;padding:0.8rem;font-size:1.2rem;margin-bottom:1.5rem;background:var(--color-surface-2);border:1px solid var(--color-border);color:var(--color-text);border-radius:6px;">
            <button onclick="App.abrirTurno()" style="width:100%;background:var(--color-success);color:#fff;padding:1rem;border:none;border-radius:6px;font-size:1.1rem;font-weight:bold;cursor:pointer;">
                Abrir Turno
            </button>
        </div>
    </div>
</div>

<!-- ── Modal Cobro ──────────────────────────────── -->
<div class="modal-overlay" id="modal-cobro" role="dialog" aria-modal="true" aria-labelledby="titulo-cobro">
    <div class="modal" style="max-width:450px;">
        <div class="modal__header">
            <h3 class="modal__title" id="titulo-cobro">Confirmar Cobro</h3>
            <button class="modal__close" onclick="App.cerrarModalCobro()" aria-label="Cerrar cobro"><svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#x"></use></svg></button>
        </div>
        <div class="modal__body" style="padding:1.5rem;">
            <div style="font-size:2rem;text-align:center;font-weight:bold;margin-bottom:1.5rem;color:var(--color-primary-ink);">
                Total: Bs. <span id="cobro-total-label">0.00</span>
            </div>

            <div style="display:flex;gap:1rem;margin-bottom:1.5rem;">
                <label class="btn" style="flex:1;text-align:center;background:var(--color-surface-2);cursor:pointer;">
                    <input type="radio" name="metodo_pago" value="efectivo" checked onclick="App.togglePagoEfectivo(true)"> Efectivo
                </label>
                <label class="btn" style="flex:1;text-align:center;background:var(--color-surface-2);cursor:pointer;">
                    <input type="radio" name="metodo_pago" value="qr" onclick="App.togglePagoEfectivo(false)"> QR
                </label>
                <label class="btn" style="flex:1;text-align:center;background:var(--color-surface-2);cursor:pointer;">
                    <input type="radio" name="metodo_pago" value="tarjeta" onclick="App.togglePagoEfectivo(false)"> Tarjeta
                </label>
            </div>

            <div id="cobro-efectivo-details">
                <label style="display:block;font-size:0.9rem;margin-bottom:0.5rem;color:var(--color-text-dim);">Efectivo Recibido (Bs.)</label>
                <input type="number" id="cobro-recibido" step="0.50" min="0" placeholder="0.00" oninput="App.calcularVuelto()" style="width:100%;padding:1rem;font-size:1.5rem;margin-bottom:1rem;background:var(--color-surface-2);border:1px solid var(--color-border);color:var(--color-text);border-radius:6px;text-align:right;">

                <div style="display:flex;justify-content:space-between;align-items:center;font-size:1.2rem;margin-bottom:1.5rem;padding:1rem;background:var(--color-surface-2);border-radius:6px;">
                    <span>Cambio:</span>
                    <strong id="cobro-cambio" style="color:var(--color-success);">Bs. 0.00</strong>
                </div>
            </div>

            <button id="btn-confirmar-cobro" onclick="App.procesarVentaConfirmada()" style="width:100%;background:var(--color-primary);color:var(--color-on-primary);padding:1rem;border:none;border-radius:6px;font-size:1.1rem;font-weight:bold;cursor:pointer;">
                Confirmar y Registrar Venta
            </button>
        </div>
    </div>
</div>

<!-- ── Modal Gestión de Turno (Corte X/Z) ────────── -->
<div class="modal-overlay" id="modal-gestion-turno" role="dialog" aria-modal="true" aria-labelledby="titulo-gestion-turno">
    <div class="modal" style="max-width:450px;">
        <div class="modal__header">
            <h3 class="modal__title" id="titulo-gestion-turno">Gestión de Turno</h3>
            <button class="modal__close" onclick="App.cerrarModalGestionTurno()" aria-label="Cerrar gestión de turno"><svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#x"></use></svg></button>
        </div>
        <div class="modal__body" style="padding:1.5rem;">
            <div id="turno-info" style="margin-bottom:1.5rem;line-height:1.6;font-size:0.95rem;">
                <!-- Info llenada por JS -->
            </div>

            <div style="margin-bottom:1.5rem;border-top:1px solid var(--color-border);padding-top:1rem;">
                <h4 style="margin-bottom:0.8rem;color:var(--color-warning);">Registrar Egreso (Gastos Menores)</h4>
                <div style="display:flex;gap:0.5rem;margin-bottom:0.5rem;">
                    <input type="number" id="egreso-monto" placeholder="Monto (Bs.)" step="0.50" min="0.50" style="width:30%;padding:0.6rem;background:var(--color-surface-2);border:1px solid var(--color-border);color:var(--color-text);border-radius:6px;">
                    <input type="text" id="egreso-motivo" placeholder="Motivo (ej. pasajes)" style="flex:1;padding:0.6rem;background:var(--color-surface-2);border:1px solid var(--color-border);color:var(--color-text);border-radius:6px;">
                    <button onclick="App.registrarEgreso()" class="btn btn--primary">Ok</button>
                </div>
            </div>

            <div style="border-top:1px solid var(--color-border);padding-top:1rem;margin-bottom:1.5rem;">
                <h4 style="margin-bottom:0.8rem;color:var(--color-danger);">Cierre de Caja (Corte Z)</h4>
                <label style="display:block;font-size:0.85rem;margin-bottom:0.4rem;color:var(--color-text-dim);">Efectivo contado en caja (Bs.)</label>
                <input type="number" id="cierre-monto" step="0.50" min="0" placeholder="0.00" style="width:100%;padding:0.8rem;font-size:1.2rem;margin-bottom:1rem;background:var(--color-surface-2);border:1px solid var(--color-border);color:var(--color-text);border-radius:6px;text-align:right;">

                <button onclick="App.cerrarTurno()" style="width:100%;background:var(--color-danger);color:#fff;padding:0.8rem;border:none;border-radius:6px;font-size:1rem;font-weight:bold;cursor:pointer;">
                    Realizar Cierre Definitivo
                </button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/app.js"></script>
</body>
</html>
