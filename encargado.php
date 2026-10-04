<?php
require_once __DIR__ . '/config/auth.php';
verificarSesion('encargado');
$sucursal = obtenerSucursalActual();
$sucursalNombre = $sucursal ? $sucursal['nombre'] : ($_SESSION['sucursal_nombre'] ?? 'Mi Sucursal');
$sucursalId = (int) ($_SESSION['sucursal_id'] ?? 0);
$usuarioNombre = $_SESSION['usuario_nombre'] ?? 'Encargado';
$rol = $_SESSION['usuario_rol'] ?? 'encargado';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Patuju POS — Panel de Gestión de Sucursales">
    <title>Patuju POS — Panel de Sucursales (<?= htmlspecialchars($sucursalNombre) ?>)</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🏬</text></svg>">
</head>
<body data-sucursal-id="<?= $sucursalId ?>" data-sucursal-nombre="<?= htmlspecialchars($sucursalNombre) ?>" data-usuario-rol="<?= htmlspecialchars($rol) ?>">

<div class="app" style="grid-template-columns: 1fr; min-height: 100vh;">

    <!-- ── Topbar ────────────────────────────── -->
    <header class="topbar">
        <div class="topbar__brand">
            <span class="topbar__logo">🏬</span>
            <div>
                <div class="topbar__title">PANEL DE SUCURSAL</div>
                <div class="topbar__subtitle" id="topbar-subtitulo"><?= htmlspecialchars($sucursalNombre) ?></div>
            </div>
        </div>
        <div class="topbar__actions">
            <div id="topbar-sucursal-badge" class="topbar__sucursal" style="display:flex;align-items:center;gap:0.4rem;padding:0.35rem 0.8rem;background:var(--color-surface-2);border-radius:var(--radius-full);font-size:0.85rem;">
                <span>📍</span>
                <span id="topbar-sucursal-nombre"><?= htmlspecialchars($sucursalNombre) ?></span>
            </div>
            <div style="display:flex;align-items:center;gap:0.4rem;padding:0.35rem 0.8rem;background:var(--color-surface-2);border-radius:var(--radius-full);font-size:0.85rem;color:var(--color-primary);">
                <span>👤</span>
                <span><?= htmlspecialchars($usuarioNombre) ?> (Encargado)</span>
            </div>
            <span class="topbar__clock" id="clock">00:00:00</span>
            <a href="index.php" class="btn btn--primary" id="btn-ir-caja">🥟 Ir a Caja POS</a>
            <a href="logout.php" class="btn" style="color:var(--color-danger)">🚪 Salir</a>
        </div>
    </header>

    <!-- ── Contenedor Principal ──────────────── -->
    <main style="padding: 1.5rem; max-width: 1400px; margin: 0 auto; width: 100%;">

        <!-- ═══════════════════════════════════════════════════════ -->
        <!-- VISTA 1: VISTA PRINCIPAL — TARJETAS DE SUCURSALES       -->
        <!-- ═══════════════════════════════════════════════════════ -->
        <section id="vista-sucursales">
            <!-- Header de Sucursales -->
            <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1.5rem;">
                <div>
                    <h2 style="font-size: 1.5rem; font-weight: 800; color: var(--color-text); margin-bottom: 0.25rem; display: flex; align-items: center; gap: 0.5rem;">
                        <span>🏬</span> Sucursales Asignadas
                    </h2>
                    <p style="font-size: 0.9rem; color: var(--color-text-muted);">
                        Selecciona una sucursal para consultar su inventario, ingresar lotes de mercadería o ajustar precios.
                    </p>
                </div>
                <div style="display: flex; gap: 0.75rem; align-items: center;">
                    <div style="display: flex; align-items: center; gap: 0.4rem; padding: 0.4rem 0.9rem; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: 0.85rem;">
                        <span>📅</span>
                        <span id="fecha-hoy-badge" style="font-weight: 600; color: var(--color-text);"><?= date('d/m/Y') ?></span>
                    </div>
                    <?php if ($rol === 'admin' || $sucursalId > 0): ?>
                    <button class="btn" onclick="Encargado.cargarResumenSucursales()" title="Recargar estado de sucursales">
                        🔄 Refrescar
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Grid de Tarjetas de Sucursales -->
            <div id="grid-sucursales-container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 1.5rem;">
                <div style="grid-column: 1/-1; text-align: center; color: var(--color-text-muted); padding: 4rem 1rem; background: var(--color-surface); border-radius: var(--radius-lg); border: 1px solid var(--color-border);">
                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">⏳</div>
                    <div>Cargando estado de sucursales...</div>
                </div>
            </div>
        </section>

        <!-- ═══════════════════════════════════════════════════════ -->
        <!-- VISTA 2: VISTA DETALLE — GESTIÓN Y CATÁLOGO DE SUCURSAL  -->
        <!-- ═══════════════════════════════════════════════════════ -->
        <section id="vista-detalle-sucursal" style="display: none;">
            <!-- Barra de Navegación y Volver -->
            <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1.25rem; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 0.8rem 1.25rem;">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <button class="btn" onclick="Encargado.volverASucursales()" style="display: flex; align-items: center; gap: 0.4rem; font-weight: 600; padding: 0.5rem 1rem;">
                        <span>←</span> Volver a Sucursales
                    </button>
                    <div style="height: 24px; width: 1px; background: var(--color-border);"></div>
                    <div>
                        <div style="font-size: 0.75rem; color: var(--color-text-muted); text-transform: uppercase;">Gestión de Sucursal</div>
                        <h2 id="detalle-sucursal-titulo" style="font-size: 1.25rem; font-weight: 800; color: var(--color-text);">Patuju Central</h2>
                    </div>
                </div>
                <div style="display: flex; gap: 0.6rem; align-items: center;">
                    <span id="detalle-turno-badge" style="padding: 0.35rem 0.8rem; border-radius: var(--radius-full); font-size: 0.8rem; font-weight: 700;">🟢 Turno Abierto</span>
                </div>
            </div>

            <!-- KPI Grid de la Sucursal -->
            <div class="kpi-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                <div class="kpi-card" style="background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 1.25rem;">
                    <div style="font-size: 0.85rem; color: var(--color-text-muted); margin-bottom: 0.25rem;">📦 Total Unidades en Stock</div>
                    <div id="kpi-stock-total" style="font-size: 1.8rem; font-weight: 800; color: var(--color-text);">0</div>
                </div>
                <div class="kpi-card" style="background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 1.25rem;">
                    <div style="font-size: 0.85rem; color: var(--color-text-muted); margin-bottom: 0.25rem;">⚠️ Alerta Stock Bajo</div>
                    <div id="kpi-stock-bajo" style="font-size: 1.8rem; font-weight: 800; color: var(--color-primary);">0</div>
                </div>
                <div class="kpi-card" style="background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 1.25rem;">
                    <div style="font-size: 0.85rem; color: var(--color-text-muted); margin-bottom: 0.25rem;">⛔ Productos Agotados</div>
                    <div id="kpi-stock-agotado" style="font-size: 1.8rem; font-weight: 800; color: var(--color-danger);">0</div>
                </div>
                <div class="kpi-card" style="background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 1.25rem;">
                    <div style="font-size: 0.85rem; color: var(--color-text-muted); margin-bottom: 0.25rem;">📊 Ventas del Día (Sucursal)</div>
                    <div id="kpi-ventas-dia" style="font-size: 1.8rem; font-weight: 800; color: var(--color-success);">Bs. 0.00</div>
                </div>
            </div>

            <!-- Acciones Rápidas y Filtros -->
            <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1.25rem; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 1rem;">
                <div style="display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: center;">
                    <button class="btn btn--primary" onclick="Encargado.abrirModalStockDiario()" style="font-size: 0.92rem; font-weight: 700; padding: 0.55rem 1.1rem; background: #e07a14; border-color: #d16b08;" title="Cargar lote predeterminado matutino de salteñas">
                        ⚡ Horneada Diaria
                    </button>
                    <button class="btn" onclick="Encargado.abrirModalLote()" style="font-size: 0.9rem; padding: 0.55rem 1rem;">
                        🚚 Ingresar Lote
                    </button>
                    <button class="btn" onclick="Encargado.ejecutarCierreMerma()" style="font-size: 0.9rem; padding: 0.55rem 0.9rem;" title="Cerrar salteñas del día y registrar sobrante como merma">
                        🌇 Merma Cierre
                    </button>
                    <button class="btn" onclick="Encargado.abrirModalMovimientos()" style="font-size: 0.9rem;">
                        📜 Movimientos
                    </button>
                    <button class="btn" onclick="Encargado.recargarSucursalActual()" title="Actualizar datos">
                        🔄
                    </button>
                </div>
                <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                    <!-- Filtro por Tipo de Rotación (Diaria vs Lenta) -->
                    <div style="display: flex; background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 2px;">
                        <button id="btn-filtro-todos" class="btn" style="padding: 0.35rem 0.7rem; font-size: 0.8rem; background: var(--color-primary); color: #fff; border-radius: var(--radius-sm);" onclick="Encargado.filtrarRotacion('todos')">
                            📦 Todos
                        </button>
                        <button id="btn-filtro-diaria" class="btn" style="padding: 0.35rem 0.7rem; font-size: 0.8rem; background: transparent; color: var(--color-text-muted); border-radius: var(--radius-sm);" onclick="Encargado.filtrarRotacion('diaria')">
                            🥟 Diarios (Salteñas)
                        </button>
                        <button id="btn-filtro-lenta" class="btn" style="padding: 0.35rem 0.7rem; font-size: 0.8rem; background: transparent; color: var(--color-text-muted); border-radius: var(--radius-sm);" onclick="Encargado.filtrarRotacion('lenta')">
                            🥤 Lentos (Bebidas)
                        </button>
                    </div>

                    <!-- Conmutador de vista Tarjetas vs Tabla -->
                    <div style="display: flex; background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 2px;">
                        <button id="btn-vista-cards" class="btn" style="padding: 0.35rem 0.7rem; font-size: 0.8rem; background: var(--color-primary); color: #fff; border-radius: var(--radius-sm);" onclick="Encargado.cambiarModoVista('cards')">
                            🎴 Tarjetas
                        </button>
                        <button id="btn-vista-tabla" class="btn" style="padding: 0.35rem 0.7rem; font-size: 0.8rem; background: transparent; color: var(--color-text-muted); border-radius: var(--radius-sm);" onclick="Encargado.cambiarModoVista('tabla')">
                            📋 Tabla
                        </button>
                    </div>
                    <input type="text" id="buscador-stock" placeholder="🔍 Buscar producto..." style="background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 0.45rem 0.75rem; color: var(--color-text); width: 180px;" oninput="Encargado.filtrarTabla()">
                </div>
            </div>

            <!-- VISTA DE TARJETAS (Predeterminada) -->
            <div id="cards-stock-wrap" style="margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--color-text);">📦 Catálogo de Productos — Haz clic en una tarjeta para editar precio o stock</h3>
                    <span id="contador-productos-cards" style="font-size: 0.85rem; color: var(--color-text-muted);">0 productos</span>
                </div>
                <div id="cards-stock-container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1.2rem;">
                    <!-- Renderizado dinámico de tarjetas por JS -->
                </div>
            </div>

            <!-- VISTA DE TABLA (Secundaria) -->
            <div id="tabla-stock-wrap" class="admin-table-wrap" style="background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); overflow: hidden; display: none;">
                <div class="admin-table-wrap__header" style="display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; background: var(--color-surface-2); font-weight: 700;">
                    <span>📦 Tabla Detallada de Inventario y Precios</span>
                    <span id="contador-productos" style="font-size: 0.85rem; color: var(--color-text-muted);">0 productos</span>
                </div>
                <div class="admin-table-wrap__body" style="overflow-x: auto;">
                    <table class="admin-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--color-border); text-align: left; font-size: 0.85rem; color: var(--color-text-muted);">
                                <th style="padding: 0.8rem 1rem;">Producto</th>
                                <th style="padding: 0.8rem 1rem;">Categoría</th>
                                <th style="padding: 0.8rem 1rem; text-align: center;">Stock Disponible</th>
                                <th style="padding: 0.8rem 1rem; text-align: center;">Alerta Mín.</th>
                                <th style="padding: 0.8rem 1rem; text-align: right;">Precio Sucursal</th>
                                <th style="padding: 0.8rem 1rem; text-align: center;">Estado</th>
                                <th style="padding: 0.8rem 1rem; text-align: center;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tabla-stock-body">
                            <tr><td colspan="7" style="text-align: center; color: var(--color-text-muted); padding: 3rem;">Cargando inventario...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

    </main>
</div>

<!-- ── Modal: Ingreso de Lote de Mercadería ──────────────── -->
<div class="modal-overlay" id="modal-lote" style="display: none;">
    <div class="modal" style="max-width: 750px; width: 90%;">
        <div class="modal__header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--color-border); padding-bottom: 0.75rem; margin-bottom: 1rem;">
            <h3 style="font-size: 1.2rem; display: flex; align-items: center; gap: 0.5rem;">
                🚚 Ingreso de Mercadería / Horneada
            </h3>
            <button class="modal__close" onclick="Encargado.cerrarModalLote()" style="background: none; border: none; color: var(--color-text-muted); font-size: 1.3rem; cursor: pointer;">✕</button>
        </div>
        <div class="modal__body">
            <p style="font-size: 0.9rem; color: var(--color-text-muted); margin-bottom: 1rem;">
                Ingresa la cantidad de unidades que acaban de llegar o salir del horno para sumarlas al inventario disponible de esta sucursal.
            </p>
            <div class="form-group" style="margin-bottom: 1rem;">
                <label for="lote-motivo" style="display: block; font-size: 0.85rem; margin-bottom: 0.3rem;">Motivo / Referencia:</label>
                <input type="text" id="lote-motivo" placeholder="Ej: Horneada mañana 07:00 AM o Recepción de fábrica" style="width: 100%; background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 0.6rem; color: var(--color-text);">
            </div>
            <div style="max-height: 350px; overflow-y: auto; border: 1px solid var(--color-border); border-radius: var(--radius-md); margin-bottom: 1rem;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                    <thead style="background: var(--color-surface-2); position: sticky; top: 0;">
                        <tr>
                            <th style="padding: 0.6rem 0.8rem; text-align: left;">Producto</th>
                            <th style="padding: 0.6rem 0.8rem; text-align: center;">Stock Actual</th>
                            <th style="padding: 0.6rem 0.8rem; text-align: center; width: 140px;">Unidades a Sumar</th>
                        </tr>
                    </thead>
                    <tbody id="lote-items-body">
                        <!-- Generado por JS -->
                    </tbody>
                </table>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                <button type="button" class="btn" onclick="Encargado.cerrarModalLote()">Cancelar</button>
                <button type="button" class="btn btn--primary" onclick="Encargado.guardarLote()" id="btn-guardar-lote">💾 Confirmar e Ingresar Stock</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Modal: Ajuste / Edición Integral de Producto ──────── -->
<div class="modal-overlay" id="modal-ajuste" style="display: none;">
    <div class="modal" style="max-width: 540px; width: 92%;">
        <div class="modal__header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--color-border); padding-bottom: 0.75rem;">
            <div>
                <h3 id="modal-ajuste-titulo" style="font-size: 1.15rem; font-weight: 700;">✏️ Editar Producto</h3>
                <div id="modal-ajuste-subtitulo" style="font-size: 0.8rem; color: var(--color-text-muted);">Stock actual: 0 unidades</div>
            </div>
            <button class="modal__close" onclick="Encargado.cerrarModalAjuste()" style="background: none; border: none; color: var(--color-text-muted); font-size: 1.3rem; cursor: pointer;">✕</button>
        </div>

        <!-- Pestañas de Acción del Modal -->
        <div style="display: flex; border-bottom: 1px solid var(--color-border); background: var(--color-surface-2);">
            <button id="tab-modal-precio" class="btn tab-modal-btn" onclick="Encargado.cambiarTabModal('precio')" style="flex: 1; border-radius: 0; padding: 0.65rem 0.5rem; font-size: 0.85rem; font-weight: 600; border-bottom: 2px solid var(--color-primary); color: var(--color-primary); background: transparent;">
                💰 Ajustar Precio
            </button>
            <button id="tab-modal-ingreso" class="btn tab-modal-btn" onclick="Encargado.cambiarTabModal('ingreso')" style="flex: 1; border-radius: 0; padding: 0.65rem 0.5rem; font-size: 0.85rem; font-weight: 600; border-bottom: 2px solid transparent; color: var(--color-text-muted); background: transparent;">
                🚚 Ingresar Stock
            </button>
            <button id="tab-modal-historial" class="btn tab-modal-btn" onclick="Encargado.cambiarTabModal('historial')" style="flex: 1; border-radius: 0; padding: 0.65rem 0.5rem; font-size: 0.85rem; font-weight: 600; border-bottom: 2px solid transparent; color: var(--color-text-muted); background: transparent;">
                📜 Historial
            </button>
        </div>

        <div class="modal__body" style="padding: 1.25rem;">
            <input type="hidden" id="ajuste-prod-id">

            <!-- TAB 1: AJUSTAR PRECIO Y PARÁMETROS -->
            <div id="panel-modal-precio">
                <div style="background: var(--color-surface-2); border-radius: var(--radius-md); padding: 0.8rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 0.85rem; color: var(--color-text-muted);">Precio Base General (Catálogo):</span>
                    <strong id="ajuste-precio-base-label" style="font-size: 1rem; color: var(--color-text);">Bs. 0.00</strong>
                </div>
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.85rem; margin-bottom: 0.3rem;">
                        Precio Específico en esta Sucursal (Bs.):
                    </label>
                    <input type="number" id="ajuste-precio" min="0" step="0.50" placeholder="Dejar vacío para usar precio base general" style="width: 100%; background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 0.6rem; color: var(--color-text); font-size: 1rem;">
                    <small style="display: block; margin-top: 0.25rem; font-size: 0.75rem; color: var(--color-text-muted);">Si dejas este campo vacío, la sucursal hereda automáticamente el precio base.</small>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
                    <div class="form-group">
                        <label style="display: block; font-size: 0.85rem; margin-bottom: 0.3rem;">Alerta Stock Mínimo:</label>
                        <input type="number" id="ajuste-alerta" min="0" step="1" style="width: 100%; background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 0.6rem; color: var(--color-text);">
                    </div>
                    <div class="form-group" id="wrap-ajuste-predeterminado">
                        <label style="display: block; font-size: 0.85rem; margin-bottom: 0.3rem;" title="Cantidad base diaria para apertura rápida">
                            Stock Diario Base:
                        </label>
                        <input type="number" id="ajuste-predeterminado" min="0" step="1" placeholder="Ej: 80" style="width: 100%; background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 0.6rem; color: var(--color-text);">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 1rem; background: var(--color-surface-2); border-radius: var(--radius-md); padding: 0.75rem 0.9rem; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong style="font-size: 0.85rem; color: var(--color-text); display: block;">Disponibilidad en Caja (CRUD Local)</strong>
                        <small style="font-size: 0.75rem; color: var(--color-text-muted);">Si lo pausas, el producto se oculta en el POS de tus cajeros.</small>
                    </div>
                    <select id="ajuste-disponible-select" style="background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-sm); padding: 0.4rem 0.6rem; color: var(--color-text); font-size: 0.85rem; font-weight: 600;">
                        <option value="1">🟢 Habilitado</option>
                        <option value="0">⛔ Pausado</option>
                    </select>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.5rem;">
                    <button type="button" class="btn" onclick="Encargado.cerrarModalAjuste()">Cancelar</button>
                    <button type="button" class="btn btn--primary" onclick="Encargado.guardarPrecioYAlerta()">💾 Guardar Parámetros</button>
                </div>
            </div>

            <!-- TAB 2: INGRESO DE MERCADERÍA -->
            <div id="panel-modal-ingreso" style="display: none;">
                <div style="background: var(--color-surface-2); border-radius: var(--radius-md); padding: 0.8rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 0.85rem; color: var(--color-text-muted);">Stock actual disponible:</span>
                    <strong id="ingreso-stock-actual-label" style="font-size: 1.1rem; color: var(--color-primary);">0 unidades</strong>
                </div>
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.85rem; margin-bottom: 0.3rem;">Cantidad de unidades a ingresar:</label>
                    <input type="number" id="ingreso-cantidad-unidades" min="1" step="1" placeholder="Ej: 50" style="width: 100%; background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 0.6rem; color: var(--color-text); font-size: 1.1rem; font-weight: 700;">
                </div>
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.85rem; margin-bottom: 0.3rem;">Motivo del Ingreso:</label>
                    <select id="ingreso-motivo-select" style="width: 100%; background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 0.6rem; color: var(--color-text); margin-bottom: 0.5rem;" onchange="Encargado.actualizarMotivoIngreso()">
                        <option value="Horneada mañana (07:00 AM)">Horneada mañana (07:00 AM)</option>
                        <option value="Horneada mediodía (11:00 AM)">Horneada mediodía (11:00 AM)</option>
                        <option value="Recepción de fábrica central">Recepción de fábrica central</option>
                        <option value="Transferencia de otra sucursal">Transferencia de otra sucursal</option>
                        <option value="otro">Otro motivo personalizado...</option>
                    </select>
                    <input type="text" id="ingreso-motivo-custom" placeholder="Escribe el motivo..." style="display: none; width: 100%; background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 0.6rem; color: var(--color-text);">
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.5rem;">
                    <button type="button" class="btn" onclick="Encargado.cerrarModalAjuste()">Cancelar</button>
                    <button type="button" class="btn btn--primary" onclick="Encargado.guardarIngresoMercaderia()">📦 Sumar al Stock</button>
                </div>
            </div>

            <!-- TAB 3: HISTORIAL DEL PRODUCTO -->
            <div id="panel-modal-historial" style="display: none;">
                <div style="max-height: 280px; overflow-y: auto; border: 1px solid var(--color-border); border-radius: var(--radius-md);">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.82rem;">
                        <thead style="background: var(--color-surface-2); position: sticky; top: 0;">
                            <tr>
                                <th style="padding: 0.5rem 0.7rem; text-align: left;">Fecha/Hora</th>
                                <th style="padding: 0.5rem 0.7rem; text-align: center;">Tipo</th>
                                <th style="padding: 0.5rem 0.7rem; text-align: center;">Cambio</th>
                                <th style="padding: 0.5rem 0.7rem; text-align: left;">Motivo</th>
                            </tr>
                        </thead>
                        <tbody id="historial-producto-body">
                            <tr><td colspan="4" style="text-align: center; color: var(--color-text-muted); padding: 2rem;">Cargando movimientos...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div style="display: flex; justify-content: flex-end; margin-top: 1.25rem;">
                    <button type="button" class="btn" onclick="Encargado.cerrarModalAjuste()">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Modal: Movimientos Generales de Inventario ─────────── -->
<div class="modal-overlay" id="modal-movimientos" style="display: none;">
    <div class="modal" style="max-width: 850px; width: 92%;">
        <div class="modal__header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--color-border); padding-bottom: 0.75rem; margin-bottom: 1rem;">
            <h3 style="font-size: 1.2rem;">📜 Historial de Movimientos de Inventario</h3>
            <button class="modal__close" onclick="Encargado.cerrarModalMovimientos()" style="background: none; border: none; color: var(--color-text-muted); font-size: 1.3rem; cursor: pointer;">✕</button>
        </div>
        <div class="modal__body">
            <div style="max-height: 420px; overflow-y: auto; border: 1px solid var(--color-border); border-radius: var(--radius-md);">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                    <thead style="background: var(--color-surface-2); position: sticky; top: 0;">
                        <tr>
                            <th style="padding: 0.6rem 0.8rem; text-align: left;">Fecha/Hora</th>
                            <th style="padding: 0.6rem 0.8rem; text-align: left;">Producto</th>
                            <th style="padding: 0.6rem 0.8rem; text-align: center;">Tipo</th>
                            <th style="padding: 0.6rem 0.8rem; text-align: center;">Cambio</th>
                            <th style="padding: 0.6rem 0.8rem; text-align: center;">Stock Resultante</th>
                            <th style="padding: 0.6rem 0.8rem; text-align: left;">Motivo / Usuario</th>
                        </tr>
                    </thead>
                    <tbody id="movimientos-body">
                        <!-- Generado por JS -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ── Modal: Carga Rápida de Stock Diario (Horneada Matutina) ── -->
<div class="modal-overlay" id="modal-stock-diario" style="display: none;">
    <div class="modal" style="max-width: 680px; width: 92%;">
        <div class="modal__header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--color-border); padding-bottom: 0.75rem; margin-bottom: 1rem;">
            <h3 style="font-size: 1.2rem; display: flex; align-items: center; gap: 0.5rem;">
                ⚡ Horneada Matutina — Apertura de Stock Diario
            </h3>
            <button class="modal__close" onclick="Encargado.cerrarModalStockDiario()" style="background: none; border: none; color: var(--color-text-muted); font-size: 1.3rem; cursor: pointer;">✕</button>
        </div>
        <div class="modal__body">
            <p style="font-size: 0.88rem; color: var(--color-text-muted); margin-bottom: 1rem;">
                Confirma las cantidades de salteñas horneadas hoy. Vienen precargadas con la plantilla estándar de tu sucursal para abrir en 1-clic.
            </p>
            <div style="max-height: 320px; overflow-y: auto; border: 1px solid var(--color-border); border-radius: var(--radius-md); margin-bottom: 1.25rem;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
                    <thead style="background: var(--color-surface-2); position: sticky; top: 0;">
                        <tr>
                            <th style="padding: 0.6rem 0.8rem; text-align: left;">Salteña (Rotación Diaria)</th>
                            <th style="padding: 0.6rem 0.8rem; text-align: center;">Stock Actual</th>
                            <th style="padding: 0.6rem 0.8rem; text-align: center; width: 140px;">Unidades a Ingresar</th>
                        </tr>
                    </thead>
                    <tbody id="stock-diario-items-body">
                        <!-- Generado por JS -->
                    </tbody>
                </table>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <button type="button" class="btn" onclick="Encargado.restablecerPredeterminadosDiarios()" style="font-size: 0.82rem; color: var(--color-text-muted);">
                    ↺ Restaurar plantilla base
                </button>
                <div style="display: flex; gap: 0.5rem;">
                    <button type="button" class="btn" onclick="Encargado.cerrarModalStockDiario()">Cancelar</button>
                    <button type="button" class="btn btn--primary" onclick="Encargado.confirmarStockDiario()" id="btn-confirmar-stock-diario" style="background: #e07a14; border-color: #d16b08; font-weight: 700;">
                        ⚡ Confirmar e Iniciar Jornada
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/encargado.js"></script>
</body>
</html>
