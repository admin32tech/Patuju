/**
 * PATUJU POS — Módulo de Administración y Auditoría Multi-Sucursal (Fase 2)
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

const Admin = (() => {
    let editandoId = null;
    let tabActual = 'productos';
    let lastSyncTimestamp = 0; // BUG-E: usado para detectar cambios y auto-refrescar
    let productosCache = [];

    const $ = (sel) => document.querySelector(sel);

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

    // Iconos SVG del sprite local
    const svgIcon = (name) => `<svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#${name}"></use></svg>`;

    function init() {
        cargarProductos();
        const form = $('#admin-form');
        if (form) {
            form.addEventListener('submit', guardarProducto);
        }

        // Delegación de eventos: evita inyectar datos en atributos onclick
        const tbody = $('#admin-tbody');
        if (tbody) {
            tbody.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-action]');
                if (!btn) return;
                const id = parseInt(btn.dataset.id, 10);
                const prod = productosCache.find(p => p.id == id);
                if (!prod) return;

                if (btn.dataset.action === 'editar') {
                    editarProducto(prod.id, prod.nombre, prod.precio, prod.categoria_id, prod.imagen || '', prod.descripcion || '');
                } else if (btn.dataset.action === 'desactivar') {
                    desactivarProducto(prod.id, prod.nombre);
                } else if (btn.dataset.action === 'activar') {
                    activarProducto(prod.id, prod.nombre);
                }
            });
        }

        startAutoSync(); // BUG-E FIX: inicia el polling de sincronización
    }

    // ── Cambio de Pestañas ─────────────────────
    const TITULOS_TAB = {
        'productos': ['Catálogo de productos', 'Crea, edita y desactiva los productos que se venden en todas las sucursales.'],
        'stock': ['Stock por sucursal', 'Ajusta existencias, alertas mínimas y precios locales de cada sede.'],
        'auditoria-ventas': ['Auditoría de ventas', 'Revisa quién vendió qué, cuándo y en qué sucursal.'],
        'auditoria-turnos': ['Cierres de turno', 'Compara lo que dice el sistema con el monto contado en cada corte Z.'],
        'analytics': ['Analítica', 'Ventas, horarios pico y productos líderes de toda la red.'],
        'usuarios': ['Gestión de usuarios y sucursales', 'Administra accesos, contraseñas y asigna sucursales a los encargados.']
    };

    function cambiarTab(tab) {
        tabActual = tab;
        const [titulo, descripcion] = TITULOS_TAB[tab] || ['', ''];
        const h1 = $('#admin-titulo'), p = $('#admin-descripcion');
        if (h1) h1.textContent = titulo;
        if (p) p.textContent = descripcion;

        // Actualizar botones
        document.querySelectorAll('.btn-tab').forEach(btn => btn.classList.remove('active'));
        const btnActivo = $(`#tab-btn-${tab}`);
        if (btnActivo) btnActivo.classList.add('active');

        // Actualizar vistas
        document.querySelectorAll('.tab-content').forEach(sec => sec.style.display = 'none');
        const secActiva = $(`#tab-content-${tab}`);
        if (secActiva) secActiva.style.display = 'block';

        // Carga diferida según tab
        if (tab === 'productos') {
            cargarProductos();
        } else if (tab === 'stock') {
            cargarStockSucursal();
        } else if (tab === 'auditoria-ventas') {
            cargarAuditoriaVentas();
        } else if (tab === 'auditoria-turnos') {
            cargarAuditoriaTurnos();
        } else if (tab === 'analytics') {
            if (typeof AdminAnalytics !== 'undefined') {
                AdminAnalytics.init();
            }
        } else if (tab === 'usuarios') {
            if (typeof AdminUsuarios !== 'undefined') {
                AdminUsuarios.cargar();
            }
        }
    }

    // ══════════════════════════════════════════
    // PESTAÑA 1: CRUD PRODUCTOS
    // ══════════════════════════════════════════

    async function cargarProductos() {
        try {
            const res = await fetch('ajax/crud_productos.php');
            const data = await res.json();

            if (data.success) {
                productosCache = data.productos || [];
                renderTablaProductos(data.productos);
                renderCategorias(data.categorias);
            }
        } catch (err) {
            console.error('Error cargando productos:', err);
        }
    }

    function renderCategorias(categorias) {
        const sel = $('#prod-categoria');
        if (!sel) return;
        sel.innerHTML = '<option value="">Seleccionar categoría...</option>';
        categorias.forEach(c => {
            sel.innerHTML += `<option value="${escapeHtml(c.id)}">${escapeHtml(c.nombre)}</option>`;
        });
    }

    function renderTablaProductos(productos) {
        const tbody = $('#admin-tbody');
        if (!tbody) return;

        if (productos.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--color-text-muted);padding:2rem;">No hay productos registrados</td></tr>';
            return;
        }

        let html = '';
        productos.forEach(p => {
            const activo = p.activo == 1;
            const badgeClass = activo ? 'badge--active' : 'badge--inactive';
            const badgeText  = activo ? 'Activo' : 'Inactivo';
            const precio     = parseFloat(p.precio).toFixed(2);

            html += `
                <tr class="${activo ? '' : 'admin-row--inactive'}">
                    <td><strong>#${p.id}</strong></td>
                    <td>
                        <div class="admin-prod-cell">

                            <div>
                                <div class="admin-prod-name">${escapeHtml(p.nombre)}</div>
                                <div class="admin-prod-desc">${escapeHtml(p.descripcion || '')}</div>
                            </div>
                        </div>
                    </td>
                    <td><span class="badge">${escapeHtml(p.categoria_nombre || 'Sin cat.')}</span></td>
                    <td><strong>Bs. ${precio}</strong></td>
                    <td><span class="badge ${badgeClass}">${badgeText}</span></td>
                    <td>
                        <div class="admin-actions">
                            <button class="btn btn--sm" data-action="editar" data-id="${p.id}" title="Editar" aria-label="Editar ${escapeHtml(p.nombre)}"><svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#pencil"></use></svg></button>
                            ${activo
                                ? `<button class="btn btn--sm btn--danger" data-action="desactivar" data-id="${p.id}" title="Desactivar" aria-label="Desactivar ${escapeHtml(p.nombre)}"><svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#trash"></use></svg></button>`
                                : `<button class="btn btn--sm" data-action="activar" data-id="${p.id}" title="Reactivar" aria-label="Reactivar ${escapeHtml(p.nombre)}"><svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#refresh"></use></svg></button>`
                            }
                        </div>
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    }

    async function guardarProducto(e) {
        e.preventDefault();

        const nombre      = $('#prod-nombre').value.trim();
        const precio      = parseFloat($('#prod-precio').value);
        const categoriaId = parseInt($('#prod-categoria').value);
        const imagen      = $('#prod-imagen').value.trim() || 'default.png';
        const descripcion = $('#prod-descripcion').value.trim();

        if (!nombre || isNaN(precio) || !categoriaId) {
            alert('Por favor completa todos los campos requeridos (*)');
            return;
        }

        const payload = {
            nombre,
            precio,
            categoria_id: categoriaId,
            imagen,
            descripcion
        };

        const btnSubmit = $('#admin-form-submit');
        btnSubmit.disabled = true;
            btnSubmit.innerHTML = svgIcon('save') + ' Guardando...';

        try {
            let res;
            if (editandoId) {
                // BUG-D FIX: El id siempre va en la URL (?id=X) porque crud_productos.php
                // lo lee de $_GET['id']. Antes se ponía en el body y se ignoraba.
                res = await fetch(`ajax/crud_productos.php?id=${editandoId}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
            } else {
                res = await fetch('ajax/crud_productos.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
            }

            const data = await res.json();
            if (data.success) {
                limpiarFormulario();
                cargarProductos();
            } else {
                alert(data.error || 'Error al guardar');
            }
        } catch (err) {
            console.error('Error:', err);
            alert('Error de conexión');
        } finally {
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = svgIcon('save') + (editandoId ? ' Actualizar' : ' Guardar');
        }
    }

    function editarProducto(id, nombre, precio, categoriaId, imagen, descripcion) {
        editandoId = id;
        $('#admin-form-title').textContent = `Editar Producto #${id}`;
        $('#prod-nombre').value = nombre;
        $('#prod-precio').value = precio;
        $('#prod-categoria').value = categoriaId;
        $('#prod-imagen').value = imagen;
        $('#prod-descripcion').value = descripcion;
        $('#admin-form-submit').innerHTML = svgIcon('save') + ' Actualizar';
        $('#admin-form-cancel').style.display = 'inline-flex';
        window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    }

    function limpiarFormulario() {
        editandoId = null;
        $('#admin-form').reset();
        $('#admin-form-title').textContent = 'Nuevo Producto';
        $('#admin-form-submit').innerHTML = svgIcon('save') + ' Guardar';
        $('#admin-form-cancel').style.display = 'none';
    }

    async function desactivarProducto(id, nombre) {
        if (!confirm(`¿Desactivar "${nombre}"? No aparecerá en la caja.`)) return;

        try {
            const res = await fetch(`ajax/crud_productos.php?id=${id}`, { method: 'DELETE' });
            const data = await res.json();
            if (data.success) {
                cargarProductos();
            } else {
                alert(data.error || 'Error al desactivar');
            }
        } catch (err) {
            console.error('Error:', err);
        }
    }

    async function activarProducto(id, nombre) {
        if (!confirm(`¿Reactivar "${nombre}"? Volverá a estar disponible.`)) return;

        try {
            // BUG-C FIX: Antes se enviaba { reactivar: true } que el backend ignoraba.
            // El endpoint PUT espera los campos del producto incluyendo activo=1.
            // Se recuperan los datos actuales del producto antes de enviar.
            const resGet = await fetch('ajax/crud_productos.php');
            const dataGet = await resGet.json();
            const prod = dataGet.success ? (dataGet.productos || []).find(p => p.id == id) : null;

            const payload = prod
                ? { nombre: prod.nombre, precio: prod.precio, categoria_id: prod.categoria_id, imagen: prod.imagen || 'default.png', descripcion: prod.descripcion || '', activo: 1 }
                : { nombre, precio: 0, categoria_id: 1, imagen: 'default.png', descripcion: '', activo: 1 };

            const res = await fetch(`ajax/crud_productos.php?id=${id}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.success) {
                cargarProductos();
            } else {
                alert(data.error || 'Error al reactivar');
            }
        } catch (err) {
            console.error('Error:', err);
        }
    }

    // ══════════════════════════════════════════
    // PESTAÑA 2: STOCK MULTI-SUCURSAL (Editable por Sucursal)
    // ══════════════════════════════════════════
    let stockListaCache = [];

    function seleccionarSucursalStock(sucId) {
        const sel = $('#filtro-stock-sucursal');
        if (sel) sel.value = (sucId !== undefined && sucId !== null) ? String(sucId) : '';

        // Actualizar bordes de tarjetas de sucursales
        document.querySelectorAll('.sucursal-admin-card').forEach(card => {
            card.style.borderColor = 'var(--color-border)';
            card.style.boxShadow = 'none';
        });
        if (sucId) {
            const activeCard = $(`#card-suc-${sucId}`);
            if (activeCard) {
                activeCard.style.borderColor = 'var(--color-primary)';
                activeCard.style.boxShadow = '0 0 0 2px var(--color-primary)';
            }
        }

        const titulo = $('#stock-table-titulo');
        if (titulo && sel) {
            const textoSuc = sel.options[sel.selectedIndex]?.text || 'Todas las Sedes';
            titulo.textContent = `Control de Inventario: ${textoSuc}`;
        }

        cargarStockSucursal();

        const tableWrap = document.querySelector('.admin-table-wrap');
        if (tableWrap) {
            tableWrap.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
        }
    }

    async function cargarStockSucursal() {
        const tbody = $('#stock-admin-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--color-text-muted);padding:2rem;">Cargando inventario...</td></tr>';

        const sucId = $('#filtro-stock-sucursal')?.value || '';
        const url = sucId ? `ajax/stock.php?sucursal_id=${sucId}` : 'ajax/stock.php';

        try {
            const res = await fetch(url);
            const data = await res.json();

            if (data.success) {
                stockListaCache = data.data || [];
                filtrarTablaStockLocal();
            } else {
                tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:var(--color-danger);padding:2rem;">${escapeHtml(data.error)}</td></tr>`;
            }
        } catch (err) {
            console.error('Error cargando stock:', err);
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--color-danger);padding:2rem;">Error de conexión al cargar stock</td></tr>';
        }
    }

    function filtrarTablaStockLocal() {
        const query = ($('#filtro-stock-busqueda')?.value || '').toLowerCase().trim();
        if (!query) {
            renderTablaStock(stockListaCache);
            return;
        }
        const filtrados = stockListaCache.filter(item => {
            const nomProd = (item.producto_nombre || '').toLowerCase();
            const nomSuc  = (item.sucursal_nombre || '').toLowerCase();
            const nomCat  = (item.categoria_nombre || '').toLowerCase();
            return nomProd.includes(query) || nomSuc.includes(query) || nomCat.includes(query);
        });
        renderTablaStock(filtrados);
    }

    function renderTablaStock(lista) {
        const tbody = $('#stock-admin-tbody');
        if (!tbody) return;

        if (lista.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--color-text-muted);padding:2rem;">No se encontraron registros de stock</td></tr>';
            return;
        }

        let html = '';
        lista.forEach(s => {
            const cant = parseInt(s.cantidad_disponible, 10) || 0;
            const alerta = parseInt(s.alerta_minima, 10) || 10;
            const precioBase = parseFloat(s.precio_base || 0).toFixed(2);
            const precioEf = parseFloat(s.precio_efectivo || s.precio_base || 0).toFixed(2);
            const precioLocalVal = (s.precio_sucursal !== null && s.precio_sucursal !== undefined && s.precio_sucursal !== '')
                ? parseFloat(s.precio_sucursal).toFixed(2)
                : '';
            const rowKey = `${s.sucursal_id}_${s.producto_id}`;

            let estado = '';
            if (cant === 0) {
                estado = '<span class="badge" style="background:var(--color-danger-bg);color:var(--color-danger);font-weight:700;">AGOTADO</span>';
            } else if (cant <= alerta) {
                estado = '<span class="badge" style="background:rgba(240,160,48,0.15);color:var(--color-primary-ink);font-weight:700;">BAJO</span>';
            } else {
                estado = '<span class="badge" style="background:var(--color-success-bg);color:var(--color-success);font-weight:700;">NORMAL</span>';
            }

            html += `
                <tr id="row-stock-${rowKey}" data-sucursal-id="${s.sucursal_id}" data-producto-id="${s.producto_id}">
                    <td><strong>${escapeHtml(s.sucursal_nombre)}</strong></td>
                    <td>
                        <div style="font-weight: 700; color: var(--color-text);">${escapeHtml(s.producto_nombre)}</div>
                        <small style="color:var(--color-text-muted);">Precio Catálogo: Bs. ${precioBase}</small>
                    </td>
                    <td><span class="badge">${escapeHtml(s.categoria_nombre || 'General')}</span></td>
                    <td style="text-align:center;">
                        <input type="number" min="0" value="${cant}"
                               class="admin-input-stock"
                               data-row-key="${rowKey}"
                               data-sucursal-id="${s.sucursal_id}"
                               data-producto-id="${s.producto_id}"
                               oninput="Admin.marcarFilaModificada('${rowKey}')"
                               style="width: 80px; padding: 0.4rem; text-align: center; font-size: 1rem; font-weight: 700; background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-sm); color: var(--color-text);">
                    </td>
                    <td style="text-align:center;">
                        <input type="number" min="0" value="${alerta}"
                               class="admin-input-alerta"
                               data-row-key="${rowKey}"
                               data-sucursal-id="${s.sucursal_id}"
                               data-producto-id="${s.producto_id}"
                               oninput="Admin.marcarFilaModificada('${rowKey}')"
                               style="width: 70px; padding: 0.4rem; text-align: center; font-size: 0.9rem; background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-sm); color: var(--color-text-muted);">
                    </td>
                    <td style="text-align:center;">
                        <div style="display:flex;align-items:center;justify-content:center;gap:0.3rem;">
                            <span style="color:var(--color-text-muted);font-size:0.8rem;">Bs.</span>
                            <input type="number" step="0.50" min="0"
                                   value="${precioLocalVal}"
                                   placeholder="${precioBase}"
                                   title="Precio específico para esta sede. Déjalo en blanco para usar el precio base (Bs. ${precioBase})"
                                   class="admin-input-precio"
                                   data-row-key="${rowKey}"
                                   data-sucursal-id="${s.sucursal_id}"
                                   data-producto-id="${s.producto_id}"
                                   data-precio-base="${precioBase}"
                                   oninput="Admin.marcarFilaModificada('${rowKey}')"
                                   style="width: 85px; padding: 0.4rem; font-size: 0.95rem; font-weight: 600; text-align: right; background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: var(--radius-sm); color: var(--color-text);">
                            <button type="button" class="btn btn--sm" aria-label="Usar precio base general" onclick="Admin.restablecerPrecioBase('${rowKey}')" title="Usar precio base general (${precioBase})" style="padding: 0.25rem 0.45rem; font-size: 0.75rem; color: var(--color-text-muted);"><svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#refresh"></use></svg></button>
                        </div>
                    </td>
                    <td style="text-align:center;">${estado}</td>
                    <td style="text-align:center;">
                        <button class="btn btn--sm btn--primary" id="btn-save-${rowKey}" onclick="Admin.guardarStockFila('${rowKey}', this)" style="padding: 0.35rem 0.75rem; font-size: 0.8rem; font-weight: 600; width: 100%;">
                            Guardar
                        </button>
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    }

    function marcarFilaModificada(rowKey) {
        const row = $(`#row-stock-${rowKey}`);
        if (row) {
            row.style.background = 'rgba(240, 160, 48, 0.08)';
            row.classList.add('fila-modificada');
        }
        const btnSave = $(`#btn-save-${rowKey}`);
        if (btnSave) {
            btnSave.textContent = 'Guardar *';
            btnSave.classList.add('btn--warning');
        }
    }

    function restablecerPrecioBase(rowKey) {
        const precioInput = document.querySelector(`.admin-input-precio[data-row-key="${rowKey}"]`);
        if (precioInput) {
            precioInput.value = '';
            marcarFilaModificada(rowKey);
        }
    }

    async function guardarStockFila(rowKey, btn) {
        const row = $(`#row-stock-${rowKey}`);
        if (!row) return;

        const sucursalId = parseInt(row.dataset.sucursalId, 10);
        const productoId = parseInt(row.dataset.productoId, 10);
        const cantInput = document.querySelector(`.admin-input-stock[data-row-key="${rowKey}"]`);
        const alertaInput = document.querySelector(`.admin-input-alerta[data-row-key="${rowKey}"]`);
        const precioInput = document.querySelector(`.admin-input-precio[data-row-key="${rowKey}"]`);

        if (!cantInput || !alertaInput || !precioInput) return;

        const cantidad = cantInput.value.trim() !== '' ? parseInt(cantInput.value, 10) : 0;
        const alerta = alertaInput.value.trim() !== '' ? parseInt(alertaInput.value, 10) : 10;
        const precioVal = precioInput.value.trim();
        const precioSucursal = precioVal !== '' ? parseFloat(precioVal) : null;

        if (cantidad < 0) {
            alert('El stock no puede ser negativo');
            return;
        }
        if (precioSucursal !== null && precioSucursal <= 0) {
            alert('El precio local debe ser mayor a 0');
            return;
        }

        const originalText = btn ? btn.textContent : 'Guardar';
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Guardando...';
        }

        try {
            const res = await fetch('ajax/stock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    accion: 'ajuste',
                    sucursal_id: sucursalId,
                    producto_id: productoId,
                    cantidad: cantidad,
                    alerta_minima: alerta,
                    precio_sucursal: precioSucursal,
                    motivo: 'Ajuste manual desde Panel Dirección General por sucursal'
                })
            });

            const data = await res.json();
            if (data.success) {
                if (btn) {
                    btn.textContent = 'Guardado';
                    btn.style.background = 'var(--color-success)';
                    btn.style.color = '#fff';
                }
                row.style.background = 'transparent';
                row.classList.remove('fila-modificada');

                // Actualizar cache local
                const item = stockListaCache.find(x => x.sucursal_id == sucursalId && x.producto_id == productoId);
                if (item) {
                    item.cantidad_disponible = cantidad;
                    item.alerta_minima = alerta;
                    item.precio_sucursal = precioSucursal;
                    item.precio_efectivo = precioSucursal !== null ? precioSucursal : item.precio_base;
                }

                setTimeout(() => {
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = 'Guardar';
                        btn.style.background = '';
                        btn.style.color = '';
                        btn.classList.remove('btn--warning');
                    }
                }, 2000);
            } else {
                alert(data.error || 'Error al guardar stock');
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = originalText;
                }
            }
        } catch (err) {
            console.error('Error guardando stock:', err);
            alert('Error de conexión con el servidor');
            if (btn) {
                btn.disabled = false;
                btn.textContent = originalText;
            }
        }
    }

    async function guardarTodoStock() {
        const filasModificadas = document.querySelectorAll('.fila-modificada');
        if (filasModificadas.length === 0) {
            alert('No hay cambios pendientes de guardar.');
            return;
        }

        const btnGuardarTodo = $('#btn-guardar-todo-stock');
        if (btnGuardarTodo) {
            btnGuardarTodo.disabled = true;
            btnGuardarTodo.innerHTML = svgIcon('save') + ` Guardando (${filasModificadas.length})...`;
        }

        let exito = 0;
        let errores = 0;

        for (const fila of filasModificadas) {
            const sucursalId = parseInt(fila.dataset.sucursalId, 10);
            const productoId = parseInt(fila.dataset.productoId, 10);
            const rowKey = `${sucursalId}_${productoId}`;
            const cantInput = document.querySelector(`.admin-input-stock[data-row-key="${rowKey}"]`);
            const alertaInput = document.querySelector(`.admin-input-alerta[data-row-key="${rowKey}"]`);
            const precioInput = document.querySelector(`.admin-input-precio[data-row-key="${rowKey}"]`);

            if (!cantInput || !alertaInput || !precioInput) continue;

            const cantidad = cantInput.value.trim() !== '' ? parseInt(cantInput.value, 10) : 0;
            const alerta = alertaInput.value.trim() !== '' ? parseInt(alertaInput.value, 10) : 10;
            const precioVal = precioInput.value.trim();
            const precioSucursal = precioVal !== '' ? parseFloat(precioVal) : null;

            try {
                const res = await fetch('ajax/stock.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        accion: 'ajuste',
                        sucursal_id: sucursalId,
                        producto_id: productoId,
                        cantidad: cantidad,
                        alerta_minima: alerta,
                        precio_sucursal: precioSucursal,
                        motivo: 'Ajuste masivo desde Panel Dirección General'
                    })
                });
                const data = await res.json();
                if (data.success) {
                    exito++;
                    fila.style.background = 'transparent';
                    fila.classList.remove('fila-modificada');
                    const btnSave = $(`#btn-save-${rowKey}`);
                    if (btnSave) {
                        btnSave.textContent = 'Guardar';
                        btnSave.classList.remove('btn--warning');
                    }
                } else {
                    errores++;
                }
            } catch (err) {
                errores++;
            }
        }

        if (btnGuardarTodo) {
            btnGuardarTodo.disabled = false;
            btnGuardarTodo.innerHTML = svgIcon('save') + ' Guardar Todo';
        }

        if (errores === 0) {
            alert(`${exito} producto(s) actualizados exitosamente.`);
            cargarStockSucursal();
        } else {
            alert(`Se guardaron ${exito} producto(s). Hubo ${errores} error(es).`);
        }
    }

    // ══════════════════════════════════════════
    // PESTAÑA 3: AUDITORÍA DE VENTAS (Trazabilidad)
    // ══════════════════════════════════════════

    async function cargarAuditoriaVentas() {
        const tbody = $('#auditoria-ventas-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--color-text-muted);padding:2rem;">Cargando historial con trazabilidad...</td></tr>';

        const sucId = $('#filtro-venta-sucursal')?.value || '';
        const desde = $('#filtro-venta-desde')?.value || '';
        const hasta = $('#filtro-venta-hasta')?.value || '';

        const params = new URLSearchParams();
        if (sucId) params.append('sucursal_id', sucId);
        if (desde) params.append('fecha_desde', desde);
        if (hasta) params.append('fecha_hasta', hasta);

        try {
            const res = await fetch(`ajax/historial.php?${params.toString()}`);
            const data = await res.json();

            if (data.success) {
                renderTablaAuditoriaVentas(data.data || []);
            } else {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;color:var(--color-danger);padding:2rem;">${escapeHtml(data.error)}</td></tr>`;
            }
        } catch (err) {
            console.error('Error cargando auditoría de ventas:', err);
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--color-danger);padding:2rem;">Error de conexión</td></tr>';
        }
    }

    function renderTablaAuditoriaVentas(ventas) {
        const tbody = $('#auditoria-ventas-tbody');
        if (!tbody) return;

        if (ventas.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--color-text-muted);padding:2rem;">No se encontraron ventas con los filtros seleccionados</td></tr>';
            return;
        }

        let html = '';
        ventas.forEach(v => {
            let detalleStr = (v.detalle || []).map(d => `${d.cantidad}x ${escapeHtml(d.producto_nombre)} (Bs. ${parseFloat(d.subtotal).toFixed(2)})`).join(', ');
            if (!detalleStr) detalleStr = `${v.items_count} items`;

            let metodoBadge = '';
            if (v.metodo_pago === 'efectivo') {
                metodoBadge = '<span class="badge" style="background:var(--color-success-bg);color:var(--color-success);">Efectivo</span>';
            } else if (v.metodo_pago === 'qr') {
                metodoBadge = '<span class="badge" style="background:rgba(96,165,250,0.15);color:var(--color-info);">QR</span>';
            } else {
                metodoBadge = '<span class="badge" style="background:rgba(240,160,48,0.15);color:var(--color-primary-ink);">Tarjeta</span>';
            }

            html += `
                <tr>
                    <td><strong>#${v.id}</strong></td>
                    <td style="font-size:0.85rem;color:var(--color-text-muted);">${escapeHtml(v.fecha)}</td>
                    <td><strong>${escapeHtml(v.sucursal_nombre)}</strong></td>
                    <td>${escapeHtml(v.cajero_nombre)}</td>
                    <td style="font-size:0.85rem;max-width:320px;">${detalleStr}</td>
                    <td>${metodoBadge}</td>
                    <td style="text-align:right;font-size:1.05rem;font-weight:700;color:var(--color-primary-ink);">
                        Bs. ${v.total}
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    }

    // ══════════════════════════════════════════
    // PESTAÑA 4: AUDITORÍA DE TURNOS (CORTES Z)
    // ══════════════════════════════════════════

    async function cargarAuditoriaTurnos() {
        const tbody = $('#auditoria-turnos-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--color-text-muted);padding:2rem;">Cargando turnos y Cortes Z...</td></tr>';

        const sucId = $('#filtro-turno-sucursal')?.value || '';
        const estado = $('#filtro-turno-estado')?.value || '';

        const params = new URLSearchParams({ auditoria: '1' });
        if (sucId) params.append('sucursal_id', sucId);
        if (estado) params.append('estado', estado);

        try {
            const res = await fetch(`ajax/turno_caja.php?${params.toString()}`);
            const data = await res.json();

            if (data.success) {
                renderTablaAuditoriaTurnos(data.turnos || []);
            } else {
                tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;color:var(--color-danger);padding:2rem;">${escapeHtml(data.error)}</td></tr>`;
            }
        } catch (err) {
            console.error('Error cargando turnos:', err);
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--color-danger);padding:2rem;">Error de conexión</td></tr>';
        }
    }

    function renderTablaAuditoriaTurnos(turnos) {
        const tbody = $('#auditoria-turnos-tbody');
        if (!tbody) return;

        if (turnos.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--color-text-muted);padding:2rem;">No hay turnos registrados</td></tr>';
            return;
        }

        let html = '';
        turnos.forEach(t => {
            const esCerrado = t.estado === 'cerrado';
            const dif = t.diferencia !== null ? parseFloat(t.diferencia) : null;

            let cuadreBadge = '';
            if (!esCerrado) {
                cuadreBadge = '<span class="badge" style="background:rgba(96,165,250,0.15);color:var(--color-info);">Turno Abierto</span>';
            } else if (dif === 0) {
                cuadreBadge = '<span class="badge" style="background:var(--color-success-bg);color:var(--color-success);">Cuadrado (0.00)</span>';
            } else if (dif > 0) {
                cuadreBadge = `<span class="badge" style="background:rgba(52,211,153,0.15);color:var(--color-success);">Sobrante (+${dif.toFixed(2)})</span>`;
            } else {
                cuadreBadge = `<span class="badge" style="background:var(--color-danger-bg);color:var(--color-danger);">Faltante (${dif.toFixed(2)})</span>`;
            }

            html += `
                <tr>
                    <td><strong>#${t.id}</strong></td>
                    <td>
                        <strong>${escapeHtml(t.sucursal_nombre)}</strong><br>
                        <small style="color:var(--color-text-muted);">${escapeHtml(t.cajero_nombre)}</small>
                    </td>
                    <td style="font-size:0.8rem;color:var(--color-text-muted);">
                        Apertura: ${escapeHtml(t.hora_apertura)}<br>
                        Cierre: ${t.hora_cierre ? escapeHtml(t.hora_cierre) : '<em style="color:var(--color-info);">En curso</em>'}
                    </td>
                    <td style="text-align:right;">Bs. ${parseFloat(t.monto_apertura).toFixed(2)}</td>
                    <td style="text-align:right;color:var(--color-primary-ink);font-weight:600;">
                        ${t.ventas_sistema !== null ? 'Bs. ' + parseFloat(t.ventas_sistema).toFixed(2) : '—'}
                    </td>
                    <td style="text-align:right;color:var(--color-danger);">
                        ${t.egresos_menores > 0 ? '- Bs. ' + parseFloat(t.egresos_menores).toFixed(2) : 'Bs. 0.00'}
                    </td>
                    <td style="text-align:right;font-weight:700;">
                        ${t.monto_cierre !== null ? 'Bs. ' + parseFloat(t.monto_cierre).toFixed(2) : '—'}
                    </td>
                    <td style="text-align:center;">${cuadreBadge}</td>
                    <td style="font-size:0.8rem;color:var(--color-text-muted);max-width:180px;">
                        ${escapeHtml(t.notas || '—')}
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    }

    // ══════════════════════════════════════════
    // AUTO-SYNC (BUG-E): Polling cada 30s para detectar cambios
    // ══════════════════════════════════════════

    /**
     * Llama a admin_sync.php cada 30 segundos.
     * Si detecta un timestamp más nuevo que el último conocido,
     * refresca automáticamente la pestaña activa.
     */
    function startAutoSync() {
        setInterval(async () => {
            try {
                const res = await fetch('ajax/admin_sync.php?action=validate');
                if (!res.ok) return;
                const data = await res.json();
                if (data.status !== 'ok') return;

                const serverTs = data.timestamp || 0;
                if (serverTs > lastSyncTimestamp) {
                    lastSyncTimestamp = serverTs;

                    // Refrescar solo la pestaña visible para no sobrecargar
                    if (tabActual === 'auditoria-ventas') {
                        cargarAuditoriaVentas();
                    } else if (tabActual === 'stock') {
                        cargarStockSucursal();
                    }
                    // Actualizar también el indicador de tiempo si existe
                    const badge = $('#sync-badge');
                    if (badge) {
                        const hora = new Date().toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit' });
                        badge.textContent = `Actualizado ${hora}`;
                    }
                }
            } catch (err) {
                // Sin conexión — fallo silencioso
            }
        }, 30000);
    }


    document.addEventListener('DOMContentLoaded', init);

    return {
        cambiarTab,
        cargarProductos,
        guardarProducto,
        editarProducto,
        limpiarFormulario,
        desactivarProducto,
        activarProducto,
        cargarStockSucursal,
        seleccionarSucursalStock,
        filtrarTablaStockLocal,
        marcarFilaModificada,
        restablecerPrecioBase,
        guardarStockFila,
        guardarTodoStock,
        cargarAuditoriaVentas,
        cargarAuditoriaTurnos,
        startAutoSync
    };
})();

/**
 * ══════════════════════════════════════════════════════════════════════════
 * MÓDULO DE GESTIÓN DE USUARIOS Y ASIGNACIÓN DE SUCURSALES (Fase 2.5)
 * ══════════════════════════════════════════════════════════════════════════
 */
const AdminUsuarios = (() => {
    let usuariosLista = [];
    let sucursalesLista = [];
    let usuarioSeleccionadoModal = null;
    let editandoId = null;

    const $ = (sel) => document.querySelector(sel);
    const escapeHtml = (str) => {
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };
    const svgIcon = (name) => `<svg class="icon" aria-hidden="true" focusable="false"><use href="assets/img/icons.svg#${name}"></use></svg>`;

    async function cargar() {
        const tbody = $('#usr-admin-tbody');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="7" class="admin-empty-row">Cargando usuarios...</td></tr>';
        }

        try {
            const res = await fetch('ajax/usuarios.php?accion=listar');
            const data = await res.json();

            if (data.success) {
                usuariosLista = data.usuarios || [];
                sucursalesLista = data.sucursales || [];
                actualizarKPIs(data.kpis || {});
                poblarSelectorSucursales(sucursalesLista);
                filtrar(); // Respeta los filtros activos tras recargar
            } else {
                mostrarNotificacion(data.error || 'Error al cargar usuarios', 'error');
                if (tbody) tbody.innerHTML = `<tr><td colspan="7" class="admin-empty-row" style="color:var(--color-danger);">${escapeHtml(data.error || 'Error')}</td></tr>`;
            }
        } catch (err) {
            console.error('Error cargando usuarios:', err);
            mostrarNotificacion('Error de conexión al cargar usuarios', 'error');
            if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="admin-empty-row" style="color:var(--color-danger);">Error de conexión</td></tr>';
        }
    }

    function actualizarKPIs(kpis) {
        if ($('#kpi-usr-total')) $('#kpi-usr-total').textContent = kpis.total || 0;
        if ($('#kpi-usr-activos')) $('#kpi-usr-activos').textContent = kpis.activos || 0;
        if ($('#kpi-usr-encargados')) $('#kpi-usr-encargados').textContent = kpis.encargados || 0;
        if ($('#kpi-usr-cajeros')) $('#kpi-usr-cajeros').textContent = kpis.cajeros || 0;
    }

    function poblarSelectorSucursales(sucursales) {
        const sel = $('#usr-sucursal-principal');
        if (!sel) return;
        const valorActual = sel.value;
        // Solo el admin puede tener una sucursal interna (SUC-000) como principal
        const permiteInternas = $('#usr-rol')?.value === 'admin';
        let html = '<option value="">Selecciona una sucursal...</option>';
        sucursales.forEach(s => {
            if (s.es_interna == 1 && !permiteInternas) return;
            const extra = s.es_interna == 1 ? ' (Interna Admin)' : '';
            html += `<option value="${escapeHtml(s.id)}">${escapeHtml(s.codigo)} — ${escapeHtml(s.nombre)}${extra}</option>`;
        });
        sel.innerHTML = html;
        // Conservar la selección solo si sigue siendo válida para el rol elegido
        sel.value = valorActual;
        if (sel.selectedIndex === -1) sel.value = '';
    }

    function renderTabla(lista) {
        const tbody = $('#usr-admin-tbody');
        const contador = $('#usr-contador');
        if (!tbody) return;

        if (contador) contador.textContent = `${lista.length} usuarios registrados`;

        if (lista.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="admin-empty-row">No se encontraron usuarios</td></tr>';
            return;
        }

        let html = '';
        lista.forEach(u => {
            const esAdmin = u.rol === 'admin';
            const esEncargado = u.rol === 'encargado';
            const estaActivo = u.activo === 1;

            // Badge de rol
            let rolBadge = '';
            if (esAdmin) {
                rolBadge = '<span style="background:rgba(147, 51, 234, 0.15);color:#7e22ce;padding:0.25rem 0.65rem;border-radius:var(--radius-full);font-size:0.75rem;font-weight:700;">Administrador</span>';
            } else if (esEncargado) {
                rolBadge = '<span style="background:rgba(224, 122, 20, 0.15);color:#9c4a00;padding:0.25rem 0.65rem;border-radius:var(--radius-full);font-size:0.75rem;font-weight:700;">Encargado</span>';
            } else {
                rolBadge = '<span style="background:var(--color-surface-2);color:var(--color-text);padding:0.25rem 0.65rem;border-radius:var(--radius-full);font-size:0.75rem;font-weight:600;">Cajero (Caja)</span>';
            }

            // Badge de estado
            const estadoBadge = estaActivo
                ? '<span style="background:var(--color-success-bg);color:var(--color-success);padding:0.2rem 0.55rem;border-radius:var(--radius-full);font-size:0.75rem;font-weight:700;">Activo</span>'
                : '<span style="background:var(--color-danger-bg);color:var(--color-danger);padding:0.2rem 0.55rem;border-radius:var(--radius-full);font-size:0.75rem;font-weight:700;">Inactivo</span>';

            // Sucursales a cargo
            let aCargoHtml = '';
            if (esEncargado) {
                const total = (u.sucursales_asignadas || []).length;
                if (total > 0) {
                    aCargoHtml = `
                        <div style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;">
                            <span style="font-size:0.8rem;font-weight:700;color:var(--color-primary-ink);">${total} sucursales</span>
                            <button type="button" class="btn btn--sm" onclick="AdminUsuarios.abrirModalSucursales(${u.id})" style="padding:0.2rem 0.5rem;font-size:0.72rem;">
                                ${svgIcon('store')} Asignar (${total})
                            </button>
                        </div>
                    `;
                } else {
                    aCargoHtml = `
                        <button type="button" class="btn btn--sm" onclick="AdminUsuarios.abrirModalSucursales(${u.id})" style="padding:0.2rem 0.5rem;font-size:0.72rem;color:var(--color-danger);border-color:var(--color-danger);">
                            ${svgIcon('plus')} Asignar sucursales
                        </button>
                    `;
                }
            } else if (esAdmin) {
                aCargoHtml = '<span style="font-size:0.8rem;color:var(--color-text-dim);">Acceso Global (15 sedes)</span>';
            } else {
                aCargoHtml = '<span style="font-size:0.8rem;color:var(--color-text-dim);">Solo sucursal fija</span>';
            }

            const ultimoLogin = u.ultimo_login ? u.ultimo_login.substring(0, 16) : 'Nunca';

            html += `
                <tr style="border-bottom: 1px solid var(--color-border); ${!estaActivo ? 'opacity:0.6;' : ''}">
                    <td style="padding: 0.8rem 1rem;">
                        <div style="display:flex;align-items:center;gap:0.6rem;">
                            <div style="width:34px;height:34px;border-radius:50%;background:var(--color-surface-2);display:flex;align-items:center;justify-content:center;color:var(--color-primary-ink);">
                                ${svgIcon('user')}
                            </div>
                            <div>
                                <div style="font-weight:700;color:var(--color-text);">${escapeHtml(u.nombre_display)}</div>
                                <div style="font-size:0.75rem;color:var(--color-text-dim);">@${escapeHtml(u.username)} • ID #${u.id}</div>
                            </div>
                        </div>
                    </td>
                    <td style="padding: 0.8rem 1rem;">${rolBadge}</td>
                    <td style="padding: 0.8rem 1rem; font-size:0.88rem;">${escapeHtml(u.sucursal_nombre)}</td>
                    <td style="padding: 0.8rem 1rem;">${aCargoHtml}</td>
                    <td style="padding: 0.8rem 1rem; text-align:center;">${estadoBadge}</td>
                    <td style="padding: 0.8rem 1rem; font-size:0.8rem; color:var(--color-text-dim);">${ultimoLogin}</td>
                    <td style="padding: 0.8rem 1rem; text-align:center;">
                        <div style="display:flex;gap:0.35rem;justify-content:center;">
                            <button type="button" class="btn" style="padding:0.35rem 0.55rem;font-size:0.75rem;" title="Editar datos" onclick="AdminUsuarios.editar(${u.id})">
                                ${svgIcon('pencil')}
                            </button>
                            <button type="button" class="btn" style="padding:0.35rem 0.55rem;font-size:0.75rem;" title="Restablecer contraseña" onclick="AdminUsuarios.abrirModalPassword(${u.id})">
                                ${svgIcon('refresh')}
                            </button>
                            <button type="button" class="btn" style="padding:0.35rem 0.55rem;font-size:0.75rem;color:${estaActivo ? 'var(--color-danger)' : 'var(--color-success)'};" title="${estaActivo ? 'Desactivar usuario' : 'Activar usuario'}" onclick="AdminUsuarios.toggleActivo(${u.id})">
                                ${svgIcon('power')}
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    }

    function filtrar() {
        const rolFiltro = ($('#filtro-usr-rol')?.value || '').trim();
        const busq = ($('#filtro-usr-busqueda')?.value || '').toLowerCase().trim();

        let filtrados = usuariosLista;
        if (rolFiltro) {
            filtrados = filtrados.filter(u => u.rol === rolFiltro);
        }
        if (busq) {
            filtrados = filtrados.filter(u => {
                const nom = (u.nombre_display || '').toLowerCase();
                const usr = (u.username || '').toLowerCase();
                const suc = (u.sucursal_nombre || '').toLowerCase();
                return nom.includes(busq) || usr.includes(busq) || suc.includes(busq);
            });
        }
        renderTabla(filtrados);
    }

    function alCambiarRol() {
        const rol = $('#usr-rol')?.value;
        const wrapSuc = $('#wrap-usr-sucursal');
        const labelSuc = wrapSuc ? wrapSuc.querySelector('label') : null;
        const selSuc = $('#usr-sucursal-principal');

        // Repoblar según el rol (las internas solo para admin) y exigir sucursal si no es admin
        if (sucursalesLista.length) poblarSelectorSucursales(sucursalesLista);
        if (selSuc) selSuc.required = rol !== 'admin';

        if (rol === 'admin') {
            if (labelSuc) labelSuc.textContent = 'Sucursal (Opcional para Admin)';
        } else if (rol === 'encargado') {
            if (labelSuc) labelSuc.textContent = 'Sucursal base principal *';
        } else {
            if (labelSuc) labelSuc.textContent = 'Sucursal fija de caja *';
        }
    }

    function abrirFormularioNuevo() {
        limpiarFormulario();
        $('#usr-form-title').textContent = 'Nuevo Usuario';
        $('#wrap-usr-password').style.display = 'block';
        $('#usr-password').required = true;
        $('#usr-username').disabled = false;
        $('#usr-username').focus();
        window.scrollTo({ top: $('#usr-form-container').offsetTop - 80, behavior: 'smooth' });
    }

    function editar(id) {
        const u = usuariosLista.find(item => item.id == id);
        if (!u) return;

        editandoId = u.id;
        $('#usr-id').value = u.id;
        $('#usr-username').value = u.username;
        $('#usr-username').disabled = true;
        $('#usr-nombre').value = u.nombre_display;
        $('#usr-rol').value = u.rol;
        alCambiarRol(); // Repuebla el selector según el rol antes de fijar la sucursal
        $('#usr-sucursal-principal').value = u.sucursal_id || '';
        if ($('#usr-sucursal-principal').selectedIndex === -1) $('#usr-sucursal-principal').value = '';
        $('#usr-activo').value = u.activo;

        $('#wrap-usr-password').style.display = 'none';
        $('#usr-password').required = false;

        $('#usr-form-title').textContent = `Editar Usuario: ${u.username}`;
        $('#usr-form-submit').innerHTML = `${svgIcon('save')} Guardar cambios`;
        $('#usr-form-cancel').style.display = 'inline-flex';

        window.scrollTo({ top: $('#usr-form-container').offsetTop - 80, behavior: 'smooth' });
    }

    function limpiarFormulario() {
        editandoId = null;
        $('#usr-id').value = '';
        $('#usr-username').value = '';
        $('#usr-username').disabled = false;
        $('#usr-password').value = '';
        $('#wrap-usr-password').style.display = 'block';
        $('#usr-password').required = true;
        $('#usr-nombre').value = '';
        $('#usr-rol').value = 'caja';
        $('#usr-sucursal-principal').value = '';
        $('#usr-activo').value = '1';

        $('#usr-form-title').textContent = 'Nuevo Usuario';
        $('#usr-form-submit').innerHTML = `${svgIcon('save')} Guardar usuario`;
        $('#usr-form-cancel').style.display = 'none';
        alCambiarRol();
    }

    async function guardar(e) {
        if (e) e.preventDefault();

        const id = $('#usr-id').value;
        const username = $('#usr-username').value.trim();
        const password = $('#usr-password').value;
        const nombre = $('#usr-nombre').value.trim();
        const rol = $('#usr-rol').value;
        const sucursalId = $('#usr-sucursal-principal').value;
        const activo = parseInt($('#usr-activo').value, 10);

        // Encargado y caja necesitan una sucursal principal (el backend también lo exige)
        if (rol !== 'admin' && !sucursalId) {
            mostrarNotificacion('Debes asignar una sucursal principal para este rol', 'error');
            $('#usr-sucursal-principal').focus();
            return;
        }

        const btnSubmit = $('#usr-form-submit');
        if (btnSubmit) btnSubmit.disabled = true;

        try {
            let res, data;
            if (id) {
                res = await fetch('ajax/usuarios.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        accion: 'editar',
                        id: parseInt(id, 10),
                        nombre_display: nombre,
                        rol: rol,
                        sucursal_id: sucursalId ? parseInt(sucursalId, 10) : null,
                        activo: activo
                    })
                });
            } else {
                res = await fetch('ajax/usuarios.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        accion: 'crear',
                        username: username,
                        password: password,
                        nombre_display: nombre,
                        rol: rol,
                        sucursal_id: sucursalId ? parseInt(sucursalId, 10) : null,
                        activo: activo
                    })
                });
            }

            data = await res.json();
            if (data.success) {
                mostrarNotificacion(data.message || 'Usuario guardado', 'success');
                limpiarFormulario();
                cargar();
            } else {
                mostrarNotificacion(data.error || 'Error al guardar usuario', 'error');
            }
        } catch (err) {
            console.error('Error guardando usuario:', err);
            mostrarNotificacion('Error de conexión al guardar usuario', 'error');
        } finally {
            if (btnSubmit) btnSubmit.disabled = false;
        }
    }

    async function toggleActivo(id) {
        const u = usuariosLista.find(item => item.id == id);
        if (!u) return;
        const accionTexto = u.activo === 1 ? 'desactivar' : 'activar';
        if (!confirm(`¿Estás seguro de que deseas ${accionTexto} al usuario ${u.nombre_display} (@${u.username})?`)) {
            return;
        }

        try {
            const res = await fetch('ajax/usuarios.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    accion: 'toggle_activo',
                    usuario_id: id
                })
            });
            const data = await res.json();
            if (data.success) {
                mostrarNotificacion(data.message, 'success');
                cargar();
            } else {
                mostrarNotificacion(data.error || 'Error al cambiar estado', 'error');
            }
        } catch (err) {
            mostrarNotificacion('Error de conexión', 'error');
        }
    }

    // ── MODAL ASIGNAR SUCURSALES (N:M) ────────────────────────────────
    function abrirModalSucursales(usuarioId) {
        const u = usuariosLista.find(item => item.id == usuarioId);
        if (!u) return;

        usuarioSeleccionadoModal = u;
        $('#asig-usuario-id').value = u.id;
        $('#modal-asig-titulo').textContent = `Asignar Sucursales: ${u.nombre_display}`;
        $('#modal-asig-subtitulo').textContent = `Encargado @${u.username} • Sucursal base: ${u.sucursal_nombre}`;

        const grid = $('#asig-sucursales-grid');
        if (!grid) return;

        const asignadasSet = new Set(u.sucursales_asignadas_ids || []);
        if (u.sucursal_id) asignadasSet.add(u.sucursal_id);

        let html = '';
        sucursalesLista.forEach(s => {
            if (s.es_interna == 1) return;
            const esPrincipal = (parseInt(s.id, 10) === parseInt(u.sucursal_id, 10));
            // La principal siempre conserva acceso (el backend la incluye igualmente): fija y no editable aquí
            const checked = (esPrincipal || asignadasSet.has(parseInt(s.id, 10))) ? 'checked' : '';
            const disabled = esPrincipal ? 'disabled' : '';

            html += `
                <label style="display:flex;align-items:flex-start;gap:0.6rem;background:var(--color-surface-2);border:1px solid var(--color-border);border-radius:var(--radius-md);padding:0.75rem 0.9rem;cursor:${esPrincipal ? 'default' : 'pointer'};user-select:none;">
                    <input type="checkbox" value="${escapeHtml(s.id)}" class="chk-sucursal-asig" ${checked} ${disabled} onchange="AdminUsuarios.actualizarContadorModalSucursales()" style="margin-top:0.25rem;transform:scale(1.15);">
                    <div style="flex:1;">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <strong style="font-size:0.88rem;color:var(--color-text);">${escapeHtml(s.nombre)}</strong>
                            <span style="font-size:0.72rem;font-weight:700;color:var(--color-primary-ink);">${escapeHtml(s.codigo)}</span>
                        </div>
                        <small style="display:block;color:var(--color-text-dim);font-size:0.75rem;">${escapeHtml(s.direccion)}</small>
                        ${esPrincipal ? '<span style="display:inline-block;margin-top:0.3rem;font-size:0.68rem;background:rgba(224,122,20,0.15);color:#9c4a00;padding:0.1rem 0.4rem;border-radius:var(--radius-full);font-weight:700;">Principal: se cambia desde Editar</span>' : ''}
                    </div>
                </label>
            `;
        });

        grid.innerHTML = html;
        actualizarContadorModalSucursales();
        abrirModalAccesible($('#modal-asignar-sucursales'));
    }

    function cerrarModalSucursales() {
        cerrarModalAccesible($('#modal-asignar-sucursales'));
        usuarioSeleccionadoModal = null;
    }

    function seleccionarTodasSucursales(check) {
        // No tocar la sucursal principal (deshabilitada): siempre queda marcada
        document.querySelectorAll('.chk-sucursal-asig:not(:disabled)').forEach(chk => chk.checked = check);
        actualizarContadorModalSucursales();
    }

    function actualizarContadorModalSucursales() {
        const checks = document.querySelectorAll('.chk-sucursal-asig:checked');
        const label = $('#asig-seleccionadas-label');
        if (label) {
            label.textContent = `${checks.length} sucursales seleccionadas`;
        }
    }

    async function guardarAsignacionSucursales() {
        const uId = parseInt($('#asig-usuario-id').value, 10);
        if (!uId) return;

        const seleccionadas = [];
        document.querySelectorAll('.chk-sucursal-asig:checked').forEach(chk => {
            seleccionadas.push(parseInt(chk.value, 10));
        });

        try {
            const res = await fetch('ajax/usuarios.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    accion: 'asignar_sucursales',
                    usuario_id: uId,
                    sucursales_ids: seleccionadas
                })
            });
            const data = await res.json();
            if (data.success) {
                mostrarNotificacion(data.message, 'success');
                cerrarModalSucursales();
                cargar();
            } else {
                mostrarNotificacion(data.error || 'Error al guardar asignaciones', 'error');
            }
        } catch (err) {
            mostrarNotificacion('Error de conexión', 'error');
        }
    }

    // ── MODAL RESET PASSWORD ──────────────────────────────────────────
    function abrirModalPassword(usuarioId) {
        const u = usuariosLista.find(item => item.id == usuarioId);
        if (!u) return;

        $('#reset-usuario-id').value = u.id;
        $('#modal-reset-titulo').textContent = `Restablecer Contraseña`;
        $('#modal-reset-usuario-sub').textContent = `Usuario: ${u.nombre_display} (@${u.username})`;
        // Sin clave por defecto: se precarga una aleatoria que el admin puede cambiar
        generarPasswordAleatoria();
        abrirModalAccesible($('#modal-reset-password'));
    }

    function cerrarModalPassword() {
        cerrarModalAccesible($('#modal-reset-password'));
    }

    function generarPasswordAleatoria() {
        const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#$';
        // RNG criptográfico (Math.random es predecible para contraseñas)
        const rnd = new Uint32Array(10);
        crypto.getRandomValues(rnd);
        let pass = '';
        for (let i = 0; i < rnd.length; i++) {
            pass += chars.charAt(rnd[i] % chars.length);
        }
        $('#reset-nueva-password').value = pass;
    }

    // ── Accesibilidad de modales: foco inicial, Escape y foco de retorno ──
    let focoPrevioModal = null;

    function abrirModalAccesible(modal) {
        if (!modal) return;
        focoPrevioModal = document.activeElement;
        modal.classList.add('active');
        // Igual que app.js: esperar a que el overlay sea visible antes de enfocar
        setTimeout(() => {
            if (!modal.classList.contains('active')) return;
            const zona = modal.querySelector('.modal__body') || modal;
            const primero = zona.querySelector('input:not([type="hidden"]):not(:disabled), select:not(:disabled), textarea:not(:disabled), button:not(:disabled)');
            if (primero) primero.focus();
        }, 100);
    }

    function cerrarModalAccesible(modal) {
        if (!modal || !modal.classList.contains('active')) return;
        modal.classList.remove('active');
        const previo = focoPrevioModal;
        focoPrevioModal = null;
        if (previo && previo !== document.body && document.body.contains(previo) && typeof previo.focus === 'function') {
            previo.focus();
        }
    }

    // Escape cierra el modal de usuarios que esté abierto
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if ($('#modal-reset-password')?.classList.contains('active')) {
            cerrarModalPassword();
        } else if ($('#modal-asignar-sucursales')?.classList.contains('active')) {
            cerrarModalSucursales();
        }
    });

    async function confirmarResetPassword() {
        const uId = parseInt($('#reset-usuario-id').value, 10);
        const pass = $('#reset-nueva-password').value.trim();

        if (!uId || !pass) {
            mostrarNotificacion('Ingresa una contraseña válida', 'error');
            return;
        }

        try {
            const res = await fetch('ajax/usuarios.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    accion: 'reset_password',
                    usuario_id: uId,
                    password: pass
                })
            });
            const data = await res.json();
            if (data.success) {
                mostrarNotificacion(data.message, 'success');
                cerrarModalPassword();
            } else {
                mostrarNotificacion(data.error || 'Error al restablecer contraseña', 'error');
            }
        } catch (err) {
            mostrarNotificacion('Error de conexión', 'error');
        }
    }

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
        toast.style.background = tipo === 'success' ? 'var(--color-success)' : (tipo === 'error' ? 'var(--color-danger)' : 'var(--color-primary)');
        toast.style.color = tipo === 'info' ? '#000' : '#fff';
        toast.textContent = mensaje;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3500);
    }

    return {
        cargar,
        filtrar,
        alCambiarRol,
        abrirFormularioNuevo,
        editar,
        limpiarFormulario,
        guardar,
        toggleActivo,
        abrirModalSucursales,
        cerrarModalSucursales,
        seleccionarTodasSucursales,
        actualizarContadorModalSucursales,
        guardarAsignacionSucursales,
        abrirModalPassword,
        cerrarModalPassword,
        generarPasswordAleatoria,
        confirmarResetPassword
    };
})();
