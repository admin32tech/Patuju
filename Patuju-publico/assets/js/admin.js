/**
 * PATUJU POS — Módulo de Administración y Auditoría Multi-Sucursal (Fase 2)
 */

const Admin = (() => {
    let editandoId = null;
    let tabActual = 'productos';
    let lastSyncTimestamp = 0; // BUG-E: usado para detectar cambios y auto-refrescar

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

    function init() {
        cargarProductos();
        const form = $('#admin-form');
        if (form) {
            form.addEventListener('submit', guardarProducto);
        }
        startAutoSync(); // BUG-E FIX: inicia el polling de sincronización
    }

    // ── Cambio de Pestañas ─────────────────────
    function cambiarTab(tab) {
        tabActual = tab;

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
            sel.innerHTML += `<option value="${c.id}">${c.nombre}</option>`;
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
                            <span class="admin-prod-icon">🥟</span>
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
                            <button class="btn btn--sm" onclick="Admin.editarProducto(${p.id}, '${escapeHtml(p.nombre)}', ${p.precio}, ${p.categoria_id}, '${escapeHtml(p.imagen || '')}', '${escapeHtml(p.descripcion || '')}')" title="Editar">✏️</button>
                            ${activo 
                                ? `<button class="btn btn--sm btn--danger" onclick="Admin.desactivarProducto(${p.id}, '${escapeHtml(p.nombre)}')" title="Desactivar">🗑️</button>`
                                : `<button class="btn btn--sm" onclick="Admin.activarProducto(${p.id}, '${escapeHtml(p.nombre)}')" title="Reactivar">♻️</button>`
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
        btnSubmit.textContent = 'Guardando...';

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
            btnSubmit.textContent = editandoId ? '💾 Actualizar' : '💾 Guardar';
        }
    }

    function editarProducto(id, nombre, precio, categoriaId, imagen, descripcion) {
        editandoId = id;
        $('#admin-form-title').textContent = `✏️ Editar Producto #${id}`;
        $('#prod-nombre').value = nombre;
        $('#prod-precio').value = precio;
        $('#prod-categoria').value = categoriaId;
        $('#prod-imagen').value = imagen;
        $('#prod-descripcion').value = descripcion;
        $('#admin-form-submit').textContent = '💾 Actualizar';
        $('#admin-form-cancel').style.display = 'inline-flex';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function limpiarFormulario() {
        editandoId = null;
        $('#admin-form').reset();
        $('#admin-form-title').textContent = '➕ Nuevo Producto';
        $('#admin-form-submit').textContent = '💾 Guardar';
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
            titulo.textContent = `🏬 Control de Inventario: ${textoSuc}`;
        }

        cargarStockSucursal();

        const tableWrap = document.querySelector('.admin-table-wrap');
        if (tableWrap) {
            tableWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
                estado = '<span class="badge" style="background:var(--color-danger-bg);color:var(--color-danger);font-weight:700;">⛔ AGOTADO</span>';
            } else if (cant <= alerta) {
                estado = '<span class="badge" style="background:rgba(240,160,48,0.15);color:var(--color-primary);font-weight:700;">⚠️ BAJO</span>';
            } else {
                estado = '<span class="badge" style="background:var(--color-success-bg);color:var(--color-success);font-weight:700;">✅ NORMAL</span>';
            }

            html += `
                <tr id="row-stock-${rowKey}" data-sucursal-id="${s.sucursal_id}" data-producto-id="${s.producto_id}">
                    <td><strong>📍 ${escapeHtml(s.sucursal_nombre)}</strong></td>
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
                            <button type="button" class="btn btn--sm" onclick="Admin.restablecerPrecioBase('${rowKey}')" title="Usar precio base general (${precioBase})" style="padding: 0.25rem 0.45rem; font-size: 0.75rem; color: var(--color-text-muted);">↺</button>
                        </div>
                    </td>
                    <td style="text-align:center;">${estado}</td>
                    <td style="text-align:center;">
                        <button class="btn btn--sm btn--primary" id="btn-save-${rowKey}" onclick="Admin.guardarStockFila('${rowKey}', this)" style="padding: 0.35rem 0.75rem; font-size: 0.8rem; font-weight: 600; width: 100%;">
                            💾 Guardar
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
            btnSave.textContent = '💾 Guardar *';
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

        const originalText = btn ? btn.textContent : '💾 Guardar';
        if (btn) {
            btn.disabled = true;
            btn.textContent = '⏳ Guardando...';
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
                    btn.textContent = '✅ Guardado';
                    btn.style.background = 'var(--color-success)';
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
                        btn.textContent = '💾 Guardar';
                        btn.style.background = '';
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
            btnGuardarTodo.textContent = `⏳ Guardando (${filasModificadas.length})...`;
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
                        btnSave.textContent = '💾 Guardar';
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
            btnGuardarTodo.textContent = '💾 Guardar Todo';
        }

        if (errores === 0) {
            alert(`✅ ${exito} producto(s) actualizados exitosamente.`);
            cargarStockSucursal();
        } else {
            alert(`⚠️ Se guardaron ${exito} producto(s). Hubo ${errores} error(es).`);
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
                metodoBadge = '<span class="badge" style="background:var(--color-success-bg);color:var(--color-success);">💵 Efectivo</span>';
            } else if (v.metodo_pago === 'qr') {
                metodoBadge = '<span class="badge" style="background:rgba(96,165,250,0.15);color:var(--color-info);">📱 QR</span>';
            } else {
                metodoBadge = '<span class="badge" style="background:rgba(240,160,48,0.15);color:var(--color-primary);">💳 Tarjeta</span>';
            }

            html += `
                <tr>
                    <td><strong>#${v.id}</strong></td>
                    <td style="font-size:0.85rem;color:var(--color-text-muted);">${escapeHtml(v.fecha)}</td>
                    <td><strong>📍 ${escapeHtml(v.sucursal_nombre)}</strong></td>
                    <td>👤 ${escapeHtml(v.cajero_nombre)}</td>
                    <td style="font-size:0.85rem;max-width:320px;">${detalleStr}</td>
                    <td>${metodoBadge}</td>
                    <td style="text-align:right;font-size:1.05rem;font-weight:700;color:var(--color-primary);">
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
                cuadreBadge = '<span class="badge" style="background:rgba(96,165,250,0.15);color:var(--color-info);">🔵 Turno Abierto</span>';
            } else if (dif === 0) {
                cuadreBadge = '<span class="badge" style="background:var(--color-success-bg);color:var(--color-success);">✅ Cuadrado (0.00)</span>';
            } else if (dif > 0) {
                cuadreBadge = `<span class="badge" style="background:rgba(52,211,153,0.15);color:var(--color-success);">➕ Sobrante (+${dif.toFixed(2)})</span>`;
            } else {
                cuadreBadge = `<span class="badge" style="background:var(--color-danger-bg);color:var(--color-danger);">⚠️ Faltante (${dif.toFixed(2)})</span>`;
            }

            html += `
                <tr>
                    <td><strong>#${t.id}</strong></td>
                    <td>
                        <strong>📍 ${escapeHtml(t.sucursal_nombre)}</strong><br>
                        <small style="color:var(--color-text-muted);">👤 ${escapeHtml(t.cajero_nombre)}</small>
                    </td>
                    <td style="font-size:0.8rem;color:var(--color-text-muted);">
                        Apertura: ${escapeHtml(t.hora_apertura)}<br>
                        Cierre: ${t.hora_cierre ? escapeHtml(t.hora_cierre) : '<em style="color:var(--color-info);">En curso</em>'}
                    </td>
                    <td style="text-align:right;">Bs. ${parseFloat(t.monto_apertura).toFixed(2)}</td>
                    <td style="text-align:right;color:var(--color-primary);font-weight:600;">
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
                        badge.textContent = `🟢 Actualizado ${hora}`;
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
