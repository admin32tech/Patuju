/**
 * PATUJU POS — Controlador de Analítica Gerencial (Fase 3)
 *
 * Implementa estrictamente el informe de integridad de datos (Fase_3.md):
 * 1. Actualización periódica cada 30 segundos sin WebSockets innecesarios.
 * 2. Regla Fundamental: Nunca reemplazar datos válidos por un error de red.
 * 3. Control de estados visuales: 🟢 Actualizado | 🟡 Actualizando | 🟠 Antiguo | 🔴 Error.
 * 4. Control de respuestas fuera de orden mediante versiones de datos.
 * 5. Visualización mediante Chart.js (actualización mediante chart.update()).
 */

const AdminAnalytics = (() => {
    // ── Constantes y Configuración ──────────────────────────────────────────
    const INTERVALO_ACTUALIZACION_MS = 30000; // 30 segundos
    const LIMITE_DATO_ANTIGUO_SEG     = 90;    // 90 segundos

    // ── Estado Interno del Dashboard ────────────────────────────────────────
    let ultimoEstadoValido = null;
    let ultimaHoraValida   = null;
    let versionActual      = 0;
    let timerPolling       = null;
    let timerAntiguedad    = null;
    let peticionEnCurso    = false;

    // ── Instancias de Gráficos Chart.js ─────────────────────────────────────
    let chartSucursales = null;
    let chartHoras      = null;
    let chartProductos  = null;
    let chartTendencia  = null;
    let chartMes        = null;

    // ── Paleta de Colores Corporativa ───────────────────────────────────────
    const COLORES = {
        primary:       '#f59e0b',
        primaryAlpha:  'rgba(245, 158, 11, 0.75)',
        primaryBg:     'rgba(245, 158, 11, 0.15)',
        danger:        '#ef4444',
        dangerAlpha:   'rgba(239, 68, 68, 0.75)',
        success:       '#10b981',
        successAlpha:  'rgba(16, 185, 129, 0.75)',
        info:          '#3b82f6',
        infoAlpha:     'rgba(59, 130, 246, 0.75)',
        purple:        '#8b5cf6',
        purpleAlpha:   'rgba(139, 92, 246, 0.75)',
        gridBorder:    'rgba(255, 255, 255, 0.08)',
        textColor:     '#94a3b8'
    };

    // ── Inicialización del Módulo ───────────────────────────────────────────
    function init() {
        const container = document.getElementById('tab-content-analytics');
        if (!container) return;

        // Comprobar disponibilidad de Chart.js
        if (typeof Chart === 'undefined') {
            console.error('Chart.js no está cargado.');
            return;
        }

        // Configuración global de Chart.js para Dark Mode
        Chart.defaults.color = COLORES.textColor;
        Chart.defaults.font.family = 'system-ui, -apple-system, sans-serif';

        crearGraficosVacios();
        actualizarDatos();

        // Configurar actualización periódica
        if (timerPolling) clearInterval(timerPolling);
        timerPolling = setInterval(actualizarDatos, INTERVALO_ACTUALIZACION_MS);

        // Timer para evaluar antigüedad de datos cada 10 segundos
        if (timerAntiguedad) clearInterval(timerAntiguedad);
        timerAntiguedad = setInterval(evaluarAntiguedad, 10000);
    }

    // ── Inicialización de Canvases ──────────────────────────────────────────
    function crearGraficosVacios() {
        // 1. Unidades vendidas hoy por sucursal
        const ctxSuc = document.getElementById('chart-sucursales');
        if (ctxSuc && !chartSucursales) {
            chartSucursales = new Chart(ctxSuc, {
                type: 'bar',
                data: { labels: [], datasets: [{ label: 'Unidades Vendidas', data: [], backgroundColor: COLORES.primaryAlpha, borderRadius: 6 }] },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: COLORES.gridBorder } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }

        // 2. Horarios pico de venta (Ventas por Hora)
        const ctxHoras = document.getElementById('chart-horas');
        if (ctxHoras && !chartHoras) {
            chartHoras = new Chart(ctxHoras, {
                type: 'line',
                data: { labels: [], datasets: [{ label: 'Ventas (Bs.)', data: [], borderColor: COLORES.info, backgroundColor: 'rgba(59, 130, 246, 0.1)', fill: true, tension: 0.35 }] },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: COLORES.gridBorder } },
                        x: { grid: { color: COLORES.gridBorder } }
                    }
                }
            });
        }

        // 3. Top 10 Productos Más Vendidos
        const ctxProd = document.getElementById('chart-productos');
        if (ctxProd && !chartProductos) {
            chartProductos = new Chart(ctxProd, {
                type: 'bar',
                data: { labels: [], datasets: [{ label: 'Unidades', data: [], backgroundColor: COLORES.successAlpha, borderRadius: 6 }] },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { beginAtZero: true, grid: { color: COLORES.gridBorder } },
                        y: { grid: { display: false } }
                    }
                }
            });
        }

        // 4. Tendencia de Ventas (Últimos 30 Días)
        const ctxTend = document.getElementById('chart-tendencia');
        if (ctxTend && !chartTendencia) {
            chartTendencia = new Chart(ctxTend, {
                type: 'line',
                data: { labels: [], datasets: [{ label: 'Total Diario (Bs.)', data: [], borderColor: COLORES.primary, backgroundColor: COLORES.primaryBg, fill: true, tension: 0.3 }] },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: COLORES.gridBorder } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }

        // 5. Ventas por Mes (Últimos 12 meses)
        const ctxMes = document.getElementById('chart-mes');
        if (ctxMes && !chartMes) {
            chartMes = new Chart(ctxMes, {
                type: 'bar',
                data: {
                    labels: [],
                    datasets: [
                        {
                            label: 'Total (Bs.)',
                            data: [],
                            backgroundColor: COLORES.primaryAlpha,
                            borderColor: COLORES.primary,
                            borderWidth: 1,
                            borderRadius: 6
                        },
                        {
                            label: 'Unidades',
                            data: [],
                            backgroundColor: COLORES.infoAlpha,
                            borderColor: COLORES.info,
                            borderWidth: 1,
                            borderRadius: 6,
                            yAxisID: 'yUnidades'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top', labels: { boxWidth: 12 } }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: COLORES.gridBorder },
                            ticks: { callback: v => 'Bs.' + v.toLocaleString('es-BO') }
                        },
                        yUnidades: {
                            position: 'right',
                            beginAtZero: true,
                            grid: { drawOnChartArea: false }
                        },
                        x: { grid: { display: false } }
                    }
                }
            });
        }
    }

    // ── Actualización de Datos (Polling y Validación) ───────────────────────
    async function actualizarDatos() {
        if (peticionEnCurso) return;
        peticionEnCurso = true;

        mostrarEstado('actualizando');

        try {
            const res = await fetch('ajax/analytics.php');
            if (!res.ok) {
                throw new Error(`Error HTTP: ${res.status}`);
            }

            const respuesta = await res.json();
            if (!respuesta || !respuesta.ok || !respuesta.data) {
                throw new Error(respuesta?.error || 'Formato de respuesta inválido');
            }

            // Validación de respuesta fuera de orden
            const versionRespuesta = respuesta.data_version || 0;
            if (versionRespuesta < versionActual) {
                console.warn('Respuesta desfasada ignorada');
                peticionEnCurso = false;
                return;
            }

            // ÉXITO: Aceptar nuevo estado válido
            versionActual      = versionRespuesta;
            ultimoEstadoValido = respuesta.data;
            ultimaHoraValida   = new Date();

            // Actualizar interfaz y gráficos
            renderKPIs(ultimoEstadoValido.kpis);
            renderGraficos(ultimoEstadoValido);
            mostrarEstado('actualizado', ultimaHoraValida);

        } catch (error) {
            console.error('Error al actualizar analítica:', error);

            // REGLA FUNDAMENTAL DE FASE_3.MD:
            // NUNCA reemplazar estado ni poner gráficos en cero por un error.
            mostrarEstado('error', ultimaHoraValida);

        } finally {
            peticionEnCurso = false;
        }
    }

    // ── Renderizado de KPIs ─────────────────────────────────────────────────
    function renderKPIs(kpis) {
        if (!kpis) return;
        const setVal = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.textContent = val;
        };

        setVal('kpi-ventas-hoy',       'Bs. ' + Number(kpis.ventas_hoy || 0).toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
        setVal('kpi-transacciones-hoy', Number(kpis.transacciones_hoy || 0).toLocaleString('es-BO'));
        setVal('kpi-ticket-promedio',  'Bs. ' + Number(kpis.ticket_promedio_hoy || 0).toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
        setVal('kpi-ventas-mes',       'Bs. ' + Number(kpis.ventas_mes || 0).toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    }

    // ── Renderizado de Gráficos Chart.js ────────────────────────────────────
    function renderGraficos(data) {
        // 1. Sucursales (Solo sucursales con ventas o top relevantes)
        if (chartSucursales && data.ventas_por_sucursal) {
            // Comportamiento incremental: filtrar sucursales con ventas hoy o mostrar todas ordenadas
            const activas = data.ventas_por_sucursal.filter(s => parseInt(s.unidades_hoy) > 0);
            const lista = activas.length > 0 ? activas : data.ventas_por_sucursal.slice(0, 8);

            chartSucursales.data.labels = lista.map(s => s.nombre.replace('Patuju ', ''));
            chartSucursales.data.datasets[0].data = lista.map(s => parseInt(s.unidades_hoy));
            chartSucursales.update();
        }

        // 2. Horarios Pico
        if (chartHoras && data.ventas_por_hora) {
            chartHoras.data.labels = data.ventas_por_hora.map(h => h.hora_label);
            chartHoras.data.datasets[0].data = data.ventas_por_hora.map(h => parseFloat(h.total_bs));
            chartHoras.update();
        }

        // 3. Top Productos
        if (chartProductos && data.top_productos) {
            chartProductos.data.labels = data.top_productos.map(p => p.nombre);
            chartProductos.data.datasets[0].data = data.top_productos.map(p => parseInt(p.unidades_vendidas));
            chartProductos.update();
        }

        // 4. Tendencia 30 Días
        if (chartTendencia && data.tendencia_30_dias) {
            chartTendencia.data.labels = data.tendencia_30_dias.map(t => t.dia_label);
            chartTendencia.data.datasets[0].data = data.tendencia_30_dias.map(t => parseFloat(t.total_bs));
            chartTendencia.update();
        }

        // 5. Ventas por Mes
        if (chartMes && data.ventas_por_mes) {
            chartMes.data.labels = data.ventas_por_mes.map(m => m.mes_label);
            chartMes.data.datasets[0].data = data.ventas_por_mes.map(m => parseFloat(m.total_bs));
            chartMes.data.datasets[1].data = data.ventas_por_mes.map(m => parseInt(m.unidades));
            chartMes.update();
        }
    }

    // ── Indicador de Estado Visual (Fase_3.md) ──────────────────────────────
    function mostrarEstado(estado, fechaHora = null) {
        const badge = document.getElementById('analytics-status-badge');
        if (!badge) return;

        const horaStr = fechaHora ? fechaHora.toLocaleTimeString('es-BO') : '';

        if (estado === 'actualizando') {
            badge.className = 'badge-status status-updating';
            badge.innerHTML = '🟡 Actualizando datos...';
        } else if (estado === 'actualizado') {
            badge.className = 'badge-status status-ok';
            badge.innerHTML = `🟢 Actualizado (${horaStr})`;
        } else if (estado === 'antiguo') {
            badge.className = 'badge-status status-warning';
            badge.innerHTML = `🟠 Datos antiguos (${horaStr})`;
        } else if (estado === 'error') {
            badge.className = 'badge-status status-error';
            badge.innerHTML = horaStr
                ? `🔴 Error de actualización (Último dato válido: ${horaStr})`
                : `🔴 No se pudo conectar con el servidor`;
        }
    }

    function evaluarAntiguedad() {
        if (!ultimaHoraValida || peticionEnCurso) return;
        const segundos = Math.floor((new Date() - ultimaHoraValida) / 1000);
        if (segundos > LIMITE_DATO_ANTIGUO_SEG) {
            mostrarEstado('antiguo', ultimaHoraValida);
        }
    }

    // ── Modal de Exportación y Descarga ─────────────────────────────────────
    function abrirModalExportar() {
        const modal = document.getElementById('modal-exportar-reporte');
        if (modal) modal.classList.add('active');
    }

    function cerrarModalExportar() {
        const modal = document.getElementById('modal-exportar-reporte');
        if (modal) modal.classList.remove('active');
    }

    function descargarReporte() {
        const tipo  = document.getElementById('rep-tipo')?.value  || 'ventas_sucursal';
        const desde = document.getElementById('rep-desde')?.value || '';
        const hasta = document.getElementById('rep-hasta')?.value || '';

        const url = `ajax/analytics.php?export=csv&tipo=${encodeURIComponent(tipo)}&desde=${encodeURIComponent(desde)}&hasta=${encodeURIComponent(hasta)}`;
        window.location.href = url;
        cerrarModalExportar();
    }

    function imprimirReporte() {
        window.print();
    }

    // ── API Pública ─────────────────────────────────────────────────────────
    return {
        init,
        actualizar: actualizarDatos,
        abrirModalExportar,
        cerrarModalExportar,
        descargarReporte,
        imprimirReporte
    };
})();
