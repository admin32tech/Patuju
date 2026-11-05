<?php
require_once __DIR__ . '/config/auth.php';
verificarSesion('admin');

$db = getDB();
$sucursales = $db->query("SELECT id, nombre, direccion, es_interna FROM sucursales WHERE activo = 1 ORDER BY id ASC")->fetchAll();

/** Icono del sprite SVG local */
function ic(string $name): string {
    return '<svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#' . $name . '"></use></svg>';
}

/** <option> de sucursales reutilizado en los filtros */
function opcionesSucursales(array $sucursales): string {
    $html = '';
    foreach ($sucursales as $s) {
        $html .= '<option value="' . (int)$s['id'] . '">' . htmlspecialchars($s['nombre']) . '</option>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Patuju POS — Panel de Administración General y Auditoría Multi-Sucursal">
    <title>Patuju POS — Panel de Dirección General</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
</head>
<body>

<div class="admin-shell">

    <!-- ── Barra lateral ─────────────────────── -->
    <aside class="admin-sidebar">
        <div class="admin-brand">
            <div class="admin-brand__name">Patuju · Dirección</div>
            <div class="admin-brand__sub">Consolidado y auditoría de las sucursales</div>
        </div>

        <nav class="side-nav" aria-label="Secciones del panel">
            <button type="button" class="btn-tab active" id="tab-btn-productos" onclick="Admin.cambiarTab('productos')"><?= ic('package') ?> Catálogo de productos</button>
            <button type="button" class="btn-tab" id="tab-btn-stock" onclick="Admin.cambiarTab('stock')"><?= ic('store') ?> Stock por sucursal</button>
            <button type="button" class="btn-tab" id="tab-btn-auditoria-ventas" onclick="Admin.cambiarTab('auditoria-ventas')"><?= ic('list') ?> Auditoría de ventas</button>
            <button type="button" class="btn-tab" id="tab-btn-auditoria-turnos" onclick="Admin.cambiarTab('auditoria-turnos')"><?= ic('clock') ?> Cierres de turno</button>
            <button type="button" class="btn-tab" id="tab-btn-analytics" onclick="Admin.cambiarTab('analytics')"><?= ic('trend') ?> Analítica</button>
            <button type="button" class="btn-tab" id="tab-btn-usuarios" onclick="Admin.cambiarTab('usuarios')"><?= ic('user') ?> Gestión de usuarios</button>
        </nav>

        <div class="admin-sidebar__foot">
            <a href="index.php" class="btn" id="btn-volver-caja"><?= ic('cart') ?> Ir a caja</a>
            <a href="encargado.php" class="btn" id="btn-ir-encargado"><?= ic('store') ?> Panel de sucursal</a>
            <a href="logout.php" class="btn btn--exit"><?= ic('logout') ?> Cerrar sesión</a>
        </div>
    </aside>

    <!-- ── Contenido ─────────────────────────── -->
    <main class="admin-main" id="contenido">
        <div class="admin-content">

            <header class="admin-page-head">
                <h1 id="admin-titulo">Catálogo de productos</h1>
                <p id="admin-descripcion">Crea, edita y desactiva los productos que se venden en todas las sucursales.</p>
            </header>

            <!-- PESTAÑA 1: CATÁLOGO -->
            <section id="tab-content-productos" class="tab-content">
                <div class="admin-grid">
                    <div class="admin-form">
                        <h3 class="admin-form__title" id="admin-form-title">Nuevo Producto</h3>
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
                            <div class="form-actions">
                                <button type="submit" class="btn btn--primary" id="admin-form-submit"><?= ic('save') ?> Guardar</button>
                                <button type="button" class="btn" id="admin-form-cancel" style="display:none;" onclick="Admin.limpiarFormulario()">Cancelar</button>
                            </div>
                        </form>
                    </div>

                    <div class="admin-table-wrap">
                        <div class="admin-table-wrap__header">Productos registrados</div>
                        <div class="admin-table-wrap__body">
                            <table class="admin-table">
                                <thead>
                                    <tr><th>ID</th><th>Nombre</th><th>Categoría</th><th>Precio base</th><th>Estado</th><th>Acciones</th></tr>
                                </thead>
                                <tbody id="admin-tbody">
                                    <tr><td colspan="6" class="admin-empty-row">Cargando productos...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- PESTAÑA 2: STOCK POR SUCURSAL -->
            <section id="tab-content-stock" class="tab-content" style="display:none;">
                <div class="stock-intro">
                    <h3>Sucursales disponibles</h3>
                    <span>Elige una sucursal para editar su stock y sus precios locales</span>
                </div>
                <div class="sucursales-quick-grid">
                    <div class="sucursal-admin-card" onclick="Admin.seleccionarSucursalStock('')">
                        <div>
                            <div class="sucursal-admin-card__name">Todas las sedes</div>
                            <div class="sucursal-admin-card__meta">Consolidado general</div>
                        </div>
                        <span class="btn btn--sm">Ver todas</span>
                    </div>
                    <?php foreach ($sucursales as $s): ?>
                    <div class="sucursal-admin-card" id="card-suc-<?= (int)$s['id'] ?>" onclick="Admin.seleccionarSucursalStock(<?= (int)$s['id'] ?>)">
                        <div>
                            <div class="sucursal-admin-card__name"><?= htmlspecialchars($s['nombre']) ?></div>
                            <div class="sucursal-admin-card__meta"><?= ic('pin') ?> <?= htmlspecialchars($s['direccion']) ?></div>
                        </div>
                        <button type="button" class="btn btn--sm btn--primary" onclick="event.stopPropagation(); Admin.seleccionarSucursalStock(<?= (int)$s['id'] ?>)">
                            Ver sucursal <?= ic('arrow-right') ?>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="filter-bar filter-bar--spaced">
                    <div>
                        <label for="filtro-stock-sucursal" class="field-label">Sucursal activa</label>
                        <select id="filtro-stock-sucursal" class="field" onchange="Admin.cargarStockSucursal()">
                            <option value="">Todas las sucursales (consolidado)</option>
                            <?= opcionesSucursales($sucursales) ?>
                        </select>
                    </div>
                    <div>
                        <label for="filtro-stock-busqueda" class="field-label">Buscar producto</label>
                        <input type="text" id="filtro-stock-busqueda" class="field" placeholder="Nombre del producto" oninput="Admin.filtrarTablaStockLocal()">
                    </div>
                    <div class="filter-bar__spacer"></div>
                    <button type="button" class="btn" onclick="Admin.cargarStockSucursal()"><?= ic('refresh') ?> Actualizar</button>
                    <button type="button" class="btn btn--primary" id="btn-guardar-todo-stock" onclick="Admin.guardarTodoStock()"><?= ic('save') ?> Guardar todo</button>
                </div>

                <div class="admin-table-wrap">
                    <div class="admin-table-wrap__header admin-table-wrap__header--split">
                        <span id="stock-table-titulo">Inventario y precios por sucursal</span>
                        <span class="admin-table-wrap__hint">Los cambios se guardan en el inventario de la sucursal elegida</span>
                    </div>
                    <div class="admin-table-wrap__body">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Sucursal</th>
                                    <th>Producto</th>
                                    <th>Categoría</th>
                                    <th class="col-center col-w120">Stock disp.</th>
                                    <th class="col-center col-w100">Alerta mín.</th>
                                    <th class="col-center col-w170">Precio local (Bs.)</th>
                                    <th class="col-center">Estado</th>
                                    <th class="col-center col-w110">Acción</th>
                                </tr>
                            </thead>
                            <tbody id="stock-admin-tbody">
                                <tr><td colspan="8" class="admin-empty-row">Cargando inventario...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- PESTAÑA 3: AUDITORÍA DE VENTAS -->
            <section id="tab-content-auditoria-ventas" class="tab-content" style="display:none;">
                <div class="filter-bar">
                    <div>
                        <label for="filtro-venta-sucursal" class="field-label">Sucursal</label>
                        <select id="filtro-venta-sucursal" class="field">
                            <option value="">Todas las sucursales</option>
                            <?= opcionesSucursales($sucursales) ?>
                        </select>
                    </div>
                    <div>
                        <label for="filtro-venta-desde" class="field-label">Desde</label>
                        <input type="date" id="filtro-venta-desde" class="field">
                    </div>
                    <div>
                        <label for="filtro-venta-hasta" class="field-label">Hasta</label>
                        <input type="date" id="filtro-venta-hasta" class="field">
                    </div>
                    <button type="button" class="btn btn--primary" onclick="Admin.cargarAuditoriaVentas()"><?= ic('search') ?> Filtrar ventas</button>
                </div>

                <div class="admin-table-wrap">
                    <div class="admin-table-wrap__header">Ventas registradas: quién vendió qué, cuándo y dónde</div>
                    <div class="admin-table-wrap__body">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th># Venta</th><th>Fecha / hora</th><th>Sucursal</th><th>Cajero</th><th>Detalle</th><th>Método de pago</th>
                                    <th class="col-right">Total (Bs.)</th>
                                </tr>
                            </thead>
                            <tbody id="auditoria-ventas-tbody">
                                <tr><td colspan="7" class="admin-empty-row">Cargando historial de ventas...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- PESTAÑA 4: CIERRES DE TURNO -->
            <section id="tab-content-auditoria-turnos" class="tab-content" style="display:none;">
                <div class="filter-bar">
                    <div>
                        <label for="filtro-turno-sucursal" class="field-label">Sucursal</label>
                        <select id="filtro-turno-sucursal" class="field">
                            <option value="">Todas las sucursales</option>
                            <?= opcionesSucursales($sucursales) ?>
                        </select>
                    </div>
                    <div>
                        <label for="filtro-turno-estado" class="field-label">Estado</label>
                        <select id="filtro-turno-estado" class="field">
                            <option value="">Todos los turnos</option>
                            <option value="cerrado">Solo cerrados (corte Z)</option>
                            <option value="abierto">Solo abiertos</option>
                        </select>
                    </div>
                    <button type="button" class="btn btn--primary" onclick="Admin.cargarAuditoriaTurnos()"><?= ic('search') ?> Filtrar cierres</button>
                </div>

                <div class="admin-table-wrap">
                    <div class="admin-table-wrap__header">Arqueos y cierres de caja (cortes Z)</div>
                    <div class="admin-table-wrap__body">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th># Turno</th><th>Sucursal / cajero</th><th>Apertura - cierre</th>
                                    <th class="col-right">Fondo inicial</th>
                                    <th class="col-right">Ventas sistema</th>
                                    <th class="col-right">Egresos</th>
                                    <th class="col-right">Monto físico</th>
                                    <th class="col-center">Diferencia</th>
                                    <th>Notas</th>
                                </tr>
                            </thead>
                            <tbody id="auditoria-turnos-tbody">
                                <tr><td colspan="9" class="admin-empty-row">Cargando turnos de caja...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- PESTAÑA 5: ANALÍTICA -->
            <section id="tab-content-analytics" class="tab-content" style="display:none;">
                <div class="analytics-topbar">
                    <div class="analytics-topbar__status">
                        <span id="analytics-status-badge" class="badge-status status-ok">Actualizado</span>
                        <span class="analytics-topbar__note">Se actualiza solo cada 30 s</span>
                    </div>
                    <div class="analytics-topbar__actions">
                        <button type="button" class="btn" onclick="AdminAnalytics.actualizar()" title="Forzar actualización de datos"><?= ic('refresh') ?> Refrescar</button>
                        <button type="button" class="btn btn--primary" onclick="AdminAnalytics.abrirModalExportar()" title="Exportar reportes a Excel o CSV"><?= ic('download') ?> Exportar reportes</button>
                        <button type="button" class="btn" onclick="AdminAnalytics.imprimirReporte()" title="Imprimir informe en PDF"><?= ic('printer') ?> Imprimir PDF</button>
                    </div>
                </div>

                <div class="kpi-grid">
                    <div class="kpi-card"><div class="kpi-card__info"><div class="kpi-card__title">Ventas de hoy</div><div class="kpi-card__value" id="kpi-ventas-hoy">Bs. 0.00</div></div></div>
                    <div class="kpi-card"><div class="kpi-card__info"><div class="kpi-card__title">Transacciones hoy</div><div class="kpi-card__value" id="kpi-transacciones-hoy">0</div></div></div>
                    <div class="kpi-card"><div class="kpi-card__info"><div class="kpi-card__title">Ticket promedio</div><div class="kpi-card__value" id="kpi-ticket-promedio">Bs. 0.00</div></div></div>
                    <div class="kpi-card"><div class="kpi-card__info"><div class="kpi-card__title">Ventas del mes</div><div class="kpi-card__value" id="kpi-ventas-mes">Bs. 0.00</div></div></div>
                </div>

                <div class="analytics-charts-grid">
                    <div class="chart-card col-8">
                        <div class="chart-card__header"><div class="chart-card__title">Unidades vendidas hoy por sucursal</div><span class="chart-card__note">Comportamiento incremental</span></div>
                        <div class="chart-card__body"><canvas id="chart-sucursales"></canvas></div>
                    </div>
                    <div class="chart-card col-4">
                        <div class="chart-card__header"><div class="chart-card__title">Horarios pico (hoy)</div><span class="chart-card__note">Evolución horaria en Bs.</span></div>
                        <div class="chart-card__body"><canvas id="chart-horas"></canvas></div>
                    </div>
                    <div class="chart-card col-7">
                        <div class="chart-card__header"><div class="chart-card__title">Ventas por mes (últimos 12 meses)</div><span class="chart-card__note">Total facturado en Bs.</span></div>
                        <div class="chart-card__body chart-card__body--md"><canvas id="chart-mes"></canvas></div>
                    </div>
                    <div class="chart-card col-5">
                        <div class="chart-card__header"><div class="chart-card__title">Top 10 productos del mes</div><span class="chart-card__note">Por unidades vendidas</span></div>
                        <div class="chart-card__body"><canvas id="chart-productos"></canvas></div>
                    </div>
                    <div class="chart-card col-12">
                        <div class="chart-card__header"><div class="chart-card__title">Tendencia de ventas diarias (últimos 30 días)</div><span class="chart-card__note">Total facturado en Bs.</span></div>
                        <div class="chart-card__body chart-card__body--lg"><canvas id="chart-tendencia"></canvas></div>
                    </div>
                </div>
            </section>

            <!-- PESTAÑA 6: GESTIÓN DE USUARIOS Y ASIGNACIÓN DE SUCURSALES -->
            <section id="tab-content-usuarios" class="tab-content" style="display:none;">
                <div class="kpi-grid" style="margin-bottom: 1.5rem;">
                    <div class="kpi-card">
                        <div class="kpi-card__info">
                            <div class="kpi-card__title">Total usuarios</div>
                            <div class="kpi-card__value" id="kpi-usr-total">0</div>
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-card__info">
                            <div class="kpi-card__title">Usuarios activos</div>
                            <div class="kpi-card__value" id="kpi-usr-activos">0</div>
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-card__info">
                            <div class="kpi-card__title">Encargados (Supervisores)</div>
                            <div class="kpi-card__value" id="kpi-usr-encargados" style="color:var(--color-primary-ink);">0</div>
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-card__info">
                            <div class="kpi-card__title">Cajeros (POS)</div>
                            <div class="kpi-card__value" id="kpi-usr-cajeros">0</div>
                        </div>
                    </div>
                </div>

                <div class="filter-bar filter-bar--spaced">
                    <div>
                        <label for="filtro-usr-rol" class="field-label">Filtrar por rol</label>
                        <select id="filtro-usr-rol" class="field" onchange="AdminUsuarios.filtrar()">
                            <option value="">Todos los roles</option>
                            <option value="admin">Administrador</option>
                            <option value="encargado">Encargado (Supervisor)</option>
                            <option value="caja">Cajero (Caja)</option>
                        </select>
                    </div>
                    <div>
                        <label for="filtro-usr-busqueda" class="field-label">Buscar usuario</label>
                        <input type="text" id="filtro-usr-busqueda" class="field" placeholder="Nombre o usuario..." oninput="AdminUsuarios.filtrar()">
                    </div>
                    <div class="filter-bar__spacer"></div>
                    <button type="button" class="btn" onclick="AdminUsuarios.cargar()"><?= ic('refresh') ?> Refrescar</button>
                    <button type="button" class="btn btn--primary" onclick="AdminUsuarios.abrirFormularioNuevo()"><?= ic('plus') ?> Nuevo usuario</button>
                </div>

                <div class="admin-grid">
                    <!-- Formulario de Usuario -->
                    <div class="admin-form" id="usr-form-container">
                        <h3 class="admin-form__title" id="usr-form-title">Nuevo Usuario</h3>
                        <form id="usr-form" onsubmit="AdminUsuarios.guardar(event)">
                            <input type="hidden" id="usr-id" value="">
                            
                            <div class="form-group">
                                <label for="usr-username">Usuario (Login) *</label>
                                <input type="text" id="usr-username" placeholder="ej: encargado_norte" required autocomplete="off">
                                <small style="display:block;margin-top:0.25rem;font-size:0.75rem;color:var(--color-text-dim);">Solo letras, números, guiones y puntos.</small>
                            </div>

                            <div class="form-group" id="wrap-usr-password">
                                <label for="usr-password">Contraseña *</label>
                                <input type="password" id="usr-password" placeholder="Mínimo 6 caracteres" autocomplete="new-password">
                            </div>

                            <div class="form-group">
                                <label for="usr-nombre">Nombre y Apellido *</label>
                                <input type="text" id="usr-nombre" placeholder="ej: Juan Pérez" required>
                            </div>

                            <div class="form-group">
                                <label for="usr-rol">Rol del usuario *</label>
                                <select id="usr-rol" class="field" required onchange="AdminUsuarios.alCambiarRol()">
                                    <option value="caja">Cajero (Solo Caja POS de su sucursal)</option>
                                    <option value="encargado">Encargado (Gestión de Stock y múltiples sucursales)</option>
                                    <option value="admin">Administrador General (Acceso total)</option>
                                </select>
                            </div>

                            <div class="form-group" id="wrap-usr-sucursal">
                                <label for="usr-sucursal-principal">Sucursal principal *</label>
                                <select id="usr-sucursal-principal" class="field" required>
                                    <option value="">Selecciona una sucursal...</option>
                                    <?= opcionesSucursales(array_filter($sucursales, fn($s) => empty($s['es_interna']))) ?>
                                </select>
                                <small style="display:block;margin-top:0.25rem;font-size:0.75rem;color:var(--color-text-dim);">Sede física asignada por defecto.</small>
                            </div>

                            <div class="form-group" id="wrap-usr-activo">
                                <label for="usr-activo">Estado</label>
                                <select id="usr-activo" class="field">
                                    <option value="1">Activo (Puede iniciar sesión)</option>
                                    <option value="0">Inactivo (Acceso bloqueado)</option>
                                </select>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn--primary" id="usr-form-submit"><?= ic('save') ?> Guardar usuario</button>
                                <button type="button" class="btn" id="usr-form-cancel" style="display:none;" onclick="AdminUsuarios.limpiarFormulario()">Cancelar</button>
                            </div>
                        </form>
                    </div>

                    <!-- Tabla de Usuarios -->
                    <div class="admin-table-wrap">
                        <div class="admin-table-wrap__header admin-table-wrap__header--split">
                            <span>Usuarios del sistema</span>
                            <span id="usr-contador" style="font-size:0.85rem;color:var(--color-text-muted);">Cargando...</span>
                        </div>
                        <div class="admin-table-wrap__body" style="overflow-x:auto;">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Usuario</th>
                                        <th>Rol</th>
                                        <th>Sucursal principal</th>
                                        <th>Sucursales a cargo</th>
                                        <th class="col-center">Estado</th>
                                        <th>Último login</th>
                                        <th class="col-center col-w120">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="usr-admin-tbody">
                                    <tr><td colspan="7" class="admin-empty-row">Cargando usuarios...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

        </div>
    </main>
</div>

<!-- Modal de exportación -->
<div class="modal-overlay" id="modal-exportar-reporte">
    <div class="modal modal--sm" role="dialog" aria-modal="true" aria-labelledby="modal-exportar-titulo">
        <div class="modal__header">
            <h3 class="modal__title" id="modal-exportar-titulo">Exportar reportes</h3>
            <button type="button" class="modal__close" aria-label="Cerrar" onclick="AdminAnalytics.cerrarModalExportar()"><?= ic('x') ?></button>
        </div>
        <div class="modal__body modal__body--pad">
            <p class="modal__lead">Genera un CSV en UTF-8 con formato Bs., listo para abrir en Excel o importar en tu software contable.</p>
            <div class="form-group">
                <label for="rep-tipo">Tipo de reporte *</label>
                <select id="rep-tipo" class="field">
                    <option value="ventas_sucursal">Ventas consolidadas por sucursal</option>
                    <option value="productos">Ranking y rendimiento de productos</option>
                    <option value="diario">Ventas día a día</option>
                </select>
            </div>
            <div class="form-row-2">
                <div class="form-group">
                    <label for="rep-desde">Desde</label>
                    <input type="date" id="rep-desde" class="field" value="<?= date('Y-m-01') ?>">
                </div>
                <div class="form-group">
                    <label for="rep-hasta">Hasta</label>
                    <input type="date" id="rep-hasta" class="field" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <button type="button" onclick="AdminAnalytics.descargarReporte()" class="btn btn--primary btn--block"><?= ic('download') ?> Descargar reporte (CSV)</button>
        </div>
    </div>
</div>

<!-- Modal: Asignar múltiples sucursales a un Encargado -->
<div class="modal-overlay" id="modal-asignar-sucursales">
    <div class="modal" style="max-width: 680px;" role="dialog" aria-modal="true" aria-labelledby="modal-asig-titulo">
        <div class="modal__header">
            <div>
                <h3 class="modal__title" id="modal-asig-titulo">Asignar Sucursales a Encargado</h3>
                <div id="modal-asig-subtitulo" style="font-size: 0.85rem; color: var(--color-text-muted); margin-top: 0.2rem;">
                    Selecciona las sucursales que este encargado podrá supervisar y modificar stock.
                </div>
            </div>
            <button type="button" class="modal__close" aria-label="Cerrar" onclick="AdminUsuarios.cerrarModalSucursales()"><?= ic('x') ?></button>
        </div>
        <div class="modal__body modal__body--pad">
            <input type="hidden" id="asig-usuario-id" value="">
            
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; padding-bottom:0.75rem; border-bottom:1px solid var(--color-border);">
                <span id="asig-seleccionadas-label" style="font-weight:600; font-size:0.9rem;">0 seleccionadas</span>
                <div style="display:flex; gap:0.5rem;">
                    <button type="button" class="btn btn--sm" onclick="AdminUsuarios.seleccionarTodasSucursales(true)">Seleccionar todas</button>
                    <button type="button" class="btn btn--sm" onclick="AdminUsuarios.seleccionarTodasSucursales(false)">Deseleccionar todas</button>
                </div>
            </div>

            <div id="asig-sucursales-grid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap:0.75rem; max-height:360px; overflow-y:auto; padding-right:0.25rem;">
                <!-- Generado dinámicamente por JS con checkboxes estilizados -->
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.75rem; margin-top:1.5rem; padding-top:1rem; border-top:1px solid var(--color-border);">
                <button type="button" class="btn" onclick="AdminUsuarios.cerrarModalSucursales()">Cancelar</button>
                <button type="button" class="btn btn--primary" onclick="AdminUsuarios.guardarAsignacionSucursales()"><?= ic('save') ?> Guardar asignación</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Restablecer Contraseña -->
<div class="modal-overlay" id="modal-reset-password">
    <div class="modal modal--sm" role="dialog" aria-modal="true" aria-labelledby="modal-reset-titulo">
        <div class="modal__header">
            <div>
                <h3 class="modal__title" id="modal-reset-titulo">Restablecer Contraseña</h3>
                <div id="modal-reset-usuario-sub" style="font-size: 0.85rem; color: var(--color-text-muted); margin-top: 0.2rem;"></div>
            </div>
            <button type="button" class="modal__close" aria-label="Cerrar" onclick="AdminUsuarios.cerrarModalPassword()"><?= ic('x') ?></button>
        </div>
        <div class="modal__body modal__body--pad">
            <input type="hidden" id="reset-usuario-id" value="">
            <div class="form-group" style="margin-bottom:1rem;">
                <label for="reset-nueva-password">Nueva contraseña</label>
                <input type="text" id="reset-nueva-password" class="field" placeholder="Mínimo 6 caracteres" autocomplete="off">
            </div>
            <div style="margin-bottom:1.5rem; display:flex; gap:0.5rem;">
                <button type="button" class="btn btn--sm" onclick="AdminUsuarios.generarPasswordAleatoria()">Generar aleatoria</button>
            </div>
            <button type="button" class="btn btn--primary btn--block" onclick="AdminUsuarios.confirmarResetPassword()"><?= ic('check') ?> Confirmar restablecimiento</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="assets/js/analytics.js"></script>
<script src="assets/js/admin.js"></script>
</body>
</html>
