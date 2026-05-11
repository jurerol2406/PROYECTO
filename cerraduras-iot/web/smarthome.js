// smarthome.js – CerradurasIoT v4.0
// Roles: admin (pleno) | user (según perm de plano) | viewer (solo lectura)

const HOST        = window.location.hostname;
const API_BASE    = `http://${HOST}:8000`;
const MQTT_WS_URL = `ws://${HOST}:9001`;

const CURRENT_USER = window.CURRENT_USER || 'usuario';
const CURRENT_ROLE = window.CURRENT_ROLE || 'user';
const ES_ADMIN     = window.ES_ADMIN === true;
const ES_VISOR     = window.ES_VISOR  === true;

// Puede interactuar con cerraduras. Admin: siempre. Viewer: nunca.
// User: se actualiza al cargar plano según perm devuelta por la API.
let puedeInteractuar = window.INTERACTUAR_INICIAL === true;

let draggedLock     = null;
let currentData     = null;
let currentHouseId  = null;
let currentPlanId   = null;
let previousLocks   = [];
let mqttClient      = null;
let logsVisible     = false;

// ── Init ─────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    if (ES_ADMIN) inicializarBotonesInventario();
    conectarMQTT();

    document.getElementById('btn-generate')?.addEventListener('click', () => {
        const raw = (document.getElementById('json-input')?.value || '').trim();
        if (!raw) { alert('El textarea JSON está vacío.'); return; }
        try { aplicarNuevoPlano(JSON.parse(raw), false); }
        catch (e) { alert('JSON inválido:\n' + e.message); }
    });

    refrescarListaPlanes();
});

// ── Guardar plano (solo admin) ───────────────────────────────────
async function guardarPlanActual() {
    if (!ES_ADMIN) return;
    if (!currentData || !currentHouseId) {
        mostrarFeedback('⚠ Analiza un plano primero.', false);
        return;
    }

    const btn    = document.getElementById('btn-guardar-plano');
    const nombre = document.getElementById('empresa-nombre')?.value.trim()
                || currentData.name || 'Empresa';

    currentData.assigned_locks = [...previousLocks];
    currentData.name = nombre;

    btn.classList.add('saving');
    btn.textContent = '⏳ Guardando…';
    btn.disabled    = true;

    try {
        const resp = await fetch('save_plan.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ house_id: currentHouseId, name: nombre, json_data: currentData }),
        });

        const text = await resp.text();
        let data;
        try { data = JSON.parse(text); }
        catch (_) { throw new Error(`Respuesta no JSON (${resp.status}): ${text.slice(0, 120)}`); }

        if (!resp.ok || !data.ok) throw new Error(data.error || `HTTP ${resp.status}`);

        currentPlanId = data.plan_id;
        btn.classList.remove('saving');
        btn.classList.add('saved');
        btn.textContent = '✔ Guardado';
        btn.disabled    = false;

        mostrarFeedback(
            `✔ "${nombre}" ${data.action === 'created' ? 'creado' : 'actualizado'} — ${previousLocks.length} candado(s) guardado(s)`,
            true
        );

        document.getElementById('plan-title-badge')?.textContent &&
            (document.getElementById('plan-title-badge').textContent = `· ${nombre} [guardado]`);

        await refrescarListaPlanes();
        const sel = document.getElementById('select-plan');
        if (sel) sel.value = String(data.plan_id);

        _appendLog({ ts: _ahora(), accion: 'PLANO_GUARDADO', lock_id: '—', house_id: currentHouseId, usuario: CURRENT_USER });

    } catch (err) {
        console.error('[guardarPlanActual]', err);
        btn.classList.remove('saving');
        btn.textContent = '💾 Guardar plano';
        btn.disabled    = false;
        mostrarFeedback('✖ Error: ' + err.message, false);
    }
}

function mostrarFeedback(msg, ok) {
    const el = document.getElementById('save-feedback');
    if (!el) return;
    el.textContent   = msg;
    el.className     = 'save-feedback ' + (ok ? 'ok' : 'err');
    el.style.display = 'inline-block';
    clearTimeout(el._t);
    el._t = setTimeout(() => { el.style.display = 'none'; }, 7000);
}

// ── Selector de planos ───────────────────────────────────────────
async function refrescarListaPlanes() {
    const sel = document.getElementById('select-plan');
    if (!sel) return;
    try {
        const r = await fetch('api_planes.php');
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        const d = await r.json();
        if (!d.ok) throw new Error(d.error || '?');

        const planes  = d.plans || [];
        const curVal  = sel.value;
        sel.innerHTML = '<option value="">— Sin plano —</option>';
        planes.forEach(p => {
            const opt = document.createElement('option');
            opt.value       = p.id;
            opt.textContent = `${p.name}  [${String(p.house_id).slice(0, 8)}…]`;
            sel.appendChild(opt);
        });
        if (curVal && [...sel.options].some(o => o.value === curVal)) sel.value = curVal;

        _setUploadMsg(
            planes.length ? `${planes.length} plano(s) disponible(s)`
                          : (ES_ADMIN ? 'Sin planos guardados.' : 'Sin planos asignados.'),
            '#94a3b8'
        );
    } catch (e) {
        _setUploadMsg('Error cargando lista: ' + e.message, '#ef4444');
    }
}

async function cargarPlanSeleccionado() {
    const sel    = document.getElementById('select-plan');
    const planId = parseInt(sel?.value);
    if (!planId) return;

    try {
        const r = await fetch(`api_planes.php?id=${planId}`);
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        const d = await r.json();
        if (!d.ok) throw new Error(d.error);

        const plan = d.plan;
        const data = typeof plan.json_data === 'string'
            ? JSON.parse(plan.json_data)
            : plan.json_data;

        data.house_id       = data.house_id || plan.house_id;
        data.name           = data.name     || plan.name;
        data.assigned_locks = Array.isArray(data.assigned_locks) ? data.assigned_locks : [];

        // Actualizar permiso de interacción según lo que devuelve la API para este usuario
        // Admin: siempre true. Viewer: siempre false. User: según plan.perm
        if (ES_ADMIN) {
            puedeInteractuar = true;
        } else if (ES_VISOR) {
            puedeInteractuar = false;
        } else {
            // plan.perm viene de la API para usuarios normales
            puedeInteractuar = (plan.perm === 'interact');
        }

        currentPlanId = plan.id;
        aplicarNuevoPlano(data, true);

        const badge = document.getElementById('plan-title-badge');
        if (badge) {
            const permTag = ES_ADMIN ? '' : (puedeInteractuar ? ' [interactivo]' : ' [solo lectura]');
            badge.textContent = `· ${plan.name}  (por ${plan.creator_name})${permTag}`;
        }

        if (ES_ADMIN) {
            const btn = document.getElementById('btn-guardar-plano');
            if (btn) {
                btn.style.display = 'inline-block';
                btn.classList.remove('saving');
                btn.classList.add('saved');
                btn.textContent = '✔ Ya guardado';
                btn.disabled    = false;
            }
        }

        _setUploadMsg(`✔ "${plan.name}" cargado  (${data.assigned_locks.length} candado(s))`, '#10b981');
    } catch (e) {
        alert('Error cargando plano: ' + e.message);
    }
}

// ── MQTT ─────────────────────────────────────────────────────────
function conectarMQTT() {
    const badge = document.getElementById('mqtt-badge');
    try {
        mqttClient = mqtt.connect(MQTT_WS_URL, {
            clientId:        `cerraduras-web-${Math.random().toString(16).slice(2, 10)}`,
            reconnectPeriod: 4000,
            connectTimeout:  8000,
            clean:           true,
        });
    } catch (e) { console.error('[MQTT]', e.message); return; }

    mqttClient.on('connect', () => {
        badge.textContent = '🟢 MQTT';
        badge.classList.add('connected');
        mqttClient.subscribe('empresa/+/+/set', { qos: 1 });
        mqttClient.subscribe('empresa/logs',    { qos: 0 });
    });

    mqttClient.on('message', (topic, payload) => {
        const msg   = payload.toString();
        const parts = topic.split('/');

        if (parts.length === 4 && parts[0] === 'empresa' && parts[3] === 'set') {
            if (parts[1] === currentHouseId) {
                const cmd = msg.trim().toUpperCase();
                if (cmd === 'LOCK')   _aplicarEstadoCerrado(parts[2]);
                if (cmd === 'UNLOCK') _aplicarEstadoAbierto(parts[2]);
            }
            return;
        }
        if (topic === 'empresa/logs') {
            try { _appendLog(JSON.parse(msg)); } catch (_) {}
        }
    });

    mqttClient.on('error',   e  => { badge.textContent = '🔴 MQTT'; badge.classList.remove('connected'); console.error('[MQTT]', e.message); });
    mqttClient.on('offline', () => { badge.textContent = '🟡 MQTT'; badge.classList.remove('connected'); });
}

function publicarEstadoMQTT(lockId, estado) {
    if (!puedeInteractuar) return; // bloqueo adicional viewer/view-perm
    if (mqttClient?.connected && currentHouseId) {
        mqttClient.publish(
            `empresa/${currentHouseId}/estado`,
            JSON.stringify({ house_id: currentHouseId, locks: [{ id: lockId, state: estado }], usuario: CURRENT_USER }),
            { qos: 1, retain: false }
        );
    }
    _guardarLogBD(currentHouseId || 'unknown', lockId, estado === 'locked' ? 'CERRAR' : 'ABRIR');
}

function publicarLimpiezaMQTT(oldHouseId, oldLocks) {
    if (mqttClient?.connected && oldHouseId && oldLocks.length) {
        mqttClient.publish(
            `empresa/limpiar/${oldHouseId}`,
            JSON.stringify({ house_id: oldHouseId, locks: oldLocks }),
            { qos: 1, retain: false }
        );
    }
}

// ── Análisis desde archivo (solo admin) ─────────────────────────
async function analizarPlanoDesdeArchivo() {
    if (!ES_ADMIN) return;

    const fileInput  = document.getElementById('plano-file');
    const spinner    = document.getElementById('spinner-upload');
    const btn        = document.getElementById('btn-analizar');
    const btnGuardar = document.getElementById('btn-guardar-plano');
    const nombre     = document.getElementById('empresa-nombre')?.value.trim() || 'Mi Empresa';

    if (!fileInput?.files?.length) { alert('Selecciona un archivo JPG/PNG primero.'); return; }

    const formData = new FormData();
    formData.append('file',   fileInput.files[0]);
    formData.append('nombre', nombre);

    btn.disabled    = true;
    btn.textContent = '⏳ Analizando…';
    if (spinner)    spinner.style.display = 'block';
    if (btnGuardar) { btnGuardar.style.display = 'none'; btnGuardar.classList.remove('saved'); }
    _setUploadMsg('', '');

    try {
        const res = await fetch(`${API_BASE}/procesar`, { method: 'POST', body: formData });
        if (!res.ok) {
            const err = await res.json().catch(() => ({ detail: res.statusText }));
            throw new Error(err.detail || `HTTP ${res.status}`);
        }
        const data = await res.json();
        data.name           = nombre;
        data.assigned_locks = [];

        aplicarNuevoPlano(data, false);
        _setUploadMsg(`✔ ${data.rooms?.length ?? 0} depts — asigna candados y pulsa "Guardar plano"`, '#f59e0b');

        if (btnGuardar) {
            btnGuardar.style.display = 'inline-block';
            btnGuardar.classList.remove('saved', 'saving');
            btnGuardar.textContent = '💾 Guardar plano';
            btnGuardar.disabled    = false;
        }
    } catch (e) {
        _setUploadMsg('✖ ' + e.message, '#ef4444');
        alert('Error al procesar plano:\n' + e.message);
    } finally {
        btn.disabled    = false;
        btn.textContent = '🔍 Analizar';
        if (spinner) spinner.style.display = 'none';
    }
}

// ── Aplicar plano ────────────────────────────────────────────────
function aplicarNuevoPlano(data, yaGuardado) {
    const oldHouseId = currentHouseId;
    const oldLocks   = previousLocks.slice();

    if (oldHouseId && oldLocks.length) publicarLimpiezaMQTT(oldHouseId, oldLocks);

    currentHouseId = data.house_id || _uuid();
    previousLocks  = [];
    currentData    = data;
    if (!yaGuardado) currentPlanId = null;

    if (!Array.isArray(currentData.assigned_locks)) currentData.assigned_locks = [];

    const hEl = document.getElementById('house-id-display');
    if (hEl) hEl.textContent = currentHouseId.slice(0, 18) + '…';

    if (ES_ADMIN) {
        const ji = document.getElementById('json-input');
        if (ji) ji.value = JSON.stringify(data, null, 2);
    }

    renderizarPlano(data);
    _appendLog({ ts: _ahora(), accion: 'CARGA_PLANO', lock_id: '—', house_id: currentHouseId, usuario: CURRENT_USER });
}

// ── Renderizado SVG ──────────────────────────────────────────────
function renderizarPlano(data) {
    const container = document.getElementById('canvas-container');
    const pool      = document.getElementById('lock-pool');
    container.innerHTML = '';
    if (pool) pool.innerHTML = '';

    const lista     = document.getElementById('lista-dispositivos');
    const panelCtrl = document.getElementById('panel-control-detallado');
    if (lista)     lista.innerHTML         = '';
    if (panelCtrl) panelCtrl.style.display = 'none';
    document.getElementById('count-assigned').innerText = '0';

    let totalDoors = 0;
    const NS  = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('width', '100%');
    svg.setAttribute('height', '100%');
    svg.setAttribute('viewBox', '0 0 100 100');

    const defs = document.createElementNS(NS, 'defs');
    const pat  = document.createElementNS(NS, 'pattern');
    pat.setAttribute('id', 'grid'); pat.setAttribute('width', '10');
    pat.setAttribute('height', '10'); pat.setAttribute('patternUnits', 'userSpaceOnUse');
    const pl = document.createElementNS(NS, 'path');
    pl.setAttribute('d', 'M 10 0 L 0 0 0 10'); pl.setAttribute('fill', 'none');
    pl.setAttribute('stroke', '#1e293b'); pl.setAttribute('stroke-width', '0.2');
    pat.appendChild(pl); defs.appendChild(pat); svg.appendChild(defs);

    const bg = document.createElementNS(NS, 'rect');
    bg.setAttribute('width', '100'); bg.setAttribute('height', '100');
    bg.setAttribute('fill', 'url(#grid)'); svg.appendChild(bg);

    const hues       = [160, 200, 260, 30, 300, 120, 190, 50, 340, 80];
    const savedLocks = new Set(Array.isArray(data.assigned_locks) ? data.assigned_locks : []);

    (data.rooms || []).forEach((room, ri) => {
        const hue = hues[ri % hues.length];

        const rect = document.createElementNS(NS, 'rect');
        rect.setAttribute('x', room.x); rect.setAttribute('y', room.y);
        rect.setAttribute('width', room.width); rect.setAttribute('height', room.height);
        rect.setAttribute('rx', '0.5');
        rect.style.cssText = `fill:hsla(${hue},70%,40%,.08);stroke:hsl(${hue},70%,50%);stroke-width:0.4;`;
        svg.appendChild(rect);

        const txt = document.createElementNS(NS, 'text');
        txt.setAttribute('x', room.x + room.width / 2);
        txt.setAttribute('y', room.y + room.height / 2);
        txt.setAttribute('font-size', '2.4');
        txt.setAttribute('fill', `hsl(${hue},50%,65%)`);
        txt.setAttribute('text-anchor', 'middle');
        txt.setAttribute('dominant-baseline', 'middle');
        txt.textContent = room.name;
        svg.appendChild(txt);

        (room.doors || []).forEach(door => {
            totalDoors++;

            if (ES_ADMIN && !savedLocks.has(door.id)) _crearCandado();

            const { dx, dy } = _posPuerta(room, door);
            const c = document.createElementNS(NS, 'circle');
            c.setAttribute('cx', dx); c.setAttribute('cy', dy);
            c.setAttribute('r', '2.2'); c.setAttribute('id', door.id);
            c.setAttribute('class', 'door-node');

            const ubicacion = `${room.name} (${_pos(door.position)})`;

            if (savedLocks.has(door.id)) {
                c.style.cssText = 'fill:#ef4444;stroke:gold;stroke-width:0.8;cursor:' + (puedeInteractuar ? 'pointer' : 'default') + ';';
                c.dataset.assigned = 'true';
                previousLocks.push(door.id);
                svg.appendChild(c);
                _crearFilaControl(door.id, ubicacion);
            } else {
                c.style.cssText = 'fill:#1e293b;cursor:pointer;stroke:#475569;stroke-width:0.4;';
                svg.appendChild(c);
            }

            const t = document.createElementNS(NS, 'title');
            t.textContent = `${ubicacion} (${door.id})`;
            c.appendChild(t);
        });
    });

    container.appendChild(svg);

    document.getElementById('count-rooms').innerText    = (data.rooms || []).length;
    document.getElementById('count-doors').innerText    = totalDoors;
    document.getElementById('count-assigned').innerText = savedLocks.size;

    if (ES_ADMIN) {
        _actualizarDisponibles();
        _habilitarDrop();
    }

    if (savedLocks.size > 0) {
        if (panelCtrl) panelCtrl.style.display = 'block';
    }

    _actualizarHeader();
}

function _posPuerta(room, door) {
    let dx = room.x, dy = room.y;
    const p = (door.offset || 50) / 100;
    if (door.position === 'right')  { dx = room.x + room.width;     dy = room.y + room.height * p; }
    if (door.position === 'left')   { dx = room.x;                  dy = room.y + room.height * p; }
    if (door.position === 'bottom') { dx = room.x + room.width * p; dy = room.y + room.height;     }
    if (door.position === 'top')    { dx = room.x + room.width * p; dy = room.y;                   }
    return { dx, dy };
}
function _pos(p) { return { left:'Izq.', right:'Der.', top:'Sup.', bottom:'Inf.' }[p] || p; }

// ── Inventario de cerraduras (solo admin) ────────────────────────
function _crearCandado() {
    const pool = document.getElementById('lock-pool');
    if (!pool) return;
    const item     = document.createElement('div');
    item.className = 'lock-item';
    item.draggable = true;
    item.innerText = '🔒';
    item.id        = 'lock-' + Math.random().toString(36).slice(2, 7);
    item.addEventListener('dragstart', function () { draggedLock = this; this.style.opacity = '.45'; });
    item.addEventListener('dragend',   function () { this.style.opacity = '1'; });
    pool.appendChild(item);
}

function inicializarBotonesInventario() {
    document.getElementById('btn-add-lock')?.addEventListener('click', () => {
        _crearCandado(); _actualizarDisponibles(); _actualizarHeader();
    });
    document.getElementById('btn-remove-lock')?.addEventListener('click', () => {
        const pool = document.getElementById('lock-pool');
        if (pool?.lastElementChild) { pool.removeChild(pool.lastElementChild); _actualizarDisponibles(); _actualizarHeader(); }
    });
}

function _actualizarDisponibles() {
    document.getElementById('count-available').innerText =
        document.getElementById('lock-pool')?.children.length ?? 0;
}

// ── Drag & Drop (solo admin) ─────────────────────────────────────
function _habilitarDrop() {
    if (!ES_ADMIN) return;
    document.querySelectorAll('.door-node').forEach(puerta => {
        puerta.addEventListener('dragover', e => e.preventDefault());
        puerta.addEventListener('drop', function (e) {
            e.preventDefault();
            if (!draggedLock || this.dataset.assigned) return;

            draggedLock.remove();
            this.style.fill        = '#ef4444';
            this.style.stroke      = 'gold';
            this.style.strokeWidth = '0.8';
            this.dataset.assigned  = 'true';

            previousLocks.push(this.id);

            if (!Array.isArray(currentData.assigned_locks)) currentData.assigned_locks = [];
            if (!currentData.assigned_locks.includes(this.id)) {
                currentData.assigned_locks.push(this.id);
            }

            const r = (currentData?.rooms || []).find(r => (r.doors || []).some(d => d.id === this.id));
            const d = (r?.doors || []).find(d => d.id === this.id);
            _crearFilaControl(this.id, r ? `${r.name} (${_pos(d?.position)})` : this.id);
            publicarEstadoMQTT(this.id, 'locked');

            document.getElementById('count-assigned').innerText = previousLocks.length;
            _actualizarDisponibles();
            _actualizarHeader();

            const btnG = document.getElementById('btn-guardar-plano');
            if (btnG) {
                btnG.style.display = 'inline-block';
                btnG.classList.remove('saved');
                btnG.textContent = '💾 Guardar plano';
                btnG.disabled    = false;
            }

            draggedLock = null;
        });
    });
}

// ── Panel de control ─────────────────────────────────────────────
function _crearFilaControl(doorId, ubicacion) {
    const panel = document.getElementById('panel-control-detallado');
    const lista = document.getElementById('lista-dispositivos');
    if (!panel || !lista) return;
    if (document.getElementById(`row-${doorId}`)) return;

    panel.style.display = 'block';

    // El botón de acción solo aparece si el usuario puede interactuar
    const accionHtml = puedeInteractuar
        ? `<button onclick="interactuar('${doorId}')" id="btn-${doorId}"
               style="background:#10b981;border:none;color:white;padding:5px 14px;
                      border-radius:4px;cursor:pointer;font-size:.78rem;font-weight:bold;">Abrir</button>`
        : `<span style="color:#475569;font-size:.75rem;padding:5px 10px;">🔒 Sin acceso</span>`;

    const fila = document.createElement('div');
    fila.className = 'device-row';
    fila.id        = `row-${doorId}`;
    fila.style.cssText = 'background:#0f172a;padding:10px;border-radius:8px;display:flex;'
        + 'justify-content:space-between;align-items:center;border-left:4px solid #ef4444;margin-bottom:6px;';
    fila.innerHTML = `
        <div style="display:flex;align-items:center;gap:10px;">
            <span id="icon-${doorId}" style="font-size:1.1rem;">🔒</span>
            <div>
                <div style="font-size:.82rem;font-weight:bold;color:white;">${_esc(ubicacion)}</div>
                <small id="status-${doorId}" style="color:#ef4444;">Estado: Cerrado</small>
            </div>
        </div>
        ${accionHtml}`;
    lista.appendChild(fila);
}

window.interactuar = function (doorId) {
    if (!puedeInteractuar) {
        // Bloqueo silencioso — no debe ocurrir si la UI está bien construida
        console.warn('[interactuar] bloqueado — sin permiso de interacción');
        return;
    }
    const c = document.getElementById(doorId);
    if (!c) return;
    const f = c.style.fill;
    if (f === 'rgb(239, 68, 68)' || f === '#ef4444') {
        _aplicarEstadoAbierto(doorId);  publicarEstadoMQTT(doorId, 'unlocked');
    } else {
        _aplicarEstadoCerrado(doorId);  publicarEstadoMQTT(doorId, 'locked');
    }
};

function _aplicarEstadoAbierto(id) {
    const c = document.getElementById(id), b = document.getElementById(`btn-${id}`),
          s = document.getElementById(`status-${id}`), i = document.getElementById(`icon-${id}`),
          r = document.getElementById(`row-${id}`);
    if (!c) return;
    c.style.fill = '#10b981';
    if (b) { b.innerText = 'Cerrar'; b.style.background = '#ef4444'; }
    if (s) { s.innerText = 'Estado: Abierto'; s.style.color = '#10b981'; }
    if (i) i.innerText = '🔓';
    if (r) r.style.borderLeftColor = '#10b981';
}

function _aplicarEstadoCerrado(id) {
    const c = document.getElementById(id), b = document.getElementById(`btn-${id}`),
          s = document.getElementById(`status-${id}`), i = document.getElementById(`icon-${id}`),
          r = document.getElementById(`row-${id}`);
    if (!c) return;
    c.style.fill = '#ef4444';
    if (b) { b.innerText = 'Abrir'; b.style.background = '#10b981'; }
    if (s) { s.innerText = 'Estado: Cerrado'; s.style.color = '#ef4444'; }
    if (i) i.innerText = '🔒';
    if (r) r.style.borderLeftColor = '#ef4444';
}

// ── Logs ─────────────────────────────────────────────────────────
function toggleLogs() {
    const panel = document.getElementById('panel-logs');
    logsVisible = !logsVisible;
    panel.style.display = logsVisible ? 'block' : 'none';
    document.getElementById('btn-logs').textContent = logsVisible ? '📋 Ocultar Logs' : '📋 Logs';
    if (logsVisible) cargarLogsDesdeDB();
}

function limpiarLogsUI() {
    const c = document.getElementById('logs-container');
    if (c) c.innerHTML = '<div style="color:#475569;">Vista limpiada.</div>';
}

async function cargarLogsDesdeDB() {
    try {
        const url = currentHouseId
            ? `api_logs.php?limit=100&house_id=${encodeURIComponent(currentHouseId)}`
            : 'api_logs.php?limit=100';
        const r = await fetch(url);
        const d = await r.json();
        if (!d.ok) throw new Error(d.error);
        const c = document.getElementById('logs-container');
        if (!c) return;
        c.innerHTML = '';
        if (!d.logs.length) { c.innerHTML = '<div style="color:#475569;">Sin registros.</div>'; return; }
        [...d.logs].reverse().forEach(log => _appendLog(log));
    } catch (e) { console.warn('[Logs]', e.message); }
}

function _appendLog(log) {
    const c = document.getElementById('logs-container');
    if (!c) return;
    const accion = log.accion || '?', lockId = log.lock_id || '—';
    const user   = log.usuario || 'sistema', hid = (log.house_id || '').slice(0, 8);
    const col    = (accion.includes('ABRIR') || accion.includes('UNLOCK')) ? '#10b981'
                 : (accion.includes('CERRAR') || accion.includes('LOCK'))  ? '#ef4444' : '#94a3b8';
    const div = document.createElement('div');
    div.style.cssText = `color:${col};margin-bottom:3px;`;
    div.textContent   = `[${_formatTs(log.ts || _ahora())}] ${accion.padEnd(18)} ${lockId.padEnd(12)} 👤${user} 🏢${hid}`;
    c.insertBefore(div, c.firstChild);
    while (c.children.length > 200) c.removeChild(c.lastChild);
}

async function exportarLogs() {
    if (!ES_ADMIN) return;
    try {
        const url = currentHouseId
            ? `api_logs.php?limit=500&house_id=${encodeURIComponent(currentHouseId)}`
            : 'api_logs.php?limit=500';
        const d = await fetch(url).then(r => r.json());
        if (!d.ok) throw new Error(d.error);
        const rows = [['ID', 'house_id', 'lock_id', 'accion', 'usuario', 'ts']];
        d.logs.forEach(l => rows.push([l.id, l.house_id, l.lock_id, l.accion, l.usuario, l.ts]));
        const csv = rows.map(r => r.map(c => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n');
        const a   = Object.assign(document.createElement('a'), {
            href:     URL.createObjectURL(new Blob([csv], { type: 'text/csv' })),
            download: `logs_${(currentHouseId || 'all').slice(0, 8)}_${_ahora().replace(/[: ]/g, '-')}.csv`,
        });
        a.click();
    } catch (e) { alert('Error exportando: ' + e.message); }
}

async function _guardarLogBD(houseId, lockId, accion) {
    if (ES_VISOR || !puedeInteractuar) return; // viewers no generan logs de operación
    try {
        await fetch('api_logs.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ house_id: houseId, lock_id: lockId, accion, usuario: CURRENT_USER }),
        });
    } catch (_) {}
}

// ── Utils ─────────────────────────────────────────────────────────
function _actualizarHeader() {
    const total = document.getElementById('count-doors')?.innerText    || '0';
    const asig  = document.getElementById('count-assigned')?.innerText || '0';
    const el    = document.getElementById('header-status');
    if (el) el.innerText = `🟢 ${asig}/${total} accesos protegidos`;
}

function _uuid() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
        const r = Math.random() * 16 | 0;
        return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
}

function _ahora()      { return new Date().toISOString().replace('T', ' ').slice(0, 19); }
function _formatTs(ts) { try { return String(ts).slice(11, 19) || String(ts); } catch (_) { return ''; } }
function _esc(s)       { return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

function _setUploadMsg(msg, color) {
    const el = document.getElementById('upload-msg');
    if (el) { el.textContent = msg; el.style.color = color; }
}
