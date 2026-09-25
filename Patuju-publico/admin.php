<?php
require_once __DIR__ . '/config/auth.php';
verificarSesion('admin');

$db = getDB();
$sucursales = $db->query("SELECT id, nombre, ciudad, departamento FROM sucursales WHERE activo = 1 ORDER BY id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Patuju POS — Panel de Administración General y Auditoría Multi-Sucursal">
    <title>Patuju POS — Panel de Dirección General</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚙️</text></svg>">
</head>
<body>

<div class="app" style="grid-template-columns: 1fr; min-height: 100vh;">

    <!-- ── Topbar ────────────────────────────── -->
    <header class="topbar">
        <div class="topbar__brand">
            <span class="topbar__logo">👑</span>
            <div>
                <div class="topbar__title">DIRECCIÓN GENERAL</div>
                <div class="topbar__subtitle">Consolidado y Auditoría Multi-Sucursal</div>
            </div>
        </div>
        <div class="topbar__actions">
            <div style="display:flex;align-items:center;gap:0.4rem;padding:0.35rem 0.8rem;background:var(--color-surface-2);border-radius:var(--radius-full);font-size:0.85rem;color:var(--color-primary);">
                <span>🏢</span>
                <span>Todas las Sucursales (15 Sedes)</span>
            </div>
            <a href="index.php" class="btn btn--primary" id="btn-volver-caja">🥟 Ir a Caja</a>
            <a href="encargado.php" class="btn" id="btn-ir-encargado">🏬 Panel Sucursal</a>
            <a href="logout.php" class="btn" style="color:var(--color-danger)">🚪 Salir</a>
        </div>
    </header>

    <!-- ── Barra de Pestañas de Navegación ───── -->
    <div style="background: var(--color-surface); border-bottom: 1px solid var(--color-border); padding: 0.5rem 1.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <button class="btn btn-tab active" id="tab-btn-productos" onclick="Admin.cambiarTab('productos')" style="font-weight: 600;">
            📦 Catálogo de Productos
        </button>
        <button class="btn btn-tab" id="tab-btn-stock" onclick="Admin.cambiarTab('stock')" style="font-weight: 600;">
            🏬 Stock por Sucursal
        </button>
        <button class="btn btn-tab" id="tab-btn-auditoria-ventas" onclick="Admin.cambiarTab('auditoria-ventas')" style="font-weight: 600;">
            🧾 Auditoría de Ventas
        </button>
        <button class="btn btn-tab" id="tab-btn-auditoria-turnos" onclick="Admin.cambiarTab('auditoria-turnos')" style="font-weight: 600;">
            📑 Cierres de Turno (Cortes Z)
        </button>
        <button class="btn btn-tab" id="tab-btn-analytics" onclick="Admin.cambiarTab('analytics')" style="font-weight: 600;">
            📊 Dashboard Analítica
        </button>
    </div>

    <!-- ── Contenido Principal ───────────────── -->
    <main style="padding: 1.5rem; max-width: 1400px; margin: 0 auto; width: 100%;">

        <!-- ════════════════════════════════════════
             PESTAÑA 1: CATÁLOGO DE PRODUCTOS (CRUD)
             ════════════════════════════════════════ -->
        <section id="tab-content-productos" class="tab-content">
            <div class="admin-grid">
                <!-- Formulario -->
                <div class="admin-form">
                    <h3 class="admin-form__title" id="admin-form-title">➕ Nuevo Producto</h3>
                    <form id="admin-form">
                        <div class="form-group">
                            <label for="prod-nombre">Nombre *</label>
                            <input type="text" id="prod-nombre" placeholder="Ej: Salteña Grande de Carne" required>
                        </div>
                        <div class="form-group">
                            <label for="prod-precio">Precio Base General (Bs.) *</label>
                            <input type="number" id="prod-precio" step="0.50" min="0.50" placeholder="8.00" required>
                        </div>
                        <div class="form-group">
                            <label for="prod-categoria">Categoría *</label>
                            <select id="prod-categoria" required>
                                <option value="">Cargando...</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="prod-imagen">Imagen (archivo PNG/SVG)</label>
                            <input type="text" id="prod-imagen" placeholder="saltena_grande.png">
                        </div>
                        <div class="form-group">
                            <label for="prod-descripcion">Descripción</label>
                            <textarea id="prod-descripcion" rows="2" placeholder="Descripción breve..."></textarea>
                        </div>
                        <div style="display:flex;gap:0.5rem;">
                            <button type="submit" class="btn btn--primary" id="admin-form-submit" style="flex:1;">💾 Guardar Producto</button>
                            <button type="button" class="btn" id="admin-form-cancel" style="display:none;" onclick="Admin.limpiarFormulario()">Cancelar</button>
                        </div>
                    </form>
                </div>

                <!-- Tabla de productos -->
                <div class="admin-table-wrap">
                    <div class="admin-table-wrap__header" style="display: flex; justify-content: space-between; align-items: center;">
                        <span>📦 Productos Registrados en el Sistema</span>
                    </div>
                    <div class="admin-table-wrap__body">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nombre</th>
                                    <th>Categoría</th>
                                    <th>Precio Base</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="admin-tbody">
                                <tr><td colspan="6" style="text-align:center;color:var(--color-text-muted);padding:2rem;">Cargando productos...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- ════════════════════════════════════════
             PESTAÑA 2: STOCK MULTI-SUCURSAL (Editable por Sucursal)
             ════════════════════════════════════════ -->
        <section id="tab-content-stock" class="tab-content" style="display: none;">
            <!-- Selector rápido por Tarjetas de Sucursal con "Ver sucursal →" -->
            <div style="margin-bottom: 1.25rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                    <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--color-text); display: flex; align-items: center; gap: 0.5rem;">
                        <span>🏢</span> Sucursales Disponibles
                    </h3>
                    <span style="font-size: 0.85rem; color: var(--color-text-muted);">Haz clic en <strong>"Ver sucursal →"</strong> para editar stock y precios específicos de esa sede</span>
                </div>
                <div class="sucursales-quick-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1rem;">
                    <div class="sucursal-admin-card" onclick="Admin.seleccionarSucursalStock('')" style="background: var(--color-surface); border: 2px solid var(--color-primary); border-radius: var(--radius-md); padding: 1rem; cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: all 0.2s ease;">
                        <div>
                            <div style="font-weight: 700; color: var(--color-primary);">🌐 Todas las Sedes</div>
                            <div style="font-size: 0.8rem; color: var(--color-text-muted);">Consolidado General</div>
                        </div>
                        <span class="btn btn--sm" style="font-size: 0.78rem;">Ver todas →</span>
                    </div>
                    <?php foreach ($sucursales as $s): ?>
                    <div class="sucursal-admin-card" id="card-suc-<?= $s['id'] ?>" onclick="Admin.seleccionarSucursalStock(<?= $s['id'] ?>)" style="background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 1rem; cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: all 0.2s ease;">
                        <div>
                            <div style="font-weight: 700; color: var(--color-text);"><?= htmlspecialchars($s['nombre']) ?></div>
                            <div style="font-size: 0.8rem; color: var(--color-text-muted);">📍 <?= htmlspecialchars($s['ciudad']) ?></div>
                        </div>
                        <button class="btn btn--sm btn--primary" onclick="event.stopPropagation(); Admin.seleccionarSucursalStock(<?= $s['id'] ?>)" style="font-size: 0.78rem; font-weight: 600;">
                            Ver sucursal →
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Toolbar de Filtros y Acciones -->
            <div style="background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 1.25rem; margin-bottom: 1.5rem; display: flex; flex-wrap: wrap; gap: 1rem; justify-content: space-between; align-items: center;">
                <div style="display: flex; gap: 0.8rem; align-items: center; flex-wrap: wrap;">
                    <label for="filtro-stock-sucursal" style="font-weight: 600; font-size: 0.9rem;">📍 Sucursal Activa:</label>
                    <select id="filtro-stock-sucursal" style="background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 0.5rem 0.8rem; color: var(--color-text); font-weight: 600;" onchange="Admin.cargarStockSucursal()">
                        <option value="">Todas las Sucursales (Consolidado)</option>
                        <?php foreach ($sucursales as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?> (<?= htmlspecialchars($s['ciudad']) ?>)</option>
                        <?php endforeach; ?>
                    </select>

                    <input type="text" id="filtro-stock-busqueda" placeholder="🔍 Buscar producto..." oninput="Admin.filtrarTablaStockLocal()" style="background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 0.5rem 0.8rem; color: var(--color-text); font-size: 0.9rem; min-width: 200px;">
                </div>
                <div style="display: flex; gap: 0.6rem; align-items: center;">
                    <button class="btn" onclick="Admin.cargarStockSucursal()">🔄 Actualizar</button>
                    <button class="btn btn--primary" id="btn-guardar-todo-stock" onclick="Admin.guardarTodoStock()" style="font-weight: 700;">💾 Guardar Todo</button>
                </div>
            </div>

            <!-- Tabla Editable de Inventario y Precios por Sucursal -->
            <div class="admin-table-wrap">
                <div class="admin-table-wrap__header" style="display: flex; justify-content: space-between; align-items: center;">
                    <span id="stock-table-titulo">🏬 Edición de Inventario y Precios por Sucursal</span>
                    <span style="font-size: 0.82rem; font-weight: 400; color: var(--color-text-muted);">
                        💡 Los cambios se guardan directamente en el inventario local de la sucursal seleccionada
                    </span>
                </div>
                <div class="admin-table-wrap__body">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Sucursal</th>
                                <th>Producto</th>
                                <th>Categoría</th>
                                <th style="text-align: center; width: 120px;">Stock Disp.</th>
                                <th style="text-align: center; width: 100px;">Alerta Mín.</th>
                                <th style="text-align: center; width: 170px;">Precio Local (Bs.)</th>
                                <th style="text-align: center;">Estado</th>
                                <th style="text-align: center; width: 110px;">Acción</th>
                            </tr>
                        </thead>
                        <tbody id="stock-admin-tbody">
                            <tr><td colspan="8" style="text-align:center;color:var(--color-text-muted);padding:2rem;">Cargando inventario...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ════════════════════════════════════════
             PESTAÑA 3: AUDITORÍA DE VENTAS (Trazabilidad)
             ════════════════════════════════════════ -->
        <section id="tab-content-auditoria-ventas" class="tab-content" style="display: none;">
            <!-- Filtros de Auditoría -->
            <div style="background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 1.25rem; margin-bottom: 1.5rem; display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end;">
                <div>
                    <label for="filtro-venta-sucursal" style="display: block; font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: 0.3rem;">Sucursal:</label>
                    <select id="filtro-venta-sucursal" style="background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 0.5rem 0.8rem; color: var(--color-text);">
                        <option value="">Todas las Sucursales</option>
                        <?php foreach ($sucursales as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="filtro-venta-desde" style="display: block; font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: 0.3rem;">Fecha Desde:</label>
                    <input type="date" id="filtro-venta-desde" style="background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 0.5rem 0.8rem; color: var(--color-text);">
                </div>
                <div>
                    <label for="filtro-venta-hasta" style="display: block; font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: 0.3rem;">Fecha Hasta:</label>
                    <input type="date" id="filtro-venta-hasta" style="background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 0.5rem 0.8rem; color: var(--color-text);">
                </div>
                <div>
                    <button class="btn btn--primary" onclick="Admin.cargarAuditoriaVentas()">🔍 Filtrar Auditoría</button>
                </div>
            </div>

            <!-- Tabla de Auditoría de Ventas -->
            <div class="admin-table-wrap">
                <div class="admin-table-wrap__header">🧾 Trazabilidad de Ventas Registradas (Quién vendió qué, cuándo y dónde)</div>
                <div class="admin-table-wrap__body">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th># Venta</th>
                                <th>Fecha / Hora</th>
                                <th>Sucursal</th>
                                <th>Cajero</th>
                                <th>Detalle de Productos</th>
                                <th>Método de Pago</th>
                                <th style="text-align: right;">Total (Bs.)</th>
                            </tr>
                        </thead>
                        <tbody id="auditoria-ventas-tbody">
                            <tr><td colspan="7" style="text-align:center;color:var(--color-text-muted);padding:2rem;">Cargando historial de ventas...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ════════════════════════════════════════
             PESTAÑA 4: AUDITORÍA DE CIERRES DE TURNO (Cortes Z)
             ════════════════════════════════════════ -->
        <section id="tab-content-auditoria-turnos" class="tab-content" style="display: none;">
            <!-- Filtros de Auditoría de Turnos -->
            <div style="background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 1.25rem; margin-bottom: 1.5rem; display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end;">
                <div>
                    <label for="filtro-turno-sucursal" style="display: block; font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: 0.3rem;">Sucursal:</label>
                    <select id="filtro-turno-sucursal" style="background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 0.5rem 0.8rem; color: var(--color-text);">
                        <option value="">Todas las Sucursales</option>
                        <?php foreach ($sucursales as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="filtro-turno-estado" style="display: block; font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: 0.3rem;">Estado:</label>
                    <select id="filtro-turno-estado" style="background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 0.5rem 0.8rem; color: var(--color-text);">
                        <option value="">Todos los turnos</option>
                        <option value="cerrado">Solo Cerrados (Corte Z)</option>
                        <option value="abierto">Solo Abiertos Actualmente</option>
                    </select>
                </div>
                <div>
                    <button class="btn btn--primary" onclick="Admin.cargarAuditoriaTurnos()">🔍 Filtrar Cierres</button>
                </div>
            </div>

            <!-- Tabla de Cierres de Turno -->
            <div class="admin-table-wrap">
                <div class="admin-table-wrap__header">📑 Arqueos y Cierres de Caja (Cortes Z a nivel nacional)</div>
                <div class="admin-table-wrap__body">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th># Turno</th>
                                <th>Sucursal / Cajero</th>
                                <th>Horarios (Apertura - Cierre)</th>
                                <th style="text-align: right;">Fondo Inicial</th>
                                <th style="text-align: right;">Ventas Sistema</th>
                                <th style="text-align: right;">Egresos</th>
                                <th style="text-align: right;">Monto Físico (Cierre)</th>
                                <th style="text-align: center;">Diferencia / Cuadre</th>
                                <th>Notas</th>
                            </tr>
                        </thead>
                        <tbody id="auditoria-turnos-tbody">
                            <tr><td colspan="9" style="text-align:center;color:var(--color-text-muted);padding:2rem;">Cargando turnos de caja...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ════════════════════════════════════════
             PESTAÑA 5: DASHBOARD DE ANALÍTICA (Fase 3)
             ════════════════════════════════════════ -->
        <section id="tab-content-analytics" class="tab-content" style="display: none;">
            <!-- Barra Superior de Control y Estado -->
            <div class="analytics-topbar">
                <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                    <span id="analytics-status-badge" class="badge-status status-ok">
                        🟢 Actualizado
                    </span>
                    <span style="font-size: 0.8rem; color: var(--color-text-muted);">
                        ⏱️ Actualización automática cada 30s
                    </span>
                </div>
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <button class="btn" onclick="AdminAnalytics.actualizar()" title="Forzar actualización de datos">
                        🔄 Refrescar
                    </button>
                    <button class="btn btn--primary" onclick="AdminAnalytics.abrirModalExportar()" title="Exportar reportes a Excel o CSV">
                        📥 Exportar Reportes
                    </button>
                    <button class="btn" onclick="AdminAnalytics.imprimirReporte()" title="Imprimir informe en formato PDF">
                        🖨️ Imprimir PDF
                    </button>
                </div>
            </div>

            <!-- Fila de Tarjetas KPI -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-card__icon" style="color: var(--color-primary);">💰</div>
                    <div class="kpi-card__info">
                        <div class="kpi-card__title">Ventas de Hoy</div>
                        <div class="kpi-card__value" id="kpi-ventas-hoy">Bs. 0.00</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-card__icon" style="color: var(--color-info);">🧾</div>
                    <div class="kpi-card__info">
                        <div class="kpi-card__title">Transacciones Hoy</div>
                        <div class="kpi-card__value" id="kpi-transacciones-hoy">0</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-card__icon" style="color: var(--color-success);">🎯</div>
                    <div class="kpi-card__info">
                        <div class="kpi-card__title">Ticket Promedio</div>
                        <div class="kpi-card__value" id="kpi-ticket-promedio">Bs. 0.00</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-card__icon" style="color: var(--color-warning);">🗓️</div>
                    <div class="kpi-card__info">
                        <div class="kpi-card__title">Ventas del Mes</div>
                        <div class="kpi-card__value" id="kpi-ventas-mes">Bs. 0.00</div>
                    </div>
                </div>
            </div>

            <!-- Gráficos de Analítica (Chart.js) -->
            <div class="analytics-charts-grid">
                <!-- Gráfico 1: Unidades vendidas hoy por sucursal -->
                <div class="chart-card col-8">
                    <div class="chart-card__header">
                        <div class="chart-card__title">
                            <span>🥟</span> Unidades Vendidas Hoy por Sucursal
                        </div>
                        <span style="font-size: 0.75rem; color: var(--color-text-muted);">Comportamiento incremental</span>
                    </div>
                    <div class="chart-card__body">
                        <canvas id="chart-sucursales"></canvas>
                    </div>
                </div>

                <!-- Gráfico 2: Horarios Pico de Venta -->
                <div class="chart-card col-4">
                    <div class="chart-card__header">
                        <div class="chart-card__title">
                            <span>⏰</span> Horarios Pico (Hoy)
                        </div>
                        <span style="font-size: 0.75rem; color: var(--color-text-muted);">Evolución horaria en Bs.</span>
                    </div>
                    <div class="chart-card__body">
                        <canvas id="chart-horas"></canvas>
                    </div>
                </div>

                <!-- Gráfico 3: Ventas por Mes (Últimos 12 meses) -->
                <div class="chart-card col-7">
                    <div class="chart-card__header">
                        <div class="chart-card__title">
                            <span>📅</span> Ventas por Mes (Últimos 12 Meses)
                        </div>
                        <span style="font-size: 0.75rem; color: var(--color-text-muted);">Total facturado en Bs. por mes</span>
                    </div>
                    <div class="chart-card__body" style="min-height: 260px;">
                        <canvas id="chart-mes"></canvas>
                    </div>
                </div>

                <!-- Gráfico 4: Top 10 Productos Más Vendidos -->
                <div class="chart-card col-5">
                    <div class="chart-card__header">
                        <div class="chart-card__title">
                            <span>🥇</span> Top 10 Productos del Mes
                        </div>
                        <span style="font-size: 0.75rem; color: var(--color-text-muted);">Por unidades vendidas</span>
                    </div>
                    <div class="chart-card__body">
                        <canvas id="chart-productos"></canvas>
                    </div>
                </div>

                <!-- Gráfico 5: Tendencia de Ventas (Últimos 30 Días) -->
                <div class="chart-card col-12">
                    <div class="chart-card__header">
                        <div class="chart-card__title">
                            <span>📈</span> Tendencia de Ventas Diarias (Últimos 30 Días)
                        </div>
                        <span style="font-size: 0.75rem; color: var(--color-text-muted);">Total facturado en Bs.</span>
                    </div>
                    <div class="chart-card__body" style="min-height: 320px;">
                        <canvas id="chart-tendencia"></canvas>
                    </div>
                </div>
            </div>
        </section>

    </main>
</div>

<!-- ── Modal de Exportación de Reportes ──────── -->
<div class="modal-overlay" id="modal-exportar-reporte">
    <div class="modal" style="max-width: 480px;">
        <div class="modal__header">
            <h3 class="modal__title">📥 Exportar Reportes de Negocio</h3>
            <button class="modal__close" onclick="AdminAnalytics.cerrarModalExportar()">✕</button>
        </div>
        <div class="modal__body" style="padding: 1.5rem;">
            <p style="color: var(--color-text-dim); font-size: 0.85rem; margin-bottom: 1.25rem;">
                Genera archivos CSV codificados en UTF-8 con formato monetario boliviano (Bs.), listos para abrirse en Microsoft Excel o importar en software contable.
            </p>

            <div class="form-group">
                <label for="rep-tipo">Tipo de Reporte *</label>
                <select id="rep-tipo" style="width: 100%; padding: 0.75rem; background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); color: var(--color-text);">
                    <option value="ventas_sucursal">Consolidado de Ventas por Sucursal</option>
                    <option value="productos">Ranking y Rendimiento de Productos</option>
                    <option value="diario">Histórico de Ventas Día a Día</option>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.25rem;">
                <div class="form-group">
                    <label for="rep-desde">Fecha Desde</label>
                    <input type="date" id="rep-desde" value="<?= date('Y-m-01') ?>" style="width: 100%; padding: 0.65rem; background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); color: var(--color-text);">
                </div>
                <div class="form-group">
                    <label for="rep-hasta">Fecha Hasta</label>
                    <input type="date" id="rep-hasta" value="<?= date('Y-m-d') ?>" style="width: 100%; padding: 0.65rem; background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); color: var(--color-text);">
                </div>
            </div>

            <button onclick="AdminAnalytics.descargarReporte()" class="btn btn--primary" style="width: 100%; padding: 0.9rem; font-size: 1rem; justify-content: center;">
                ⬇️ Descargar Reporte (.CSV / Excel)
            </button>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="assets/js/analytics.js"></script>
<script src="assets/js/admin.js"></script>
</body>
</html>
