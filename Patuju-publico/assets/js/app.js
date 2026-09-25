/**
 * PATUJU POS — Motor Principal de Caja
 * Maneja: catálogo, carrito, ventas, historial
 */

const App = (() => {
    // ── Estado ────────────────────────────────
    let carrito = [];
    let productos = [];
    let categoriaActiva = 'todas';

    // ── DOM refs ──────────────────────────────
    const $ = (sel) => document.querySelector(sel);
    const $$ = (sel) => document.querySelectorAll(sel);

    // ── Seguridad: Escapar HTML para prevenir XSS ──
    function escapeHtml(str) {
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ── Init ──────────────────────────────────
    // Se ha movido más abajo en el archivo para consolidar lógicas de turno.

    // ── Reloj en tiempo real ──────────────────
    function actualizarReloj() {
        const el = $('#clock');
        if (el) {
            const now = new Date();
            el.textContent = now.toLocaleTimeString('es-BO', {
                hour: '2-digit', minute: '2-digit', second: '2-digit'
            });
        }
    }

    // ── Cargar productos desde API ────────────
    async function cargarProductos() {
        try {
            const res = await fetch('ajax/productos.php');
            const data = await res.json();
            if (data.success) {
                productos = data.data;
                renderCatalogo();
                renderFiltros();
            }
        } catch (err) {
            toast('Error al cargar productos', 'error');
        }
    }

    // ── Render: Filtros de categoría ──────────
    function renderFiltros() {
        const container = $('#cat-filters');
        if (!container) return;

        let html = `<button class="cat-filter__btn active" data-cat="todas">🏪 Todas</button>`;
        productos.forEach(cat => {
            html += `<button class="cat-filter__btn" data-cat="${cat.id}">${cat.icono} ${cat.nombre}</button>`;
        });
        container.innerHTML = html;

        container.addEventListener('click', (e) => {
            const btn = e.target.closest('.cat-filter__btn');
            if (!btn) return;
            $$('.cat-filter__btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            categoriaActiva = btn.dataset.cat;
            renderCatalogo();
        });
    }

    // ── Render: Catálogo de productos ─────────
    function renderCatalogo() {
        const container = $('#catalogo-productos');
        if (!container) return;

        const filtered = categoriaActiva === 'todas'
            ? productos
            : productos.filter(c => c.id == categoriaActiva);

        let html = '';
        filtered.forEach(cat => {
            html += `
                <div class="categoria-section">
                    <h3 class="categoria-section__title">${escapeHtml(cat.icono)} ${escapeHtml(cat.nombre)}</h3>
                    <div class="productos-grid">
                        ${cat.productos.map(p => {
                            const stock = parseInt(p.cantidad_disponible);
                            const alerta = parseInt(p.alerta_minima);
                            const stockAgotado = stock <= 0;
                            const stockBajo = !stockAgotado && stock <= alerta;
                            
                            let stockHtml = '';
                            if (stockAgotado) {
                                stockHtml = `<div style="position:absolute;top:5px;right:5px;background:var(--color-danger);color:#fff;padding:0.2rem 0.5rem;border-radius:4px;font-size:0.75rem;font-weight:bold;">Agotado</div>`;
                            } else if (stockBajo) {
                                stockHtml = `<div style="position:absolute;top:5px;right:5px;background:var(--color-warning);color:#000;padding:0.2rem 0.5rem;border-radius:4px;font-size:0.75rem;font-weight:bold;">Stock: ${stock}</div>`;
                            } else {
                                stockHtml = `<div style="position:absolute;top:5px;right:5px;background:rgba(0,0,0,0.6);color:#fff;padding:0.2rem 0.5rem;border-radius:4px;font-size:0.75rem;">Stock: ${stock}</div>`;
                            }

                            return `
                            <div class="producto-card ${stockAgotado ? 'agotado' : ''}" data-id="${p.id}" data-nombre="${escapeHtml(p.nombre)}" data-precio="${p.precio}" data-stock="${stock}">
                                ${stockHtml}
                                <img class="producto-card__img" 
                                     src="assets/img/${escapeHtml(p.imagen)}" 
                                     alt="${escapeHtml(p.nombre)}"
                                     loading="lazy"
                                     onerror="this.src='assets/img/default.png'"
                                     ${stockAgotado ? 'style="opacity:0.4;grayscale:1;"' : ''}>
                                <div class="producto-card__body">
                                    <div class="producto-card__nombre">${escapeHtml(p.nombre)}</div>
                                    <div class="producto-card__precio">${parseFloat(p.precio).toFixed(2)}</div>
                                    <div class="producto-card__action">
                                        <input type="number" class="producto-card__qty" id="qty-${p.id}" value="1" min="1" max="${stock}" inputmode="numeric" ${stockAgotado ? 'disabled' : ''}>
                                        <button class="producto-card__btn-add" onclick="App.agregarMultiplesAlCarrito(${p.id})" ${stockAgotado ? 'disabled' : ''}>➕</button>
                                    </div>
                                </div>
                            </div>
                            `;
                        }).join('')}
                    </div>
                </div>
            `;
        });
        container.innerHTML = html;
    }

    // ── Agregar al carrito ────────────────────
    function agregarAlCarrito(el) {
        const id = parseInt(el.dataset.id);
        const nombre = el.dataset.nombre;
        const precio = parseFloat(el.dataset.precio);

        // Animación visual
        el.classList.remove('added');
        void el.offsetWidth; // reflow
        el.classList.add('added');

        const existente = carrito.find(item => item.id === id);
        if (existente) {
            existente.cantidad++;
        } else {
            carrito.push({ id, nombre, precio, cantidad: 1 });
        }

        renderCarrito();
    }

    // ── Agregar múltiples al carrito ──────────
    function agregarMultiplesAlCarrito(id) {
        const qtyInput = $('#qty-' + id);
        const qty = parseInt(qtyInput.value) || 1;
        if (qty <= 0) return;
        
        let p = null;
        for (let cat of productos) {
            let found = cat.productos.find(prod => prod.id == id);
            if (found) { p = found; break; }
        }
        if (!p) return;

        qtyInput.value = 1; // reset visual

        const el = qtyInput.closest('.producto-card');
        el.classList.remove('added');
        void el.offsetWidth;
        el.classList.add('added');

        const existente = carrito.find(item => item.id === id);
        if (existente) {
            existente.cantidad += qty;
        } else {
            carrito.push({ id, nombre: p.nombre, precio: parseFloat(p.precio), cantidad: qty });
        }

        renderCarrito();
    }

    // ── Render: Carrito / Caja ────────────────
    function renderCarrito() {
        const container = $('#caja-items');
        const totalEl = $('#caja-total');
        const badgeEl = $('#caja-badge');
        const btnCobrar = $('#btn-cobrar');
        const btnCobrarPrint = $('#btn-cobrar-imprimir');

        if (!container) return;

        if (carrito.length === 0) {
            container.innerHTML = `
                <div class="caja__empty">
                    <span class="caja__empty-icon">🛒</span>
                    <span>Toca un producto para agregarlo</span>
                </div>
            `;
            totalEl.textContent = '0.00';
            badgeEl.textContent = '0';
            if (btnCobrar) btnCobrar.disabled = true;
            if (btnCobrarPrint) btnCobrarPrint.disabled = true;
            return;
        }

        let total = 0;
        let totalItems = 0;

        let html = '';
        carrito.forEach((item, index) => {
            const subtotal = item.precio * item.cantidad;
            total += subtotal;
            totalItems += item.cantidad;

            html += `
                <div class="cart-item">
                    <div class="cart-item__info">
                        <div class="cart-item__nombre">${escapeHtml(item.nombre)}</div>
                        <div class="cart-item__precio">Bs. ${item.precio.toFixed(2)} c/u</div>
                    </div>
                    <div class="cart-item__controls">
                        <button class="cart-item__btn" onclick="App.cambiarCantidad(${index}, -1)">−</button>
                        <span class="cart-item__qty">${item.cantidad}</span>
                        <button class="cart-item__btn" onclick="App.cambiarCantidad(${index}, 1)">+</button>
                        <span class="cart-item__subtotal">Bs. ${subtotal.toFixed(2)}</span>
                        <button class="cart-item__btn cart-item__btn--remove" onclick="App.eliminarItem(${index})">✕</button>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
        totalEl.textContent = total.toFixed(2);
        badgeEl.textContent = totalItems;
        if (btnCobrar) btnCobrar.disabled = false;
        if (btnCobrarPrint) btnCobrarPrint.disabled = false;
    }

    // ── Modificar cantidad ────────────────────
    // BUG-03 FIX: Valida el límite de stock disponible antes de incrementar
    function cambiarCantidad(index, delta) {
        if (delta > 0) {
            const item = carrito[index];
            let stockDisponible = Infinity;
            for (const cat of productos) {
                const prod = cat.productos.find(p => p.id == item.id);
                if (prod) {
                    stockDisponible = parseInt(prod.cantidad_disponible, 10) || 0;
                    break;
                }
            }
            if (item.cantidad >= stockDisponible) {
                toast(`⚠️ Stock máximo disponible: ${stockDisponible} u.`, 'error');
                return;
            }
        }
        carrito[index].cantidad += delta;
        if (carrito[index].cantidad <= 0) {
            carrito.splice(index, 1);
        }
        renderCarrito();
    }

    // ── Eliminar item ─────────────────────────
    function eliminarItem(index) {
        carrito.splice(index, 1);
        renderCarrito();
    }

    // ── Turnos de Caja (Fase 2) ───────────────────────────────
    let turnoActual = null;

    async function checkTurnoAbierto() {
        try {
            const res = await fetch('ajax/turno_caja.php');
            const data = await res.json();
            if (data.success && data.turno) {
                turnoActual = data.turno;
                const btn = $('#btn-turno');
                if(btn) btn.textContent = '💼 Turno (Abierto)';
            } else {
                turnoActual = null;
                const modal = $('#modal-abrir-turno');
                if(modal) modal.classList.add('active');
            }
        } catch (e) {
            console.error(e);
        }
    }

    async function abrirTurno() {
        const input = $('#turno-monto-apertura');
        const res = await fetch('ajax/turno_caja.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ accion: 'abrir', monto_apertura: input.value || 0 })
        });
        const data = await res.json();
        if (data.success) {
            toast('✅ Turno abierto correctamente');
            $('#modal-abrir-turno').classList.remove('active');
            checkTurnoAbierto();
        } else {
            toast('❌ ' + data.error, 'error');
        }
    }

    async function abrirModalGestionTurno() {
        if (!turnoActual) return toast('Debes abrir un turno primero', 'error');
        
        // Obtenemos el Corte X para hacérselo fácil al cajero
        const res = await fetch('ajax/turno_caja.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ accion: 'corte_x' })
        });
        const data = await res.json();
        if (data.success) {
            $('#turno-info').innerHTML = `
                <div style="background:rgba(0,0,0,0.2);padding:1rem;border-radius:6px;">
                    <strong>Resumen Rápido:</strong><br>
                    Fondo inicial: Bs. ${data.monto_apertura.toFixed(2)}<br>
                    Ventas del turno: Bs. ${data.ventas_sistema.toFixed(2)} (${data.num_ventas} ventas)<br>
                    Egresos: Bs. ${data.egresos.toFixed(2)}<br>
                    <strong style="color:var(--color-success);font-size:1.15rem;display:block;margin-top:0.5rem;">
                        Efectivo esperado: Bs. ${data.efectivo_esperado.toFixed(2)}
                    </strong>
                    <span style="color:var(--color-text-dim);font-size:0.8rem;">*Sugerencia: Usa este monto si tu caja cuadra perfecto.</span>
                </div>
            `;
            // PRE-LLENAR para que cerrar la caja sea solo darle a "Aceptar"
            $('#cierre-monto').value = data.efectivo_esperado.toFixed(2);
            $('#egreso-monto').value = '';
            $('#egreso-motivo').value = '';
            $('#modal-gestion-turno').classList.add('active');
        }
    }

    async function registrarEgreso() {
        const monto = $('#egreso-monto').value;
        const motivo = $('#egreso-motivo').value;
        if (!monto || !motivo) return toast('Ingresa monto y motivo', 'error');

        const res = await fetch('ajax/turno_caja.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ accion: 'egreso', monto, motivo })
        });
        const data = await res.json();
        if (data.success) {
            toast('✅ ' + data.message);
            abrirModalGestionTurno(); // Recargar para actualizar el Corte X
        } else {
            toast('❌ ' + data.error, 'error');
        }
    }

    async function cerrarTurno() {
        const monto = $('#cierre-monto').value;
        if (monto === '') return toast('Ingresa el monto contado', 'error');

        if (!confirm('¿Estás seguro de cerrar el turno de caja definitivamente?')) return;

        const res = await fetch('ajax/turno_caja.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ accion: 'cerrar', monto_cierre: monto })
        });
        const data = await res.json();
        if (data.success) {
            let msg = `Turno Cerrado. Diferencia: Bs. ${data.diferencia.toFixed(2)}`;
            if (data.diferencia === 0) msg = '✅ Turno Cuadrado Perfecto!';
            alert(msg);
            window.location.reload(); // Recargar para forzar apertura de nuevo turno
        } else {
            toast('❌ ' + data.error, 'error');
        }
    }

    function cerrarModalGestionTurno() {
        $('#modal-gestion-turno').classList.remove('active');
    }

    // ── Proceso de Cobro (Fase 2) ─────────────────────────────
    let imprimirPendiente = false;

    function registrarVenta(imprimir = false) {
        if (carrito.length === 0) return;
        if (!turnoActual) return toast('❌ Debes abrir un turno primero', 'error');

        imprimirPendiente = imprimir;
        const total = parseFloat($('#caja-total').textContent);
        $('#cobro-total-label').textContent = total.toFixed(2);
        
        // Reset modal a efectivo por defecto
        const radioEf = document.querySelector('input[name="metodo_pago"][value="efectivo"]');
        if (radioEf) radioEf.checked = true;
        togglePagoEfectivo(true);
        
        // Pre-llenar exacto para cobro rápido
        const recibidoInput = $('#cobro-recibido');
        if (recibidoInput) {
            recibidoInput.value = total.toFixed(2);
        }
        calcularVuelto();
        
        $('#modal-cobro').classList.add('active');
        if (recibidoInput) {
            setTimeout(() => recibidoInput.focus(), 100);
        }
    }

    function togglePagoEfectivo(show) {
        const details = $('#cobro-efectivo-details');
        if (details) {
            details.style.display = show ? 'block' : 'none';
        }
    }

    function calcularVuelto() {
        const total = parseFloat($('#caja-total').textContent) || 0;
        const recibido = parseFloat($('#cobro-recibido').value) || 0;
        const cambioEl = $('#cobro-cambio');
        if (cambioEl) {
            const cambio = recibido - total;
            cambioEl.textContent = 'Bs. ' + (cambio >= 0 ? cambio.toFixed(2) : '0.00');
            cambioEl.style.color = cambio >= 0 ? 'var(--color-success)' : 'var(--color-danger)';
        }
    }

    function cerrarModalCobro() {
        $('#modal-cobro').classList.remove('active');
    }

    async function procesarVentaConfirmada() {
        const btn = $('#btn-confirmar-cobro');
        btn.disabled = true;
        btn.textContent = 'Procesando...';

        const total = parseFloat($('#caja-total').textContent) || 0;
        const metodo = document.querySelector('input[name="metodo_pago"]:checked').value;
        let recibido = null;
        let cambio = null;

        if (metodo === 'efectivo') {
            recibido = parseFloat($('#cobro-recibido').value) || 0;
            if (recibido < total) {
                toast('❌ El monto recibido es menor al total', 'error');
                btn.disabled = false;
                btn.textContent = 'Confirmar y Registrar Venta';
                return;
            }
            cambio = recibido - total;
        }

        const sucursalId = parseInt(document.body.dataset.sucursalId) || 1;
        const sucursalNombre = document.body.dataset.sucursalNombre || 'Principal';

        try {
            const res = await fetch('ajax/registrar_venta.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    items: carrito, 
                    sucursal_id: sucursalId,
                    metodo_pago: metodo,
                    monto_recibido: recibido,
                    cambio: cambio
                })
            });
            const data = await res.json();

            if (data.success) {
                toast(`✅ ${data.mensaje}`, 'success');

                if (imprimirPendiente) {
                    imprimirTicket(data.venta_id || '001', carrito, total, sucursalNombre, metodo, recibido, cambio);
                }

                carrito = [];
                renderCarrito();
                cargarTotalDia();
                cerrarModalCobro();
                cargarProductos(); // Recargar para actualizar stock en UI
            } else {
                toast('❌ ' + (data.error || 'Error al registrar'), 'error');
            }
        } catch (err) {
            toast('❌ Error de conexión', 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Confirmar y Registrar Venta';
        }
    }

    // ── Init ──────────────────────────────────
    function init() {
        checkTurnoAbierto();
        cargarProductos();
        actualizarReloj();
        setInterval(actualizarReloj, 1000);
        setInterval(cargarTotalDia, 60000);
        cargarTotalDia();

        // Event: cerrar modal
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal-overlay')) {
                // No permitir cerrar el modal de abrir turno haciendo clic fuera
                if (e.target.id === 'modal-abrir-turno') return;
                cerrarModal();
                cerrarModalCobro();
                cerrarModalGestionTurno();
            }
        });

        // Tecla ESC
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if ($('#modal-abrir-turno').classList.contains('active')) return;
                cerrarModal();
                cerrarModalCobro();
                cerrarModalGestionTurno();
            }
        });
    }

    // ── Generar e Imprimir Ticket ─────────────
    function imprimirTicket(ventaId, items, total, sucursalNombre, metodo = 'efectivo', recibido = 0, cambio = 0) {
        const ticketEl = $('#ticket-print');
        if (!ticketEl) return;

        const fecha = new Date().toLocaleString('es-BO', {
            day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit'
        });

        let filasHtml = items.map(item => `
            <tr>
                <td style="text-align:center;">${item.cantidad}</td>
                <td>${escapeHtml(item.nombre)}</td>
                <td style="text-align:right;">${(item.precio * item.cantidad).toFixed(2)}</td>
            </tr>
        `).join('');

        let pagoHtml = `<span>PAGO (${metodo.toUpperCase()}):</span><span>Bs. ${total.toFixed(2)}</span>`;
        if (metodo === 'efectivo') {
            pagoHtml = `
                <span>RECIBIDO:</span><span>Bs. ${recibido.toFixed(2)}</span><br>
                <span>CAMBIO:</span><span>Bs. ${cambio.toFixed(2)}</span>
            `;
        }

        ticketEl.innerHTML = `
            <div class="ticket">
                <div class="ticket__header">
                    <h2 class="ticket__title">PATUJU POS</h2>
                    <p class="ticket__sub">Salteñería & Tradición</p>
                    <p class="ticket__info">Sucursal: <strong>${escapeHtml(sucursalNombre)}</strong></p>
                    <p class="ticket__info">Ticket #: <strong>${ventaId}</strong> | ${fecha}</p>
                </div>
                <div class="ticket__divider">--------------------------------</div>
                <table class="ticket__table">
                    <thead>
                        <tr>
                            <th style="width:15%;text-align:center;">CNT</th>
                            <th style="text-align:left;">CONCEPTO</th>
                            <th style="width:25%;text-align:right;">SUBT</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${filasHtml}
                    </tbody>
                </table>
                <div class="ticket__divider">--------------------------------</div>
                <div class="ticket__total">
                    <span>TOTAL:</span>
                    <span>Bs. ${total.toFixed(2)}</span>
                </div>
                <div class="ticket__divider">--------------------------------</div>
                <div class="ticket__total" style="font-size:0.9rem; margin-top:5px;">
                    ${pagoHtml}
                </div>
                <div class="ticket__divider">--------------------------------</div>
                <div class="ticket__footer">
                    <p>¡Gracias por su preferencia!</p>
                    <p>*** Recibo sin valor fiscal ***</p>
                </div>
            </div>
        `;

        window.print();
    }

    // ── Cargar total del día ──────────────────
    async function cargarTotalDia() {
        try {
            const res = await fetch('ajax/historial.php?hoy=1');
            const data = await res.json();
            if (data.success) {
                const el = $('#total-dia');
                if (el) el.textContent = `Bs. ${data.total_dia} (${data.ventas_dia} ventas)`;
            }
        } catch (err) {
            // silencioso
        }
    }

    // ── Ver Historial ─────────────────────────
    async function verHistorial() {
        const overlay = $('#modal-overlay');
        const body = $('#modal-body');
        const title = $('#modal-title');

        title.textContent = '📋 Historial de Ventas';
        body.innerHTML = '<p style="color:var(--color-text-muted)">Cargando...</p>';
        overlay.classList.add('active');

        try {
            const res = await fetch('ajax/historial.php');
            const data = await res.json();

            if (data.success && data.data.length > 0) {
                let html = `
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Fecha / Hora</th>
                                <th>Sucursal</th>
                                <th>Detalle</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                `;
                data.data.forEach(v => {
                    const detalle = v.detalle.map(d =>
                        `${d.cantidad}x ${escapeHtml(d.producto_nombre)}`
                    ).join(', ');

                    const fecha = new Date(v.fecha).toLocaleString('es-BO', {
                        day: '2-digit', month: '2-digit',
                        hour: '2-digit', minute: '2-digit'
                    });

                    html += `
                        <tr>
                            <td>${v.id}</td>
                            <td>${fecha}</td>
                            <td>${escapeHtml(v.sucursal_nombre || v.sucursal_id)}</td>
                            <td class="history-table__detalle">${detalle}</td>
                            <td class="history-table__total">Bs. ${v.total}</td>
                        </tr>
                    `;
                });
                html += '</tbody></table>';
                body.innerHTML = html;
            } else {
                body.innerHTML = '<p style="color:var(--color-text-muted);text-align:center;padding:2rem;">No hay ventas registradas aún.</p>';
            }
        } catch (err) {
            body.innerHTML = '<p style="color:var(--color-danger)">Error al cargar historial</p>';
        }
    }

    // ── Cerrar modal ──────────────────────────
    function cerrarModal() {
        const overlay = $('#modal-overlay');
        if (overlay) overlay.classList.remove('active');
    }

    // ── Toast / Notificaciones ────────────────
    function toast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        if (!container) return;

        const icon = type === 'success' ? '✅' : '❌';
        const el = document.createElement('div');
        el.className = `toast toast--${type}`;
        el.innerHTML = `
            <span class="toast__icon">${icon}</span>
            <span class="toast__message">${message}</span>
        `;
        container.appendChild(el);
        setTimeout(() => el.remove(), 3200);
    }

    // ── API Pública ───────────────────────────
    return {
        init,
        agregarAlCarrito,
        agregarMultiplesAlCarrito,
        cambiarCantidad,
        eliminarItem,
        registrarVenta,
        procesarVentaConfirmada,
        togglePagoEfectivo,
        calcularVuelto,
        cerrarModalCobro,
        abrirTurno,
        abrirModalGestionTurno,
        cerrarModalGestionTurno,
        registrarEgreso,
        cerrarTurno,
        verHistorial,
        cerrarModal,
        toast
    };
})();

// Iniciar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', App.init);
