/**
 * PATUJU POS — Controlador del Panel de Encargado de Sucursales (Fase 2 Rediseñada)
 * Flujo en 2 vistas: Sucursal (Resumen) → Catálogo → Edición Modal por Pestañas
 */

// Sesión invalidada en el servidor (usuario desactivado, eliminado o expirado):
// cualquier 401 de los endpoints manda al login en vez de dejar la vista en un estado falso.
(() => {
    const fetchOriginal = window.fetch.bind(window);
    window.fetch = async (...args) => {
        const res = await fetchOriginal(...args);
        if (res.status === 401) window.location.href = 'login.php';
        return res;
    };
})();

const Encargado = (() => {
    let sucursalesLista = [];
    let sucursalSeleccionadaId = null;
    let sucursalSeleccionadaNombre = '';
    let productosStock = [];
    let productoActivoModal = null;
    let modoVistaActual = 'cards'; // 'cards' o 'tabla'
    let tabModalActivo = 'precio';  // 'precio', 'ingreso', 'historial'
    let filtroRotacionActual = 'todos'; // 'todos', 'diaria', 'lenta'
    let vistaActual = 'sucursales'; // 'sucursales', 'auditoria' o 'analitica'
    let tabAuditoriaActual = 'ventas'; // 'ventas' o 'turnos'
    let detalleSucursalAbierto = false;
    let auditoriaInicializada = false;

    // Helper selector
    const $ = (sel) => document.querySelector(sel);

    // Seguridad: Sanitizar strings para prevenir XSS
    function escapeHtml(str) {
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Iconos SVG del sprite local
    const svgIcon = (name) => `<svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#${name}"></use></svg>`;

    function init() {
        iniciarReloj();
        cargarResumenSucursales();
    }

    // ── Reloj en tiempo real ───────────────────
    function iniciarReloj() {
        const el = $('#clock');
        if (!el) return;
        const actualizar = () => {
            const ahora = new Date();
            el.textContent = ahora.toLocaleTimeString('es-BO', {
                hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
            });
        };
        actualizar();
        setInterval(actualizar, 1000);
    }

    // ══════════════════════════════════════════════════════════════════
    // NAVEGACIÓN PRINCIPAL: SUCURSALES / AUDITORÍA
    // ══════════════════════════════════════════════════════════════════

    function marcarTabActivo(btn, activo) {
        if (!btn) return;
        btn.classList.toggle('active', activo);
    }

    function cambiarVista(vista) {
        vistaActual = vista === 'auditoria'
            ? 'auditoria'
            : (vista === 'analitica' ? 'analitica' : 'sucursales');

        const vSuc = $('#vista-sucursales');
        const vDet = $('#vista-detalle-sucursal');
        const vAud = $('#vista-auditoria');
        const vAna = $('#vista-analitica');
        const btnSuc = $('#btn-vista-sucursales');
        const btnAud = $('#btn-vista-auditoria');
        const btnAna = $('#btn-vista-analitica');

        if (vistaActual === 'analitica') {
            detalleSucursalAbierto = false;
            if (vSuc) vSuc.style.display = 'none';
            if (vDet) vDet.style.display = 'none';
            if (vAud) vAud.style.display = 'none';
            if (vAna) vAna.style.display = 'block';
            if ($('#topbar-subtitulo')) $('#topbar-subtitulo').textContent = 'Analítica';
            marcarTabActivo(btnSuc, false);
            marcarTabActivo(btnAud, false);
            marcarTabActivo(btnAna, true);

            if (typeof AdminAnalytics !== 'undefined') {
                AdminAnalytics.init();
            }
        } else if (vistaActual === 'auditoria') {
            detalleSucursalAbierto = false;
            if (typeof AdminAnalytics !== 'undefined') AdminAnalytics.detener();
            if (vSuc) vSuc.style.display = 'none';
            if (vDet) vDet.style.display = 'none';
            if (vAna) vAna.style.display = 'none';
            if (vAud) vAud.style.display = 'block';
            if ($('#topbar-subtitulo')) $('#topbar-subtitulo').textContent = 'Auditoría';
            marcarTabActivo(btnSuc, false);
            marcarTabActivo(btnAud, true);
            marcarTabActivo(btnAna, false);

            if (!auditoriaInicializada) {
                auditoriaInicializada = true;
                cargarAuditoriaVentas();
            }
            if (tabAuditoriaActual === 'turnos') {
                cargarCajasAbiertas();
                cargarAuditoriaTurnos();
            }
        } else {
            // Vista 'sucursales': volver a la vista principal de tarjetas/resumen sin mezclar con el detalle
            detalleSucursalAbierto = false;
            if (typeof AdminAnalytics !== 'undefined') AdminAnalytics.detener();
            if (vAud) vAud.style.display = 'none';
            if (vAna) vAna.style.display = 'none';
            if (vDet) vDet.style.display = 'none';
            if (vSuc) vSuc.style.display = 'block';
            if ($('#topbar-subtitulo')) $('#topbar-subtitulo').textContent = document.body.dataset.sucursalNombre || 'Mi Sucursal';
            marcarTabActivo(btnSuc, true);
            marcarTabActivo(btnAud, false);
            marcarTabActivo(btnAna, false);
            cargarResumenSucursales();
        }

        window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    }

    function cambiarTabAuditoria(tab) {
        tabAuditoriaActual = tab === 'turnos' ? 'turnos' : 'ventas';
        const esVentas = tabAuditoriaActual === 'ventas';

        const panelVentas = $('#panel-aud-ventas');
        const panelTurnos = $('#panel-aud-turnos');
        if (panelVentas) panelVentas.style.display = esVentas ? 'block' : 'none';
        if (panelTurnos) panelTurnos.style.display = esVentas ? 'none' : 'block';

        marcarTabActivo($('#tab-aud-ventas'), esVentas);
        marcarTabActivo($('#tab-aud-turnos'), !esVentas);

        if (esVentas) {
            cargarAuditoriaVentas();
        } else {
            cargarCajasAbiertas();
            cargarAuditoriaTurnos();
        }
    }

    function refrescarAuditoriaSiVisible() {
        if (vistaActual !== 'auditoria') return;
        if (tabAuditoriaActual === 'ventas') {
            cargarAuditoriaVentas();
        } else {
            cargarCajasAbiertas();
            cargarAuditoriaTurnos();
        }
    }

    function recargarAuditoria() {
        refrescarAuditoriaSiVisible();
    }

    // ── Auditoría: helpers ──────────────────────────────────────────
    const bsAud = (n) => 'Bs. ' + Number(n || 0).toFixed(2);

    function filaMensajeAud(tbody, cols, texto, esError) {
        tbody.innerHTML = `<tr><td colspan="${cols}" class="admin-empty-row"${esError ? ' style="color: var(--color-danger);"' : ''}>${escapeHtml(texto)}</td></tr>`;
    }

    // Mismo rango que valida el servidor (máx. 30 días); evita pedir algo que se recortaría
    function rangoAuditoriaValido(idDesde, idHasta) {
        const desde = $(idDesde)?.value || '';
        const hasta = $(idHasta)?.value || '';
        if (desde && hasta && desde > hasta) {
            mostrarNotificacion('La fecha "Desde" no puede ser posterior a "Hasta"', 'error');
            return null;
        }
        return { desde, hasta };
    }

    // ── Auditoría: ventas de mis sucursales ─────────────────────────
    async function cargarAuditoriaVentas() {
        const tbody = $('#aud-ventas-tbody');
        if (!tbody) return;
        const rango = rangoAuditoriaValido('#aud-venta-desde', '#aud-venta-hasta');
        if (!rango) return;

        filaMensajeAud(tbody, 7, 'Cargando ventas...');
        const params = new URLSearchParams({ limit: '200' });
        const sucId = $('#aud-venta-sucursal')?.value || '';
        if (sucId) params.append('sucursal_id', sucId);
        // Sin fechas el servidor devolvería solo hoy; se envían siempre
        if (rango.desde) params.append('fecha_desde', rango.desde);
        if (rango.hasta) params.append('fecha_hasta', rango.hasta);

        try {
            const res = await fetch(`ajax/historial.php?${params.toString()}`);
            const data = await res.json();
            if (!data.success) {
                filaMensajeAud(tbody, 7, data.error || 'No se pudo cargar la auditoría', true);
                return;
            }
            renderAuditoriaVentas(data.data || []);
        } catch (err) {
            filaMensajeAud(tbody, 7, 'Error de conexión', true);
        }
    }

    function renderAuditoriaVentas(ventas) {
        const tbody = $('#aud-ventas-tbody');
        const resumen = $('#aud-ventas-resumen');
        if (!tbody) return;

        const total = ventas.reduce((acc, v) => acc + (Number(v.total_raw) || 0), 0);
        if (resumen) {
            resumen.textContent = ventas.length
                ? `${ventas.length} venta(s) · Total ${bsAud(total)}` + (ventas.length >= 200 ? ' · se muestran las 200 más recientes: acota el rango para ver el resto' : '')
                : '';
        }
        if (ventas.length === 0) {
            filaMensajeAud(tbody, 7, 'No hay ventas en tus sucursales para este rango');
            return;
        }

        const badges = {
            efectivo: '<span class="badge" style="background:var(--color-success-bg);color:var(--color-success);">Efectivo</span>',
            qr: '<span class="badge" style="background:rgba(96,165,250,0.15);color:var(--color-info);">QR</span>'
        };
        const tarjeta = '<span class="badge" style="background:rgba(240,160,48,0.15);color:var(--color-primary-ink);">Tarjeta</span>';

        tbody.innerHTML = ventas.map(v => {
            const detalle = (v.detalle || []).map(d => `${escapeHtml(d.cantidad)}x ${escapeHtml(d.producto_nombre)} (${bsAud(d.subtotal)})`).join(', ')
                || `${escapeHtml(v.items_count)} items`;
            return `
                <tr>
                    <td><strong>#${escapeHtml(v.id)}</strong></td>
                    <td style="font-size: 0.85rem; color: var(--color-text-muted);">${escapeHtml(v.fecha)}</td>
                    <td><strong>${escapeHtml(v.sucursal_nombre)}</strong></td>
                    <td>${escapeHtml(v.cajero_nombre)}</td>
                    <td style="font-size: 0.85rem; max-width: 320px;">${detalle}</td>
                    <td>${badges[v.metodo_pago] || tarjeta}</td>
                    <td class="col-right" style="font-weight: 700; color: var(--color-primary-ink);">Bs. ${escapeHtml(v.total)}</td>
                </tr>`;
        }).join('');
    }

    // ── Auditoría: cajas abiertas ahora ─────────────────────────────
    async function cargarCajasAbiertas() {
        const tbody = $('#aud-cajas-abiertas-tbody');
        if (!tbody) return;
        filaMensajeAud(tbody, 7, 'Cargando cajas abiertas...');

        const params = new URLSearchParams({ abiertos: '1' });
        const sucId = $('#aud-turno-sucursal')?.value || '';
        if (sucId) params.append('sucursal_id', sucId);

        try {
            const res = await fetch(`ajax/turno_caja.php?${params.toString()}`);
            const data = await res.json();
            if (!data.success) {
                filaMensajeAud(tbody, 7, data.error || 'No se pudieron cargar las cajas', true);
                return;
            }
            renderCajasAbiertas(data.turnos || []);
        } catch (err) {
            filaMensajeAud(tbody, 7, 'Error de conexión', true);
        }
    }

    function renderCajasAbiertas(turnos) {
        const tbody = $('#aud-cajas-abiertas-tbody');
        const resumen = $('#aud-cajas-resumen');
        if (!tbody) return;
        if (resumen) resumen.textContent = turnos.length ? `${turnos.length} caja(s) con turno abierto` : '';
        if (turnos.length === 0) {
            filaMensajeAud(tbody, 7, 'No hay cajas abiertas en tus sucursales');
            return;
        }

        tbody.innerHTML = turnos.map(t => `
            <tr>
                <td><strong>${escapeHtml(t.sucursal_nombre)}</strong></td>
                <td>${escapeHtml(t.cajero_nombre)}</td>
                <td style="font-size: 0.85rem; color: var(--color-text-muted);">${escapeHtml(t.hora_apertura)}</td>
                <td class="col-right">${bsAud(t.ventas_sistema)} <small style="color: var(--color-text-muted);">(${parseInt(t.num_ventas, 10) || 0})</small></td>
                <td class="col-right">${bsAud(t.egresos_menores)}</td>
                <td class="col-right" style="font-weight: 700; color: var(--color-success);">${bsAud(t.efectivo_esperado)}</td>
                <td class="col-center">
                    <button type="button" class="btn" data-aud-cerrar="${escapeHtml(t.sucursal_id)}" style="font-size: 0.8rem; font-weight: 700; color: var(--color-danger); border-color: var(--color-danger);">Cerrar turno</button>
                </td>
            </tr>`).join('');

        tbody.onclick = (e) => {
            const btn = e.target.closest('[data-aud-cerrar]');
            if (btn) abrirModalCerrarTurno(btn.dataset.audCerrar);
        };
    }

    // ── Auditoría: cortes Z ─────────────────────────────────────────
    function filtrarAuditoriaTurnos() {
        cargarCajasAbiertas();
        cargarAuditoriaTurnos();
    }

    async function cargarAuditoriaTurnos() {
        const tbody = $('#aud-turnos-tbody');
        if (!tbody) return;
        const rango = rangoAuditoriaValido('#aud-turno-desde', '#aud-turno-hasta');
        if (!rango) return;

        filaMensajeAud(tbody, 9, 'Cargando cortes Z...');
        const params = new URLSearchParams({ auditoria: '1' });
        const sucId = $('#aud-turno-sucursal')?.value || '';
        const estado = $('#aud-turno-estado')?.value || '';
        if (sucId) params.append('sucursal_id', sucId);
        if (estado) params.append('estado', estado);
        if (rango.desde) params.append('fecha_desde', rango.desde);
        if (rango.hasta) params.append('fecha_hasta', rango.hasta);

        try {
            const res = await fetch(`ajax/turno_caja.php?${params.toString()}`);
            const data = await res.json();
            if (!data.success) {
                filaMensajeAud(tbody, 9, data.error || 'No se pudo cargar la auditoría de turnos', true);
                return;
            }
            renderAuditoriaTurnos(data.turnos || []);
        } catch (err) {
            filaMensajeAud(tbody, 9, 'Error de conexión', true);
        }
    }

    function renderAuditoriaTurnos(turnos) {
        const tbody = $('#aud-turnos-tbody');
        const resumen = $('#aud-turnos-resumen');
        if (!tbody) return;

        const conDiferencia = turnos.filter(t => t.estado === 'cerrado' && Number(t.diferencia) !== 0).length;
        if (resumen) {
            resumen.textContent = turnos.length
                ? `${turnos.length} turno(s) · ${conDiferencia} con diferencia` + (turnos.length >= 100 ? ' · se muestran los 100 más recientes' : '')
                : '';
        }
        if (turnos.length === 0) {
            filaMensajeAud(tbody, 9, 'No hay turnos en tus sucursales para este rango');
            return;
        }

        tbody.innerHTML = turnos.map(t => {
            const cerrado = t.estado === 'cerrado';
            const dif = t.diferencia !== null ? Number(t.diferencia) : null;
            let badge;
            if (!cerrado) {
                badge = '<span class="badge" style="background:rgba(96,165,250,0.15);color:var(--color-info);">Abierto</span>';
            } else if (dif === 0) {
                badge = '<span class="badge" style="background:var(--color-success-bg);color:var(--color-success);">Cuadrado</span>';
            } else if (dif > 0) {
                badge = `<span class="badge" style="background:rgba(52,211,153,0.15);color:var(--color-success);">Sobrante +${dif.toFixed(2)}</span>`;
            } else {
                badge = `<span class="badge" style="background:var(--color-danger-bg);color:var(--color-danger);">Faltante ${dif.toFixed(2)}</span>`;
            }
            return `
                <tr>
                    <td><strong>#${escapeHtml(t.id)}</strong></td>
                    <td><strong>${escapeHtml(t.sucursal_nombre)}</strong><br><small style="color: var(--color-text-muted);">${escapeHtml(t.cajero_nombre)}</small></td>
                    <td style="font-size: 0.8rem; color: var(--color-text-muted);">
                        ${escapeHtml(t.hora_apertura)}<br>${t.hora_cierre ? escapeHtml(t.hora_cierre) : '<em>En curso</em>'}
                    </td>
                    <td class="col-right">${bsAud(t.monto_apertura)}</td>
                    <td class="col-right">${t.ventas_sistema !== null ? bsAud(t.ventas_sistema) : '—'}</td>
                    <td class="col-right">${bsAud(t.egresos_menores)}</td>
                    <td class="col-right">${t.monto_cierre !== null ? bsAud(t.monto_cierre) : '—'}</td>
                    <td class="col-center">${badge}</td>
                    <td style="font-size: 0.8rem; max-width: 220px;">${escapeHtml(t.notas || '')}</td>
                </tr>`;
        }).join('');
    }

    // ══════════════════════════════════════════════════════════════════
    // VISTA 1: RESUMEN DE SUCURSALES (TARJETAS VISUALES)
    // ══════════════════════════════════════════════════════════════════

    async function cargarResumenSucursales() {
        const container = $('#grid-sucursales-container');
        if (container) {
            container.innerHTML = `
                <div style="grid-column: 1/-1; text-align: center; color: var(--color-text-muted); padding: 4rem 1rem; background: var(--color-surface); border-radius: var(--radius-lg); border: 1px solid var(--color-border);">

                    <div style="font-weight: 600;">Cargando estado de sucursales...</div>
                </div>
            `;
        }

        try {
            const res = await fetch('ajax/stock.php?resumen_sucursales=1');
            const data = await res.json();

            if (data.success) {
                sucursalesLista = data.sucursales || [];
                if (data.fecha_actual && $('#fecha-hoy-badge')) {
                    $('#fecha-hoy-badge').textContent = data.fecha_actual;
                }
                renderTarjetasSucursales(sucursalesLista, data.fecha_actual || new Date().toLocaleDateString('es-BO'));
            } else {
                if (container) {
                    container.innerHTML = `<div style="grid-column: 1/-1; text-align: center; color: var(--color-danger); padding: 3rem;">${escapeHtml(data.error || 'Error al cargar sucursales')}</div>`;
                }
            }
        } catch (err) {
            console.error('Error cargando resumen de sucursales:', err);
            if (container) {
                container.innerHTML = '<div style="grid-column: 1/-1; text-align: center; color: var(--color-danger); padding: 3rem;">Error de conexión con el servidor</div>';
            }
        }
    }

    function renderTarjetasSucursales(sucursales, fechaStr) {
        const container = $('#grid-sucursales-container');
        if (!container) return;
        container.innerHTML = '';

        // Prevenir cualquier duplicación por ID de sucursal (Bug 1 Fix)
        const seenIds = new Set();
        const sucursalesUnicas = (sucursales || []).filter(s => {
            if (!s || !s.id || seenIds.has(s.id)) return false;
            seenIds.add(s.id);
            return true;
        });

        if (sucursalesUnicas.length === 0) {
            container.innerHTML = `
                <div style="grid-column: 1/-1; text-align: center; color: var(--color-text-muted); padding: 4rem 1rem; background: var(--color-surface); border-radius: var(--radius-lg); border: 1px solid var(--color-border);">

                    <div>No hay sucursales asignadas a este usuario.</div>
                </div>
            `;
            return;
        }

        let html = '';
        sucursalesUnicas.forEach(s => {
            const totalProds = parseInt(s.total_productos, 10) || 0;
            const unidadesTotal = parseInt(s.stock_total_unidades, 10) || 0;
            const optimo = parseInt(s.stock_optimo_count, 10) || 0;
            const bajo = parseInt(s.stock_bajo_count, 10) || 0;
            const agotado = parseInt(s.stock_agotado_count, 10) || 0;
            const turnoAbierto = s.turno_estado === 'abierto';
            const ventasHoy = parseFloat(s.ventas_hoy || 0).toFixed(2);
            const numVentas = parseInt(s.num_ventas_hoy, 10) || 0;

            // Alerta visual en la tarjeta si hay productos agotados o críticos
            let cardBorder = 'border: 1px solid var(--color-border);';
            let alertBadge = '';
            if (agotado > 0) {
                cardBorder = 'border: 1px solid rgba(239, 68, 68, 0.4);';
                alertBadge = '<span style="background: var(--color-danger-bg); color: var(--color-danger); font-size: 0.72rem; font-weight: 700; padding: 0.2rem 0.6rem; border-radius: var(--radius-full);">' + agotado + ' agotados</span>';
            } else if (bajo > 0) {
                cardBorder = 'border: 1px solid rgba(240, 160, 48, 0.4);';
                alertBadge = '<span style="background: rgba(240, 160, 48, 0.15); color:var(--color-primary-ink); font-size: 0.72rem; font-weight: 700; padding: 0.2rem 0.6rem; border-radius: var(--radius-full);">' + bajo + ' con stock bajo</span>';
            }

            html += `
                <div class="sucursal-card" data-sucursal-id="${s.id}" role="button" tabindex="0" aria-label="Abrir sucursal ${escapeHtml(s.nombre)}" style="background: var(--color-surface); ${cardBorder} border-radius: var(--radius-lg); padding: 1.5rem; cursor: pointer; transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease; display: flex; flex-direction: column; justify-content: space-between; box-shadow: var(--shadow-sm);">
                    <div>
                        <!-- Encabezado de la Tarjeta con Código y Flecha -->
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem;">
                            <div>
                                <span style="font-size: 0.75rem; font-weight: 700; color:var(--color-primary-ink); text-transform: uppercase; letter-spacing: 0.05em;">${escapeHtml(s.codigo || 'SUC')}</span>
                                <h3 style="font-size: 1.25rem; font-weight: 800; color: var(--color-text); margin-top: 0.2rem;">${escapeHtml(s.nombre)}</h3>
                            </div>
                            <span style="font-size: 1.4rem; color:var(--color-primary-ink); line-height: 1; transition: transform 0.2s ease;" class="arrow-icon">→</span>
                        </div>

                        <!-- Info Encargado y Fecha -->
                        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem 1.2rem; margin-bottom: 1.1rem; font-size: 0.85rem; color: var(--color-text-muted); border-bottom: 1px solid var(--color-border); padding-bottom: 0.8rem;">
                            <div><strong>Encargado:</strong> ${escapeHtml(s.encargado_nombre)}</div>
                            <div><strong>Fecha:</strong> ${fechaStr}</div>
                        </div>

                        <!-- Resumen de Inventario (Estructura Solicitada) -->
                        <div style="background: var(--color-surface-2); border-radius: var(--radius-md); padding: 1rem; margin-bottom: 1.1rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.6rem;">
                                <strong style="font-size: 0.9rem; color: var(--color-text); display: flex; align-items: center; gap: 0.35rem;">
                                    <span><svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#package"></use></svg></span> Inventario
                                </strong>
                                ${alertBadge}
                            </div>
                            <ul style="list-style: none; padding: 0; margin: 0; font-size: 0.85rem; line-height: 1.6;">
                                <li style="color: var(--color-text-dim);">• <strong>${totalProds}</strong> productos registrados</li>
                                <li style="color: var(--color-success);">• <strong>${optimo}</strong> con stock óptimo</li>
                                <li style="color: ${bajo > 0 ? 'var(--color-primary)' : 'var(--color-text-muted)'};">• <strong>${bajo}</strong> con stock bajo</li>
                                <li style="color: ${agotado > 0 ? 'var(--color-danger)' : 'var(--color-text-muted)'};">• <strong>${agotado}</strong> sin stock</li>
                            </ul>
                            <div style="margin-top: 0.75rem; padding-top: 0.6rem; border-top: 1px solid var(--color-border); font-size: 0.85rem; color: var(--color-text); font-weight: 600;">
                                Total unidades en stock: <span style="color:var(--color-primary-ink); font-size: 1.05rem;">${unidadesTotal} u.</span>
                            </div>
                        </div>

                        <!-- Estado del Turno y Ventas -->
                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.82rem; margin-bottom: 0.5rem;">
                            <div>
                                ${turnoAbierto
                                    ? '<span style="color: var(--color-success); font-weight: 700;">Turno Abierto</span>' + (s.turno_cajero ? `<small style="display:block;color:var(--color-text-muted);">Cajero: ${escapeHtml(s.turno_cajero)}</small>` : '')
                                    : '<span style="color: var(--color-text-muted); font-weight: 600;">Turno Cerrado</span>'}
                            </div>
                            <div style="text-align: right;">
                                <span style="color: var(--color-text-muted);">Ventas hoy:</span>
                                <div style="font-weight: 700; color: var(--color-text);">Bs. ${ventasHoy} (${numVentas})</div>
                            </div>
                        </div>
                    </div>

                    <!-- Botón de Navegación Inferior (y cierre de turno si hay uno abierto) -->
                    <div style="display: flex; justify-content: flex-end; align-items: center; gap: 0.6rem; margin-top: 1.25rem; padding-top: 0.8rem; border-top: 1px solid var(--color-border);">
                        ${turnoAbierto
                            ? `<button type="button" class="btn" data-cerrar-turno="${escapeHtml(s.id)}" aria-label="Cerrar turno de ${escapeHtml(s.nombre)}" style="font-size: 0.85rem; font-weight: 700; padding: 0.45rem 0.9rem; border-radius: var(--radius-md); color: var(--color-danger); border-color: var(--color-danger);">Cerrar turno</button>`
                            : ''}
                        <span class="btn btn--primary" aria-hidden="true" style="font-size: 0.88rem; font-weight: 700; padding: 0.45rem 1.1rem; border-radius: var(--radius-md);">
                            Ver sucursal →
                        </span>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;

        // Delegación: el id vive en data-*, nunca en un onclick con texto del servidor
        container.onclick = (e) => {
            const btnCerrar = e.target.closest('[data-cerrar-turno]');
            if (btnCerrar) {
                abrirModalCerrarTurno(btnCerrar.dataset.cerrarTurno);
                return;
            }
            const card = e.target.closest('[data-sucursal-id]');
            if (card) abrirSucursalPorId(card.dataset.sucursalId);
        };
        container.onkeydown = (e) => {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            // El botón "Cerrar turno" ya responde a Enter/Espacio con su propio click
            if (e.target.closest('[data-cerrar-turno]')) return;
            const card = e.target.closest('[data-sucursal-id]');
            if (!card) return;
            e.preventDefault();
            abrirSucursalPorId(card.dataset.sucursalId);
        };
    }

    // ══════════════════════════════════════════════════════════════════
    // CIERRE DE TURNO SUPERVISADO (Corte Z de una caja a cargo)
    // Para el cajero que no cerró su turno o que fue reasignado con el turno abierto.
    // ══════════════════════════════════════════════════════════════════
    let sucursalCierreId = null;
    let focoPrevioCierre = null;

    async function abrirModalCerrarTurno(sucursalId) {
        sucursalCierreId = sucursalId;
        focoPrevioCierre = document.activeElement;
        const modal = $('#modal-cerrar-turno');
        const body = $('#cerrar-turno-body');
        if (!modal || !body) return;

        const sucursal = sucursalesLista.find(s => s.id == sucursalId);
        $('#titulo-cerrar-turno').textContent = 'Cerrar turno' + (sucursal ? ' — ' + sucursal.nombre : '');
        body.innerHTML = '<p style="color: var(--color-text-muted); text-align: center; padding: 2rem;">Cargando turnos abiertos...</p>';
        modal.classList.add('active');

        try {
            const res = await fetch(`ajax/turno_caja.php?abiertos=1&sucursal_id=${encodeURIComponent(sucursalId)}`);
            const data = await res.json();
            if (!data.success) {
                body.innerHTML = `<p style="color: var(--color-danger); text-align: center; padding: 2rem;">${escapeHtml(data.error || 'No se pudieron cargar los turnos')}</p>`;
                return;
            }
            renderTurnosAbiertos(data.turnos || []);
        } catch (err) {
            body.innerHTML = '<p style="color: var(--color-danger); text-align: center; padding: 2rem;">Error de conexión</p>';
        }
    }

    function renderTurnosAbiertos(turnos) {
        const body = $('#cerrar-turno-body');
        if (!body) return;

        if (turnos.length === 0) {
            body.innerHTML = '<p style="color: var(--color-text-muted); text-align: center; padding: 2rem;">Esta sucursal no tiene turnos abiertos.</p>';
            return;
        }

        const bs = (n) => 'Bs. ' + Number(n || 0).toFixed(2);
        body.innerHTML = turnos.map(t => {
            const id = escapeHtml(t.id);
            const apertura = new Date(String(t.hora_apertura).replace(' ', 'T')).toLocaleString('es-BO', {
                day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'
            });
            return `
                <div style="border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 1rem; margin-bottom: 1rem;">
                    <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; margin-bottom: 0.6rem;">
                        <strong>${escapeHtml(t.cajero_nombre)}</strong>
                        <span style="font-size: 0.8rem; color: var(--color-text-muted);">Abierto: ${escapeHtml(apertura)}</span>
                    </div>
                    <div style="background: var(--color-surface-2); border-radius: var(--radius-md); padding: 0.75rem; font-size: 0.88rem; line-height: 1.7;">
                        Fondo inicial: ${bs(t.monto_apertura)}<br>
                        Ventas del turno: ${bs(t.ventas_sistema)} (${parseInt(t.num_ventas, 10) || 0} ventas)<br>
                        Egresos: ${bs(t.egresos_menores)}<br>
                        <strong style="color: var(--color-success);">Efectivo esperado: ${bs(t.efectivo_esperado)}</strong>
                    </div>
                    <label for="ct-monto-${id}" style="display: block; font-size: 0.85rem; font-weight: 600; margin: 0.8rem 0 0.3rem;">Efectivo contado (Bs.)</label>
                    <input type="number" id="ct-monto-${id}" class="field" min="0" step="0.50" value="${Number(t.efectivo_esperado || 0).toFixed(2)}" style="width: 100%;">
                    <label for="ct-notas-${id}" style="display: block; font-size: 0.85rem; font-weight: 600; margin: 0.6rem 0 0.3rem;">Notas (opcional)</label>
                    <input type="text" id="ct-notas-${id}" class="field" maxlength="250" placeholder="Ej.: el cajero no cerró su turno" style="width: 100%;">
                    <button type="button" class="btn btn--primary" data-confirmar-cierre="${id}" style="width: 100%; margin-top: 0.8rem; font-weight: 700;">Cerrar turno (Corte Z)</button>
                </div>
            `;
        }).join('');

        body.onclick = (e) => {
            const btn = e.target.closest('[data-confirmar-cierre]');
            if (btn) confirmarCerrarTurno(btn.dataset.confirmarCierre, btn);
        };
        const primero = body.querySelector('input');
        if (primero) primero.focus();
    }

    async function confirmarCerrarTurno(turnoId, btn) {
        const monto = $(`#ct-monto-${turnoId}`)?.value ?? '';
        const notas = $(`#ct-notas-${turnoId}`)?.value ?? '';
        if (monto === '' || isNaN(Number(monto)) || Number(monto) < 0) {
            mostrarNotificacion('Ingresa el efectivo contado', 'error');
            return;
        }
        if (!confirm('¿Cerrar definitivamente este turno de caja?')) return;

        if (btn) btn.disabled = true;
        try {
            const res = await fetch('ajax/turno_caja.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ accion: 'cerrar_supervisado', turno_id: Number(turnoId), monto_cierre: Number(monto), notas })
            });
            const data = await res.json();
            if (!data.success) {
                mostrarNotificacion(data.error || 'No se pudo cerrar el turno', 'error');
                if (btn) btn.disabled = false;
                return;
            }
            const dif = Number(data.diferencia || 0);
            const detalle = dif === 0 ? 'cuadrado' : (dif > 0 ? `sobrante Bs. ${dif.toFixed(2)}` : `faltante Bs. ${Math.abs(dif).toFixed(2)}`);
            mostrarNotificacion(`Turno cerrado (${detalle})`, 'success');
            cargarResumenSucursales();
            refrescarAuditoriaSiVisible();
            abrirModalCerrarTurno(sucursalCierreId);
        } catch (err) {
            mostrarNotificacion('Error de conexión', 'error');
            if (btn) btn.disabled = false;
        }
    }

    function cerrarModalCerrarTurno() {
        const modal = $('#modal-cerrar-turno');
        if (!modal || !modal.classList.contains('active')) return;
        modal.classList.remove('active');
        sucursalCierreId = null;
        const previo = focoPrevioCierre;
        focoPrevioCierre = null;
        if (previo && document.body.contains(previo) && typeof previo.focus === 'function') previo.focus();
    }

    // Escape cierra el modal de cierre de turno
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') cerrarModalCerrarTurno();
    });

    function abrirSucursalPorId(id) {
        const sucursal = sucursalesLista.find(s => s.id == id);
        if (sucursal) {
            seleccionarSucursal(sucursal.id, sucursal.nombre, sucursal.turno_estado);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // VISTA 2: GESTIÓN DETALLADA DE LA SUCURSAL SELECCIONADA
    // ══════════════════════════════════════════════════════════════════

    function seleccionarSucursal(id, nombre, turnoEstado) {
        sucursalSeleccionadaId = id;
        sucursalSeleccionadaNombre = nombre;
        detalleSucursalAbierto = true;

        if (typeof AdminAnalytics !== 'undefined') AdminAnalytics.detener();

        // Ocultar vista tarjetas, auditoría y analítica; mostrar detalle
        const vSucursales = $('#vista-sucursales');
        const vDetalle = $('#vista-detalle-sucursal');
        const vAuditoria = $('#vista-auditoria');
        const vAnalitica = $('#vista-analitica');
        if (vSucursales) vSucursales.style.display = 'none';
        if (vAuditoria) vAuditoria.style.display = 'none';
        if (vAnalitica) vAnalitica.style.display = 'none';
        if (vDetalle) vDetalle.style.display = 'block';
        vistaActual = 'sucursales';
        marcarTabActivo($('#btn-vista-sucursales'), true);
        marcarTabActivo($('#btn-vista-auditoria'), false);
        marcarTabActivo($('#btn-vista-analitica'), false);

        // Actualizar datos de cabecera
        if ($('#detalle-sucursal-titulo')) $('#detalle-sucursal-titulo').textContent = nombre;
        if ($('#topbar-subtitulo')) $('#topbar-subtitulo').textContent = nombre;
        if ($('#topbar-sucursal-nombre')) $('#topbar-sucursal-nombre').textContent = nombre;

        // Badge de turno
        const turnoBadge = $('#detalle-turno-badge');
        if (turnoBadge) {
            if (turnoEstado === 'abierto') {
                turnoBadge.textContent = 'Turno Abierto';
                turnoBadge.style.background = 'var(--color-success-bg)';
                turnoBadge.style.color = 'var(--color-success)';
            } else {
                turnoBadge.textContent = 'Turno Cerrado';
                turnoBadge.style.background = 'var(--color-surface-2)';
                turnoBadge.style.color = 'var(--color-text-muted)';
            }
        }

        // Cargar inventario de esta sucursal
        cargarStockSucursal(id);
        cargarVentasDiaSucursal(id);
        window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    }

    function volverASucursales() {
        detalleSucursalAbierto = false;
        sucursalSeleccionadaId = null;
        vistaActual = 'sucursales';
        const vSucursales = $('#vista-sucursales');
        const vDetalle = $('#vista-detalle-sucursal');
        const vAuditoria = $('#vista-auditoria');
        const vAnalitica = $('#vista-analitica');
        if (vDetalle) vDetalle.style.display = 'none';
        if (vAuditoria) vAuditoria.style.display = 'none';
        if (vAnalitica) vAnalitica.style.display = 'none';
        if (vSucursales) vSucursales.style.display = 'block';
        if ($('#topbar-subtitulo')) $('#topbar-subtitulo').textContent = document.body.dataset.sucursalNombre || 'Mi Sucursal';
        marcarTabActivo($('#btn-vista-sucursales'), true);
        marcarTabActivo($('#btn-vista-auditoria'), false);
        marcarTabActivo($('#btn-vista-analitica'), false);

        cargarResumenSucursales();
        window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    }

    function recargarSucursalActual() {
        if (sucursalSeleccionadaId) {
            cargarStockSucursal(sucursalSeleccionadaId);
            cargarVentasDiaSucursal(sucursalSeleccionadaId);
        }
    }

    // ── Cargar Stock e Inventario de Sucursal ──────────────
    async function cargarStockSucursal(sucursalId) {
        const tbody = $('#tabla-stock-body');
        const containerCards = $('#cards-stock-container');
        if (tbody) tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--color-text-muted); padding: 3rem;">Cargando inventario...</td></tr>';
        if (containerCards) containerCards.innerHTML = '<div style="grid-column: 1/-1; text-align: center; color: var(--color-text-muted); padding: 3rem;">Cargando catálogo de productos...</div>';

        try {
            const url = `ajax/stock.php?sucursal_id=${sucursalId}`;
            const res = await fetch(url);
            const data = await res.json();

            if (data.success) {
                productosStock = data.data || [];
                actualizarKPIs();
                renderCards(productosStock);
                renderTabla(productosStock);
            } else {
                mostrarNotificacion(data.error || 'Error al cargar stock', 'error');
            }
        } catch (err) {
            console.error('Error cargando stock:', err);
            mostrarNotificacion('Error de conexión al cargar inventario', 'error');
        }
    }

    // ── Actualizar Métricas KPI ────────────────
    function actualizarKPIs() {
        let totalUnidades = 0;
        let stockBajo = 0;
        let agotados = 0;

        productosStock.forEach(p => {
            const cant = parseInt(p.cantidad_disponible, 10) || 0;
            const alerta = parseInt(p.alerta_minima, 10) || 10;

            totalUnidades += cant;
            if (cant === 0) {
                agotados++;
            } else if (cant <= alerta) {
                stockBajo++;
            }
        });

        if ($('#kpi-stock-total')) $('#kpi-stock-total').textContent = totalUnidades;
        if ($('#kpi-stock-bajo')) $('#kpi-stock-bajo').textContent = stockBajo;
        if ($('#kpi-stock-agotado')) $('#kpi-stock-agotado').textContent = agotados;
        if ($('#contador-productos')) $('#contador-productos').textContent = `${productosStock.length} productos`;
        if ($('#contador-productos-cards')) $('#contador-productos-cards').textContent = `${productosStock.length} productos`;
    }

    // ── Cargar Total de Ventas del Día de la Sucursal ─────────
    async function cargarVentasDiaSucursal(sucursalId) {
        try {
            const res = await fetch(`ajax/historial.php?hoy=1&sucursal_id=${sucursalId}`);
            const data = await res.json();
            if (data.success && $('#kpi-ventas-dia')) {
                $('#kpi-ventas-dia').textContent = `Bs. ${data.total_dia} (${data.ventas_dia} ventas)`;
            }
        } catch (err) {
            console.error('Error cargando ventas del día:', err);
        }
    }

    // ── Conmutador de Modo de Vista ───────────
    function cambiarModoVista(modo) {
        modoVistaActual = modo;
        const wrapCards = $('#cards-stock-wrap');
        const wrapTabla = $('#tabla-stock-wrap');
        const btnCards = $('#btn-vista-cards');
        const btnTabla = $('#btn-vista-tabla');

        if (modo === 'cards') {
            if (wrapCards) wrapCards.style.display = 'block';
            if (wrapTabla) wrapTabla.style.display = 'none';
            if (btnCards) { btnCards.style.background = 'var(--color-primary)'; btnCards.style.color = 'var(--color-on-primary)'; }
            if (btnTabla) { btnTabla.style.background = 'transparent'; btnTabla.style.color = 'var(--color-text-muted)'; }
        } else {
            if (wrapCards) wrapCards.style.display = 'none';
            if (wrapTabla) wrapTabla.style.display = 'block';
            if (btnTabla) { btnTabla.style.background = 'var(--color-primary)'; btnTabla.style.color = 'var(--color-on-primary)'; }
            if (btnCards) { btnCards.style.background = 'transparent'; btnCards.style.color = 'var(--color-text-muted)'; }
        }
    }

    // ── Filtro por Tipo de Rotación (Diaria vs Lenta) ──
    function filtrarRotacion(tipo) {
        filtroRotacionActual = tipo;

        const btns = {
            'todos': $('#btn-filtro-todos'),
            'diaria': $('#btn-filtro-diaria'),
            'lenta': $('#btn-filtro-lenta')
        };

        Object.keys(btns).forEach(k => {
            const btn = btns[k];
            if (!btn) return;
            if (k === tipo) {
                btn.style.background = 'var(--color-primary)';
                btn.style.color = 'var(--color-on-primary)';
            } else {
                btn.style.background = 'transparent';
                btn.style.color = 'var(--color-text-muted)';
            }
        });

        filtrarTabla();
    }

    // ── Renderizar Vista de Tarjetas (Cards Grid) ──
    function renderCards(lista) {
        const container = $('#cards-stock-container');
        if (!container) return;

        if (lista.length === 0) {
            container.innerHTML = '<div style="grid-column: 1/-1; text-align: center; color: var(--color-text-muted); padding: 3rem; background: var(--color-surface); border-radius: var(--radius-lg);">No se encontraron productos en esta categoría o sucursal</div>';
            if ($('#contador-productos-cards')) $('#contador-productos-cards').textContent = '0 productos';
            return;
        }

        if ($('#contador-productos-cards')) $('#contador-productos-cards').textContent = `${lista.length} productos`;

        let html = '';
        lista.forEach(p => {
            const cant = parseInt(p.cantidad_disponible, 10) || 0;
            const alerta = parseInt(p.alerta_minima, 10) || 10;
            const precioBase = parseFloat(p.precio_base || 0).toFixed(2);
            const precioEfectivo = parseFloat(p.precio_efectivo || p.precio_base || 0).toFixed(2);
            const tienePrecioPersonalizado = p.precio_sucursal !== null && p.precio_sucursal !== undefined && p.precio_sucursal !== '';
            const esDiaria = p.tipo_rotacion === 'diaria';
            const estaDisponible = p.disponible_venta !== undefined && p.disponible_venta !== null ? parseInt(p.disponible_venta, 10) === 1 : true;
            const stockPred = parseInt(p.stock_predeterminado, 10) || 0;

            let estadoBadge = '';
            let borderStyle = 'border: 1px solid var(--color-border);';
            if (!estaDisponible) {
                estadoBadge = '<span style="background: rgba(239, 68, 68, 0.2); color: var(--color-danger); padding: 0.25rem 0.6rem; border-radius: var(--radius-full); font-size: 0.72rem; font-weight: 700;">PAUSADO EN CAJA</span>';
                borderStyle = 'border: 1px dashed var(--color-danger); opacity: 0.85;';
            } else if (cant === 0) {
                estadoBadge = '<span style="background: var(--color-danger-bg); color: var(--color-danger); padding: 0.25rem 0.6rem; border-radius: var(--radius-full); font-size: 0.75rem; font-weight: 700;">AGOTADO</span>';
                borderStyle = 'border: 1px solid var(--color-danger);';
            } else if (cant <= alerta) {
                estadoBadge = '<span style="background: rgba(240, 160, 48, 0.15); color:var(--color-primary-ink); padding: 0.25rem 0.6rem; border-radius: var(--radius-full); font-size: 0.75rem; font-weight: 700;">STOCK BAJO</span>';
                borderStyle = 'border: 1px solid var(--color-primary);';
            } else {
                estadoBadge = '<span style="background: var(--color-success-bg); color: var(--color-success); padding: 0.25rem 0.6rem; border-radius: var(--radius-full); font-size: 0.75rem; font-weight: 700;">ÓPTIMO</span>';
            }

            const rotacionBadge = esDiaria
                ? '<span style="background: rgba(224, 122, 20, 0.15); color: #9c4a00; padding: 0.2rem 0.5rem; border-radius: var(--radius-full); font-size: 0.7rem; font-weight: 700;">Diaria</span>'
                : '<span style="background: var(--color-surface-2); color: var(--color-text-muted); padding: 0.2rem 0.5rem; border-radius: var(--radius-full); font-size: 0.7rem; font-weight: 600;">Lenta</span>';


            html += `
                <div class="product-card-encargado" onclick="Encargado.abrirModalAjuste(${p.producto_id})" style="background: var(--color-surface); ${borderStyle} border-radius: var(--radius-lg); padding: 1.25rem; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem;">

                            <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 0.3rem;">
                                ${estadoBadge}
                                ${rotacionBadge}
                            </div>
                        </div>
                        <h4 style="font-size: 1.05rem; font-weight: 700; color: var(--color-text); margin-bottom: 0.25rem;">${escapeHtml(p.producto_nombre)}</h4>
                        <div style="font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: 0.8rem;">
                            ${escapeHtml(p.categoria_nombre || 'General')}
                            ${esDiaria && stockPred > 0 ? ` • <span style="color: #9c4a00; font-weight: 600;">Plantilla: ${stockPred} u.</span>` : ''}
                        </div>
                    </div>

                    <div style="background: var(--color-surface-2); border-radius: var(--radius-md); padding: 0.8rem; margin-bottom: 0.8rem; display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-size: 0.75rem; color: var(--color-text-muted);">Stock Disponible</div>
                            <div style="font-size: 1.4rem; font-weight: 800; color: ${cant === 0 ? 'var(--color-danger)' : (cant <= alerta ? 'var(--color-primary)' : 'var(--color-success)')};">${cant} u.</div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 0.75rem; color: var(--color-text-muted);">Precio Sucursal</div>
                            <div style="font-size: 1.1rem; font-weight: 700; color: var(--color-text);">Bs. ${precioEfectivo}</div>
                            ${tienePrecioPersonalizado ? `<div style="font-size:0.68rem;color:var(--color-primary-ink);">Local (Base: ${precioBase})</div>` : ''}
                        </div>
                    </div>

                    <div style="display: flex; gap: 0.5rem; align-items: center; justify-content: space-between; border-top: 1px solid var(--color-border); padding-top: 0.75rem; font-size: 0.8rem;">
                        <button onclick="Encargado.cambiarDisponibilidadRapida(${p.producto_id}, ${estaDisponible ? 1 : 0}, event)" style="background: none; border: 1px solid ${estaDisponible ? 'var(--color-border)' : 'var(--color-danger)'}; color: ${estaDisponible ? 'var(--color-text-muted)' : 'var(--color-danger)'}; padding: 0.2rem 0.5rem; border-radius: var(--radius-sm); font-size: 0.72rem; cursor: pointer;">
                            ${estaDisponible ? svgIcon('eye') + ' Pausar en Caja' : 'Habilitar en Caja'}
                        </button>
                        <span style="color:var(--color-primary-ink); font-weight: 600;"><svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#pencil"></use></svg> Editar</span>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
    }

    // ── Renderizar Tabla de Inventario ────────
    function renderTabla(lista) {
        const tbody = $('#tabla-stock-body');
        if (!tbody) return;

        if (lista.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--color-text-muted); padding: 3rem;">No se encontraron productos en esta categoría o sucursal</td></tr>';
            return;
        }

        let html = '';
        lista.forEach(p => {
            const cant = parseInt(p.cantidad_disponible, 10) || 0;
            const alerta = parseInt(p.alerta_minima, 10) || 10;
            const precioBase = parseFloat(p.precio_base || 0).toFixed(2);
            const precioEfectivo = parseFloat(p.precio_efectivo || p.precio_base || 0).toFixed(2);
            const tienePrecioPersonalizado = p.precio_sucursal !== null && p.precio_sucursal !== undefined && p.precio_sucursal !== '';
            const esDiaria = p.tipo_rotacion === 'diaria';
            const estaDisponible = p.disponible_venta !== undefined && p.disponible_venta !== null ? parseInt(p.disponible_venta, 10) === 1 : true;

            let estadoBadge = '';
            let stockStyle = '';
            if (!estaDisponible) {
                estadoBadge = '<span style="background: rgba(239, 68, 68, 0.2); color: var(--color-danger); padding: 0.2rem 0.5rem; border-radius: var(--radius-full); font-size: 0.72rem; font-weight: 700;">PAUSADO</span>';
                stockStyle = 'color: var(--color-danger); text-decoration: line-through;';
            } else if (cant === 0) {
                estadoBadge = '<span style="background: var(--color-danger-bg); color: var(--color-danger); padding: 0.2rem 0.6rem; border-radius: var(--radius-full); font-size: 0.75rem; font-weight: 700;">AGOTADO</span>';
                stockStyle = 'color: var(--color-danger); font-weight: 800;';
            } else if (cant <= alerta) {
                estadoBadge = '<span style="background: rgba(240, 160, 48, 0.15); color:var(--color-primary-ink); padding: 0.2rem 0.6rem; border-radius: var(--radius-full); font-size: 0.75rem; font-weight: 700;">STOCK BAJO</span>';
                stockStyle = 'color:var(--color-primary-ink); font-weight: 700;';
            } else {
                estadoBadge = '<span style="background: var(--color-success-bg); color: var(--color-success); padding: 0.2rem 0.6rem; border-radius: var(--radius-full); font-size: 0.75rem; font-weight: 700;">ÓPTIMO</span>';
                stockStyle = 'color: var(--color-success); font-weight: 600;';
            }

            let precioHtml = `<strong>Bs. ${precioEfectivo}</strong>`;
            if (tienePrecioPersonalizado) {
                precioHtml += `<br><span style="font-size:0.75rem;color:var(--color-primary-ink);">Local (Base: ${precioBase})</span>`;
            } else {
                precioHtml += `<br><span style="font-size:0.75rem;color:var(--color-text-muted);">General</span>`;
            }

            html += `
                <tr style="border-bottom: 1px solid var(--color-border); ${!estaDisponible ? 'opacity: 0.7;' : ''}">
                    <td style="padding: 0.8rem 1rem;">
                        <div style="display: flex; align-items: center; gap: 0.6rem;">

                            <div>
                                <div style="font-weight: 600; color: var(--color-text);">${escapeHtml(p.producto_nombre)}</div>
                                <div style="font-size: 0.75rem; color: var(--color-text-dim);">
                                    ID #${p.producto_id} •
                                    <span style="color: ${esDiaria ? '#e07a14' : 'var(--color-text-muted)'}; font-weight: 600;">
                                        ${esDiaria ? 'Rotación Diaria' : 'Rotación Lenta'}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td style="padding: 0.8rem 1rem; color: var(--color-text-muted); font-size: 0.85rem;">
                        ${escapeHtml(p.categoria_nombre || 'General')}
                    </td>
                    <td style="padding: 0.8rem 1rem; text-align: center; font-size: 1.15rem; ${stockStyle}">
                        ${cant}
                    </td>
                    <td style="padding: 0.8rem 1rem; text-align: center; color: var(--color-text-muted);">
                        ${alerta}
                    </td>
                    <td style="padding: 0.8rem 1rem; text-align: right;">
                        ${precioHtml}
                    </td>
                    <td style="padding: 0.8rem 1rem; text-align: center;">
                        ${estadoBadge}
                    </td>
                    <td style="padding: 0.8rem 1rem; text-align: center;">
                        <div style="display: flex; gap: 0.35rem; justify-content: center;">
                            <button class="btn" style="padding: 0.3rem 0.6rem; font-size: 0.78rem;" aria-label="${estaDisponible ? 'Pausar en caja' : 'Habilitar en caja'}" onclick="Encargado.cambiarDisponibilidadRapida(${p.producto_id}, ${estaDisponible ? 1 : 0}, event)" title="${estaDisponible ? 'Pausar en caja' : 'Habilitar en caja'}">
                                ${estaDisponible ? svgIcon('eye') : svgIcon('power')}
                            </button>
                            <button class="btn" style="padding: 0.3rem 0.65rem; font-size: 0.78rem;" onclick="Encargado.abrirModalAjuste(${p.producto_id})">
                                <svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#pencil"></use></svg> Editar
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    }

    // ── Filtrado en tiempo real (Texto + Rotación) ──
    function filtrarTabla() {
        const query = ($('#buscador-stock')?.value || '').toLowerCase().trim();

        let filtrados = productosStock;

        // Filtro por rotación
        if (filtroRotacionActual === 'diaria') {
            filtrados = filtrados.filter(p => p.tipo_rotacion === 'diaria');
        } else if (filtroRotacionActual === 'lenta') {
            filtrados = filtrados.filter(p => p.tipo_rotacion === 'lenta');
        }

        // Filtro por texto
        if (query) {
            filtrados = filtrados.filter(p => {
                const nom = (p.producto_nombre || '').toLowerCase();
                const cat = (p.categoria_nombre || '').toLowerCase();
                return nom.includes(query) || cat.includes(query);
            });
        }

        renderCards(filtrados);
        renderTabla(filtrados);
    }

    // ══════════════════════════════════════════════════════════════════
    // MODAL DE AJUSTE / EDICIÓN INTEGRAL DE PRODUCTO (3 PESTAÑAS)
    // ══════════════════════════════════════════════════════════════════

    function cambiarTabModal(tab) {
        tabModalActivo = tab;

        // Botones
        ['precio', 'ingreso', 'historial'].forEach(t => {
            const btn = $(`#tab-modal-${t}`);
            const panel = $(`#panel-modal-${t}`);
            if (t === tab) {
                if (btn) {
                    btn.style.borderBottom = '2px solid var(--color-primary)';
                    btn.style.color = 'var(--color-primary-ink)';
                }
                if (panel) panel.style.display = 'block';
            } else {
                if (btn) {
                    btn.style.borderBottom = '2px solid transparent';
                    btn.style.color = 'var(--color-text-muted)';
                }
                if (panel) panel.style.display = 'none';
            }
        });

        if (tab === 'historial' && productoActivoModal) {
            cargarHistorialProducto(productoActivoModal.producto_id);
        }
    }

    function abrirModalAjuste(productoId) {
        const prod = productosStock.find(p => p.producto_id == productoId);
        if (!prod) return;

        productoActivoModal = prod;
        $('#ajuste-prod-id').value = prod.producto_id;
        $('#modal-ajuste-titulo').textContent = `${prod.producto_nombre}`;
        $('#modal-ajuste-subtitulo').textContent = `Sucursal: ${sucursalSeleccionadaNombre} • Stock actual: ${prod.cantidad_disponible} unidades`;

        // Tab 1: Precio y Parámetros
        $('#ajuste-precio-base-label').textContent = `Bs. ${parseFloat(prod.precio_base || 0).toFixed(2)}`;
        $('#ajuste-precio').value = prod.precio_sucursal !== null && prod.precio_sucursal !== undefined ? prod.precio_sucursal : '';
        $('#ajuste-precio').placeholder = `Base: Bs. ${parseFloat(prod.precio_base || 0).toFixed(2)}`;
        $('#ajuste-alerta').value = prod.alerta_minima || 10;
        if ($('#ajuste-predeterminado')) $('#ajuste-predeterminado').value = prod.stock_predeterminado || '';
        if ($('#ajuste-disponible-select')) $('#ajuste-disponible-select').value = (prod.disponible_venta !== undefined && prod.disponible_venta !== null) ? prod.disponible_venta : '1';

        // Tab 2: Ingreso
        $('#ingreso-stock-actual-label').textContent = `${prod.cantidad_disponible} unidades`;
        $('#ingreso-cantidad-unidades').value = '';
        if ($('#ingreso-motivo-custom')) $('#ingreso-motivo-custom').style.display = 'none';
        if ($('#ingreso-motivo-select')) $('#ingreso-motivo-select').value = 'Horneada mañana (07:00 AM)';

        // Iniciar en tab precio
        cambiarTabModal('precio');
        $('#modal-ajuste').classList.add('active');
    }

    function cerrarModalAjuste() {
        $('#modal-ajuste').classList.remove('active');
        productoActivoModal = null;
    }

    function actualizarMotivoIngreso() {
        const sel = $('#ingreso-motivo-select');
        const custom = $('#ingreso-motivo-custom');
        if (sel && custom) {
            custom.style.display = sel.value === 'otro' ? 'block' : 'none';
            if (sel.value === 'otro') custom.focus();
        }
    }

    // Guardar Precio y Alerta (Tab 1)
    async function guardarPrecioYAlerta() {
        if (!productoActivoModal) return;

        const productoId = parseInt($('#ajuste-prod-id').value, 10);
        const precio = $('#ajuste-precio').value.trim();
        const alerta = $('#ajuste-alerta').value.trim();
        const stockPred = $('#ajuste-predeterminado')?.value.trim();
        const disponible = $('#ajuste-disponible-select')?.value;

        try {
            const body = {
                accion: 'ajuste',
                producto_id: productoId,
                sucursal_id: sucursalSeleccionadaId,
                alerta_minima: alerta !== '' ? parseInt(alerta, 10) : 10,
                stock_predeterminado: stockPred !== '' && stockPred !== undefined ? parseInt(stockPred, 10) : 0,
                disponible_venta: disponible !== undefined ? parseInt(disponible, 10) : 1,
                motivo: 'Ajuste de parámetros por encargado'
            };

            if (precio !== '') {
                body.precio_sucursal = parseFloat(precio);
            } else {
                body.precio_sucursal = null; // Hereda base general
            }

            const res = await fetch('ajax/stock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });

            const data = await res.json();
            if (data.success) {
                mostrarNotificacion('Parámetros de producto guardados con éxito', 'success');
                cerrarModalAjuste();
                cargarStockSucursal(sucursalSeleccionadaId);
            } else {
                mostrarNotificacion(data.error || 'Error al guardar precio', 'error');
            }
        } catch (err) {
            console.error('Error guardando precio:', err);
            mostrarNotificacion('Error de conexión', 'error');
        }
    }

    // Guardar Ingreso de Mercadería (Tab 2)
    async function guardarIngresoMercaderia() {
        if (!productoActivoModal) return;

        const productoId = parseInt($('#ajuste-prod-id').value, 10);
        const cantidad = parseInt($('#ingreso-cantidad-unidades').value, 10);

        if (!cantidad || cantidad <= 0) {
            mostrarNotificacion('Ingresa una cantidad mayor a 0', 'error');
            return;
        }

        let motivo = $('#ingreso-motivo-select')?.value || 'Ingreso de mercadería';
        if (motivo === 'otro') {
            motivo = $('#ingreso-motivo-custom')?.value.trim() || 'Ingreso personalizado';
        }

        try {
            const res = await fetch('ajax/stock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    accion: 'ingreso_lote',
                    sucursal_id: sucursalSeleccionadaId,
                    items: [{ producto_id: productoId, cantidad: cantidad }],
                    motivo: motivo
                })
            });

            const data = await res.json();
            if (data.success) {
                mostrarNotificacion(`+${cantidad} unidades agregadas a ${productoActivoModal.producto_nombre}`, 'success');
                cerrarModalAjuste();
                cargarStockSucursal(sucursalSeleccionadaId);
            } else {
                mostrarNotificacion(data.error || 'Error al ingresar stock', 'error');
            }
        } catch (err) {
            console.error('Error ingresando stock:', err);
            mostrarNotificacion('Error de conexión', 'error');
        }
    }

    // Cargar Historial del Producto (Tab 3)
    async function cargarHistorialProducto(productoId) {
        const tbody = $('#historial-producto-body');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--color-text-muted);padding:1.5rem;">Cargando movimientos...</td></tr>';

        try {
            const res = await fetch(`ajax/stock.php?movimientos=1&sucursal_id=${sucursalSeleccionadaId}`);
            const data = await res.json();

            if (data.success) {
                const movs = (data.movimientos || []).filter(m => m.producto_id == productoId).slice(0, 8);

                if (movs.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--color-text-muted);padding:1.5rem;">Sin movimientos recientes para este producto</td></tr>';
                    return;
                }

                let html = '';
                movs.forEach(m => {
                    const cant = parseInt(m.cantidad, 10);
                    const esPositivo = m.tipo_movimiento === 'ingreso_lote' || m.tipo_movimiento === 'ajuste_positivo';
                    const signo = esPositivo ? `+${cant}` : `-${cant}`;
                    const badgeColor = esPositivo ? 'var(--color-success)' : (m.tipo_movimiento === 'venta' ? 'var(--color-text)' : 'var(--color-danger)');

                    html += `
                        <tr style="border-bottom: 1px solid var(--color-border);">
                            <td style="padding: 0.5rem 0.7rem; color: var(--color-text-muted); font-size: 0.78rem;">${m.created_at.substring(5, 16)}</td>
                            <td style="padding: 0.5rem 0.7rem; text-align: center;">
                                <span style="font-size: 0.72rem; padding: 0.15rem 0.5rem; border-radius: var(--radius-full); background: var(--color-surface-2); font-weight: 600;">${escapeHtml(m.tipo_movimiento)}</span>
                            </td>
                            <td style="padding: 0.5rem 0.7rem; text-align: center; font-weight: 700; color: ${badgeColor};">
                                ${signo}
                            </td>
                            <td style="padding: 0.5rem 0.7rem; font-size: 0.8rem;">
                                ${escapeHtml(m.motivo || '-')}
                            </td>
                        </tr>
                    `;
                });
                tbody.innerHTML = html;
            }
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--color-danger);padding:1.5rem;">Error al cargar historial</td></tr>';
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // MODAL: INGRESO MASIVO DE LOTE
    // ══════════════════════════════════════════════════════════════════

    function abrirModalLote() {
        const tbody = $('#lote-items-body');
        if (!tbody) return;

        let html = '';
        productosStock.forEach(p => {
            html += `
                <tr style="border-bottom: 1px solid var(--color-border);">
                    <td style="padding: 0.6rem 0.8rem; font-weight: 500;">
                        ${escapeHtml(p.producto_nombre)}
                    </td>
                    <td style="padding: 0.6rem 0.8rem; text-align: center; color: var(--color-text-muted);">
                        ${p.cantidad_disponible}
                    </td>
                    <td style="padding: 0.6rem 0.8rem; text-align: center;">
                        <input type="number" min="0" step="1" placeholder="0" class="input-lote-cant" data-prod-id="${p.producto_id}" style="width: 100px; text-align: center; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-sm); padding: 0.4rem; color: var(--color-text);">
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
        if ($('#lote-motivo')) $('#lote-motivo').value = '';
        $('#modal-lote').classList.add('active');
    }

    function cerrarModalLote() {
        $('#modal-lote').classList.remove('active');
    }

    async function guardarLote() {
        const inputs = document.querySelectorAll('.input-lote-cant');
        const items = [];

        inputs.forEach(inp => {
            const cant = parseInt(inp.value, 10);
            const prodId = parseInt(inp.dataset.prodId, 10);
            if (cant > 0 && prodId > 0) {
                items.push({ producto_id: prodId, cantidad: cant });
            }
        });

        if (items.length === 0) {
            mostrarNotificacion('Ingresa al menos una cantidad mayor a 0', 'error');
            return;
        }

        const motivo = $('#lote-motivo')?.value.trim() || 'Ingreso de mercadería / Horneada';
        const btn = $('#btn-guardar-lote');
        if (btn) btn.disabled = true;

        try {
            const res = await fetch('ajax/stock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    accion: 'ingreso_lote',
                    sucursal_id: sucursalSeleccionadaId,
                    items: items,
                    motivo: motivo
                })
            });

            const data = await res.json();
            if (data.success) {
                mostrarNotificacion(data.message || 'Lote ingresado con éxito', 'success');
                cerrarModalLote();
                cargarStockSucursal(sucursalSeleccionadaId);
            } else {
                mostrarNotificacion(data.error || 'Error al guardar lote', 'error');
            }
        } catch (err) {
            console.error('Error guardando lote:', err);
            mostrarNotificacion('Error de conexión al guardar lote', 'error');
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // MODAL: MOVIMIENTOS GENERALES DE INVENTARIO
    // ══════════════════════════════════════════════════════════════════

    async function abrirModalMovimientos() {
        $('#modal-movimientos').classList.add('active');
        const tbody = $('#movimientos-body');
        if (!tbody) return;

        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--color-text-muted); padding: 3rem;">Cargando historial de movimientos...</td></tr>';

        try {
            const res = await fetch(`ajax/stock.php?movimientos=1&sucursal_id=${sucursalSeleccionadaId}`);
            const data = await res.json();

            if (data.success) {
                renderMovimientos(data.movimientos || []);
            } else {
                tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; color: var(--color-danger); padding: 2rem;">${escapeHtml(data.error || 'Error')}</td></tr>`;
            }
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--color-danger); padding: 2rem;">Error de conexión</td></tr>';
        }
    }

    function cerrarModalMovimientos() {
        $('#modal-movimientos').classList.remove('active');
    }

    function renderMovimientos(movs) {
        const tbody = $('#movimientos-body');
        if (!tbody) return;

        if (movs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--color-text-muted); padding: 3rem;">Sin movimientos registrados en esta sucursal</td></tr>';
            return;
        }

        let html = '';
        movs.forEach(m => {
            const cant = parseInt(m.cantidad, 10);
            const esIngreso = m.tipo_movimiento === 'ingreso_lote' || m.tipo_movimiento === 'ajuste_positivo';
            const colorCambio = esIngreso ? 'var(--color-success)' : (m.tipo_movimiento === 'venta' ? 'var(--color-text)' : 'var(--color-danger)');
            const signo = esIngreso ? `+${cant}` : `-${cant}`;

            html += `
                <tr style="border-bottom: 1px solid var(--color-border);">
                    <td style="padding: 0.6rem 0.8rem; font-size: 0.8rem; color: var(--color-text-muted);">${m.created_at}</td>
                    <td style="padding: 0.6rem 0.8rem; font-weight: 600;">${escapeHtml(m.producto_nombre)}</td>
                    <td style="padding: 0.6rem 0.8rem; text-align: center;">
                        <span style="font-size: 0.75rem; padding: 0.2rem 0.6rem; border-radius: var(--radius-full); background: var(--color-surface-2); font-weight: 600;">${escapeHtml(m.tipo_movimiento)}</span>
                    </td>
                    <td style="padding: 0.6rem 0.8rem; text-align: center; font-weight: 700; color: ${colorCambio}; font-size: 0.95rem;">${signo}</td>
                    <td style="padding: 0.6rem 0.8rem; text-align: center; color: var(--color-text-muted); font-weight: 600;">${m.stock_posterior}</td>
                    <td style="padding: 0.6rem 0.8rem; font-size: 0.82rem;">
                        <div>${escapeHtml(m.motivo || '-')}</div>
                        <small style="color: var(--color-text-dim);">Por: ${escapeHtml(m.usuario_nombre || 'Sistema')}</small>
                    </td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
    }

    // ── CRUD Local: Alternar Disponibilidad en Caja ───────
    async function cambiarDisponibilidadRapida(productoId, estadoActual, event) {
        if (event) event.stopPropagation();
        const nuevoEstado = estadoActual ? 0 : 1;
        try {
            const res = await fetch('ajax/stock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    accion: 'cambiar_disponibilidad',
                    sucursal_id: sucursalSeleccionadaId,
                    producto_id: productoId,
                    disponible_venta: nuevoEstado
                })
            });
            const data = await res.json();
            if (data.success) {
                mostrarNotificacion(data.message, 'success');
                const p = productosStock.find(item => item.producto_id == productoId);
                if (p) p.disponible_venta = nuevoEstado;
                filtrarTabla();
            } else {
                mostrarNotificacion(data.error || 'Error al cambiar disponibilidad', 'error');
            }
        } catch (err) {
            mostrarNotificacion('Error de conexión', 'error');
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // MODAL: CARGA RÁPIDA DE STOCK DIARIO (HORNEADA MATUTINA)
    // ══════════════════════════════════════════════════════════════════

    function abrirModalStockDiario() {
        const tbody = $('#stock-diario-items-body');
        if (!tbody) return;

        const diarios = productosStock.filter(p => p.tipo_rotacion === 'diaria');
        if (diarios.length === 0) {
            mostrarNotificacion('No hay productos de rotación diaria registrados en esta sucursal', 'info');
            return;
        }

        let html = '';
        diarios.forEach(p => {
            const cantActual = parseInt(p.cantidad_disponible, 10) || 0;
            const pred = parseInt(p.stock_predeterminado, 10) || 50;

            html += `
                <tr style="border-bottom: 1px solid var(--color-border);">
                    <td style="padding: 0.6rem 0.8rem; font-weight: 600;">
                        ${escapeHtml(p.producto_nombre)}
                    </td>
                    <td style="padding: 0.6rem 0.8rem; text-align: center; color: var(--color-text-muted);">
                        ${cantActual} u.
                    </td>
                    <td style="padding: 0.6rem 0.8rem; text-align: center;">
                        <input type="number" min="0" step="1" value="${pred}" data-pred="${pred}" data-prod-id="${p.producto_id}" class="input-stock-diario" style="width: 100px; text-align: center; background: var(--color-surface); border: 1px solid var(--color-primary); border-radius: var(--radius-sm); padding: 0.4rem; color: var(--color-text); font-weight: 700; font-size: 0.95rem;">
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
        $('#modal-stock-diario').classList.add('active');
    }

    function cerrarModalStockDiario() {
        $('#modal-stock-diario').classList.remove('active');
    }

    function restablecerPredeterminadosDiarios() {
        const inputs = document.querySelectorAll('.input-stock-diario');
        inputs.forEach(inp => {
            inp.value = inp.dataset.pred || '50';
        });
        mostrarNotificacion('Cantidades restauradas a la plantilla estándar', 'info');
    }

    async function confirmarStockDiario() {
        const btn = $('#btn-confirmar-stock-diario');
        const inputs = document.querySelectorAll('.input-stock-diario');
        const items = [];

        inputs.forEach(inp => {
            const cant = parseInt(inp.value, 10);
            const prodId = parseInt(inp.dataset.prodId, 10);
            if (cant > 0 && prodId > 0) {
                items.push({ producto_id: prodId, cantidad: cant });
            }
        });

        if (items.length === 0) {
            mostrarNotificacion('Ingresa al menos una cantidad mayor a 0 para iniciar la jornada', 'error');
            return;
        }

        if (btn) btn.disabled = true;

        try {
            const res = await fetch('ajax/stock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    accion: 'cargar_predeterminado_diario',
                    sucursal_id: sucursalSeleccionadaId,
                    items: items,
                    motivo: 'Apertura de jornada: Horneada matutina'
                })
            });

            const data = await res.json();
            if (data.success) {
                mostrarNotificacion(data.message || 'Stock diario cargado exitosamente', 'success');
                cerrarModalStockDiario();
                cargarStockSucursal(sucursalSeleccionadaId);
            } else {
                mostrarNotificacion(data.error || 'Error al cargar stock diario', 'error');
            }
        } catch (err) {
            console.error('Error cargando stock diario:', err);
            mostrarNotificacion('Error de conexión', 'error');
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    async function ejecutarCierreMerma() {
        if (!confirm('¿Deseas cerrar las salteñas de la jornada? Las unidades no vendidas se registrarán como merma para dejar el stock listo para la horneada de mañana.')) {
            return;
        }

        try {
            const res = await fetch('ajax/stock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    accion: 'cierre_merma_diaria',
                    sucursal_id: sucursalSeleccionadaId,
                    motivo: 'Cierre de jornada / Merma de salteñas sobrantes'
                })
            });

            const data = await res.json();
            if (data.success) {
                mostrarNotificacion(data.message || 'Cierre completado', 'success');
                cargarStockSucursal(sucursalSeleccionadaId);
            } else {
                mostrarNotificacion(data.error || 'Error al procesar merma diaria', 'error');
            }
        } catch (err) {
            mostrarNotificacion('Error de conexión', 'error');
        }
    }

    // ── Toast Helper ───────────────────────────
    function mostrarNotificacion(mensaje, tipo = 'info') {
        const toast = document.createElement('div');
        toast.style.position = 'fixed';
        toast.style.bottom = '20px';
        toast.style.right = '20px';
        toast.style.padding = '0.8rem 1.2rem';
        toast.style.borderRadius = 'var(--radius-md)';
        toast.style.fontSize = '0.9rem';
        toast.style.fontWeight = '600';
        toast.style.zIndex = '9999';
        toast.style.boxShadow = 'var(--shadow-lg)';
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        toast.style.transition = reduceMotion ? 'none' : 'opacity 200ms cubic-bezier(0.23, 1, 0.32, 1), transform 200ms cubic-bezier(0.23, 1, 0.32, 1)';
        toast.style.opacity = reduceMotion ? '1' : '0';
        toast.style.transform = reduceMotion ? 'none' : 'translateX(24px)';

        if (tipo === 'success') {
            toast.style.background = 'var(--color-success)';
            toast.style.color = '#fff';
        } else if (tipo === 'error') {
            toast.style.background = 'var(--color-danger)';
            toast.style.color = '#fff';
        } else {
            toast.style.background = 'var(--color-primary)';
            toast.style.color = '#000';
        }

        toast.textContent = mensaje;
        document.body.appendChild(toast);
        if (!reduceMotion) {
            void toast.offsetWidth;
            requestAnimationFrame(() => {
                toast.style.opacity = '1';
                toast.style.transform = 'translateX(0)';
            });
        }

        setTimeout(() => {
            toast.style.opacity = '0';
            if (!reduceMotion) toast.style.transform = 'translateX(24px)';
            setTimeout(() => toast.remove(), reduceMotion ? 0 : 220);
        }, 3500);
    }

    // Inicializar al cargar DOM
    document.addEventListener('DOMContentLoaded', init);

    return {
        cargarResumenSucursales,
        seleccionarSucursal,
        volverASucursales,
        recargarSucursalActual,
        cambiarModoVista,
        filtrarTabla,
        filtrarRotacion,
        cambiarDisponibilidadRapida,
        abrirModalAjuste,
        cerrarModalAjuste,
        cambiarTabModal,
        actualizarMotivoIngreso,
        guardarPrecioYAlerta,
        guardarIngresoMercaderia,
        abrirModalStockDiario,
        cerrarModalStockDiario,
        restablecerPredeterminadosDiarios,
        confirmarStockDiario,
        ejecutarCierreMerma,
        abrirModalLote,
        cerrarModalLote,
        guardarLote,
        abrirModalMovimientos,
        cerrarModalMovimientos,
        abrirModalCerrarTurno,
        cerrarModalCerrarTurno,
        cambiarVista,
        cambiarTabAuditoria,
        recargarAuditoria,
        cargarAuditoriaVentas,
        filtrarAuditoriaTurnos
    };
})();
