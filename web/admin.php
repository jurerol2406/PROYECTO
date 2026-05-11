<?php
require_once 'auth.php';
requireAdmin();
require_once 'conexion.php';

// Estadísticas para el dashboard
$stats = [
    'total_users'   => (int) $conexion->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'total_plans'   => (int) $conexion->query("SELECT COUNT(*) FROM plans")->fetchColumn(),
    'total_logs'    => (int) $conexion->query("SELECT COUNT(*) FROM Logs")->fetchColumn(),
    'total_permisos'=> (int) $conexion->query("SELECT COUNT(*) FROM user_plan_permissions")->fetchColumn(),
    'locks_today'   => (int) $conexion->query(
        "SELECT COUNT(*) FROM Logs WHERE date(ts) = date('now')"
    )->fetchColumn(),
    'locks_week'    => (int) $conexion->query(
        "SELECT COUNT(*) FROM Logs WHERE ts >= datetime('now', '-7 days')"
    )->fetchColumn(),
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CerradurasIoT – Administración</title>
    <link rel="stylesheet" href="smarthome.css">
    <style>
        .admin-wrap    { max-width: 1100px; margin: 0 auto; padding: 24px; }
        .page-header   { display:flex; justify-content:space-between; align-items:center;
                         border-bottom:1px solid #334155; padding-bottom:14px; margin-bottom:24px; }
        .page-title    { font-size:1.4rem; font-weight:bold; color:var(--accent-color); }
        .page-sub      { color:var(--text-dim); font-size:.85rem; }

        .tab-bar       { display:flex; gap:4px; margin-bottom:20px; flex-wrap:wrap; }
        .tab-btn       { padding:8px 18px; border:none; border-radius:8px 8px 0 0;
                         cursor:pointer; font-weight:600; font-size:.85rem;
                         background:#1e293b; color:var(--text-dim); transition:all .2s; }
        .tab-btn.active{ background:var(--card-bg); color:white; border-bottom:2px solid var(--accent-color); }
        .tab-panel     { display:none; }
        .tab-panel.active { display:block; }

        table.tbl      { width:100%; border-collapse:collapse; margin-top:8px; }
        table.tbl th,
        table.tbl td   { padding:10px 12px; text-align:left;
                         border-bottom:1px solid #1e293b; font-size:.85rem; }
        table.tbl th   { color:var(--text-dim); font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; }
        table.tbl tr:hover td { background:rgba(16,185,129,.03); }

        .btn-del  { background:#ef4444;border:none;color:white;padding:4px 12px;border-radius:5px;cursor:pointer;font-size:.76rem;font-weight:600; }
        .btn-del:hover  { opacity:.8; }
        .btn-del:disabled { opacity:.4; cursor:not-allowed; }
        .btn-perm { background:#10b981;border:none;color:white;padding:4px 12px;border-radius:5px;cursor:pointer;font-size:.76rem;font-weight:600; }
        .btn-perm:hover { opacity:.8; }

        .form-row { display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:14px; }
        .form-row input, .form-row select {
            background:var(--bg-color); border:1px solid #334155; color:white;
            padding:9px 11px; border-radius:7px; font-size:.86rem; transition:border-color .2s;
        }
        .form-row input:focus, .form-row select:focus { outline:none; border-color:var(--accent-color); }
        .form-row select option { background:#1e293b; }

        .alert { padding:10px 15px; border-radius:7px; margin-bottom:12px; font-size:.86rem; display:none; }
        .alert-ok  { background:rgba(16,185,129,.12);color:#10b981;border:1px solid #10b98155; }
        .alert-err { background:rgba(239,68,68,.12);color:#ef4444;border:1px solid #ef444455; }
        .self-tag  { color:#94a3b8; font-size:.73rem; margin-left:5px; }

        /* Stats dashboard */
        .stats-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:20px; }
        @media(max-width:600px){ .stats-grid { grid-template-columns:repeat(2,1fr); } }
        .kpi { background:var(--card-bg); padding:18px; border-radius:12px; text-align:center; border-top:3px solid; }
        .kpi-green { border-color:var(--accent-color); }
        .kpi-blue  { border-color:var(--blue-color); }
        .kpi-purple{ border-color:var(--purple-color); }
        .kpi-amber { border-color:var(--amber-color); }
        .kpi h4    { color:var(--text-dim); margin:0 0 8px; font-size:.78rem; text-transform:uppercase; }
        .kpi p     { font-size:2rem; font-weight:bold; margin:0; }

        /* Modal */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.7);
                         z-index:1000; align-items:center; justify-content:center; }
        .modal-overlay.open { display:flex; }
        .modal-box { background:var(--card-bg); border-radius:14px; width:600px; max-width:95vw;
                     max-height:85vh; overflow-y:auto; padding:28px 24px;
                     border-top:4px solid var(--accent-color); }
        .modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
        .modal-header h3 { margin:0; font-size:1rem; }
        .btn-close { background:none;border:none;color:#94a3b8;font-size:1.4rem;cursor:pointer;line-height:1; }
        .btn-close:hover { color:white; }
    </style>
</head>
<body>
<div class="admin-wrap">

    <div class="page-header">
        <div>
            <div class="page-title">🏢 Cerraduras IoT</div>
            <div class="page-sub">Panel de Administración ·
                <strong><?= htmlspecialchars($auth_user, ENT_QUOTES, 'UTF-8') ?></strong>
                <span class="tbadge-admin">Admin</span>
            </div>
        </div>
        <a href="index.php" class="btn-sm btn-teal">← Volver al Panel</a>
    </div>

    <div id="alert-box" class="alert"></div>

    <div class="tab-bar">
        <button class="tab-btn active" onclick="switchTab('dashboard')">📊 Dashboard</button>
        <button class="tab-btn"        onclick="switchTab('usuarios')">👥 Usuarios</button>
        <button class="tab-btn"        onclick="switchTab('planos')">📐 Planos</button>
        <button class="tab-btn"        onclick="switchTab('permisos')">🔑 Permisos</button>
        <button class="tab-btn"        onclick="switchTab('auditoria')">🕵️ Auditoría</button>
    </div>

    <!-- ══ DASHBOARD ══════════════════════════════════════════════ -->
    <div id="tab-dashboard" class="tab-panel active">
        <div class="stats-grid">
            <div class="kpi kpi-green">
                <h4>Usuarios totales</h4>
                <p><?= $stats['total_users'] ?></p>
            </div>
            <div class="kpi kpi-blue">
                <h4>Planos guardados</h4>
                <p><?= $stats['total_plans'] ?></p>
            </div>
            <div class="kpi kpi-purple">
                <h4>Permisos activos</h4>
                <p><?= $stats['total_permisos'] ?></p>
            </div>
            <div class="kpi kpi-green">
                <h4>Operaciones hoy</h4>
                <p><?= $stats['locks_today'] ?></p>
            </div>
            <div class="kpi kpi-amber">
                <h4>Operaciones (7 días)</h4>
                <p><?= $stats['locks_week'] ?></p>
            </div>
            <div class="kpi kpi-purple">
                <h4>Logs totales</h4>
                <p><?= $stats['total_logs'] ?></p>
            </div>
        </div>

        <?php
        // Top 5 cerraduras más activas
        $topLocks = $conexion->query(
            "SELECT lock_id, COUNT(*) AS ops FROM Logs GROUP BY lock_id ORDER BY ops DESC LIMIT 5"
        )->fetchAll();
        // Última actividad
        $recentLogs = $conexion->query(
            "SELECT lock_id, accion, usuario, ts FROM Logs ORDER BY ts DESC LIMIT 10"
        )->fetchAll();
        ?>
        <div class="card">
            <h3>🏆 Top 5 accesos más activos</h3>
            <?php if ($topLocks): ?>
            <table class="tbl">
                <thead><tr><th>Cerradura</th><th>Operaciones</th></tr></thead>
                <tbody>
                <?php foreach ($topLocks as $tl): ?>
                    <tr>
                        <td><code style="font-size:.8rem;color:#4ade80;"><?= htmlspecialchars($tl['lock_id']) ?></code></td>
                        <td><strong><?= $tl['ops'] ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p style="color:var(--text-dim);">Sin actividad registrada todavía.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>🕐 Actividad reciente</h3>
            <?php if ($recentLogs): ?>
            <table class="tbl">
                <thead><tr><th>Cerradura</th><th>Acción</th><th>Usuario</th><th>Fecha</th></tr></thead>
                <tbody>
                <?php foreach ($recentLogs as $rl): ?>
                    <tr>
                        <td><code style="font-size:.78rem;color:#4ade80;"><?= htmlspecialchars($rl['lock_id']) ?></code></td>
                        <td><?= htmlspecialchars($rl['accion']) ?></td>
                        <td><?= htmlspecialchars($rl['usuario']) ?></td>
                        <td style="color:var(--text-dim);font-size:.8rem;"><?= $rl['ts'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p style="color:var(--text-dim);">Sin actividad registrada todavía.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ USUARIOS ═══════════════════════════════════════════════ -->
    <div id="tab-usuarios" class="tab-panel">
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                <h3>👥 Usuarios del Sistema</h3>
                <button class="btn-sm btn-teal" style="font-size:.75rem;" onclick="cargarUsuarios()">🔄</button>
            </div>
            <table class="tbl">
                <thead><tr><th>#</th><th>Usuario</th><th>Rol</th><th>Creado</th><th>Acciones</th></tr></thead>
                <tbody id="tbody-usuarios">
                    <tr><td colspan="5" style="color:var(--text-dim);text-align:center;padding:20px;">Cargando…</td></tr>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h3>➕ Nuevo Usuario</h3>
            <div class="form-row">
                <input type="text"     id="new-username" placeholder="Usuario (mín. 3)" maxlength="30" style="flex:1;min-width:130px;">
                <input type="password" id="new-password" placeholder="Contraseña (mín. 4)" maxlength="128" style="flex:1;min-width:150px;">
                <select id="new-role" style="min-width:140px;">
                    <option value="user">👷 Usuario</option>
                    <option value="viewer">👁 Solo Ver</option>
                    <option value="admin">⚡ Admin</option>
                </select>
                <button class="btn-primary" style="margin-top:0;width:auto;padding:9px 20px;"
                        onclick="crearUsuario()">Crear</button>
            </div>
        </div>

        <div class="card">
            <h3>✏️ Editar Usuario</h3>
            <div class="form-row">
                <input type="number"   id="edit-id"       placeholder="ID" min="1" style="width:80px;">
                <input type="password" id="edit-password" placeholder="Nueva contraseña (opcional)" maxlength="128" style="flex:1;min-width:150px;">
                <select id="edit-role" style="min-width:150px;">
                    <option value="">Sin cambio de rol</option>
                    <option value="user">👷 Usuario</option>
                    <option value="viewer">👁 Solo Ver</option>
                    <option value="admin">⚡ Admin</option>
                </select>
                <button class="btn-primary" style="margin-top:0;width:auto;padding:9px 20px;"
                        onclick="editarUsuario()">Actualizar</button>
            </div>
        </div>
    </div>

    <!-- ══ PLANOS ═════════════════════════════════════════════════ -->
    <div id="tab-planos" class="tab-panel">
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                <h3>📐 Planos Guardados</h3>
                <button class="btn-sm btn-teal" style="font-size:.75rem;" onclick="cargarPlanos()">🔄</button>
            </div>
            <p style="color:var(--text-dim);font-size:.82rem;margin:0 0 12px 0;">
                Los planos se guardan desde el panel principal tras analizar una imagen.
            </p>
            <table class="tbl">
                <thead><tr><th>#</th><th>Nombre</th><th>Creador</th><th>House ID</th><th>Accesos</th><th>Guardado</th><th></th></tr></thead>
                <tbody id="tbody-planos">
                    <tr><td colspan="7" style="color:var(--text-dim);text-align:center;padding:20px;">Cargando…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ══ PERMISOS ═══════════════════════════════════════════════ -->
    <div id="tab-permisos" class="tab-panel">
        <div class="card">
            <h3>➕ Asignar Permiso</h3>
            <div class="form-row">
                <select id="perm-user" style="flex:1;min-width:160px;">
                    <option value="">— Usuario —</option>
                </select>
                <select id="perm-plan" style="flex:1;min-width:180px;">
                    <option value="">— Plano —</option>
                </select>
                <select id="perm-nivel" style="min-width:160px;">
                    <option value="interact">🔓 Interactivo (abrir/cerrar)</option>
                    <option value="view">👁 Solo visualización</option>
                </select>
                <button class="btn-primary" style="margin-top:0;width:auto;padding:9px 20px;"
                        onclick="otorgarPermiso()">Asignar</button>
            </div>
        </div>

        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                <h3>🔑 Permisos Activos</h3>
                <button class="btn-sm btn-teal" style="font-size:.75rem;" onclick="cargarPermisosActivos()">🔄</button>
            </div>
            <table class="tbl">
                <thead><tr><th>Usuario</th><th>Rol</th><th>Plano</th><th>Nivel</th><th>Desde</th><th></th></tr></thead>
                <tbody id="tbody-permisos">
                    <tr><td colspan="6" style="color:var(--text-dim);text-align:center;padding:20px;">Cargando…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ══ AUDITORÍA ══════════════════════════════════════════════ -->
    <div id="tab-auditoria" class="tab-panel">
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <h3>🕵️ Registro de Auditoría</h3>
                <button class="btn-sm btn-teal" style="font-size:.75rem;" onclick="cargarAuditoria()">🔄</button>
            </div>
            <p style="color:var(--text-dim);font-size:.82rem;margin:0 0 12px 0;">
                Acciones de administración: creación de usuarios, asignación de permisos, eliminación de planos.
            </p>
            <table class="tbl">
                <thead><tr><th>Fecha</th><th>Actor</th><th>Acción</th><th>Objeto</th><th>Detalle</th><th>IP</th></tr></thead>
                <tbody id="tbody-auditoria">
                    <tr><td colspan="6" style="color:var(--text-dim);text-align:center;padding:20px;">Cargando…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal permisos por plano -->
<div class="modal-overlay" id="modal-permisos">
    <div class="modal-box">
        <div class="modal-header">
            <h3>🔑 Permisos del plano: <span id="modal-plan-nombre">—</span></h3>
            <button class="btn-close" onclick="cerrarModal()">×</button>
        </div>
        <div id="modal-lista-permisos" style="margin-bottom:16px;"></div>
        <div style="border-top:1px solid #334155;padding-top:14px;">
            <strong style="font-size:.85rem;">Añadir usuario:</strong>
            <div class="form-row" style="margin-top:8px;">
                <select id="modal-user-select" style="flex:1;min-width:150px;">
                    <option value="">— Seleccionar —</option>
                </select>
                <select id="modal-perm-nivel" style="min-width:160px;">
                    <option value="interact">🔓 Interactivo</option>
                    <option value="view">👁 Solo ver</option>
                </select>
                <button class="btn-perm" style="padding:9px 16px;" onclick="otorgarPermisoModal()">Asignar</button>
            </div>
        </div>
    </div>
</div>

<script>
const API_USERS  = 'api_usuarios.php';
const API_PLANES = 'api_planes.php';
const API_PERMS  = 'api_permisos.php';
const ME         = <?= json_encode($auth_user) ?>;

let allUsers  = [];
let allPlans  = [];
let modalPlanId = null;

// ── Tabs ────────────────────────────────────────────────────────
const TAB_NAMES = ['dashboard','usuarios','planos','permisos','auditoria'];

function switchTab(name) {
    document.querySelectorAll('.tab-btn').forEach((b, i) => {
        b.classList.toggle('active', TAB_NAMES[i] === name);
    });
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');

    if (name === 'usuarios')  cargarUsuarios();
    if (name === 'planos')    cargarPlanos();
    if (name === 'permisos')  { cargarSelectsPermisos(); cargarPermisosActivos(); }
    if (name === 'auditoria') cargarAuditoria();
}

// ── Badge helpers ───────────────────────────────────────────────
function rolBadge(role) {
    const map = { admin: 'tbadge-admin', user: 'tbadge-user', viewer: 'tbadge-viewer' };
    const labels = { admin: '⚡ Admin', user: '👷 Usuario', viewer: '👁 Solo Ver' };
    return `<span class="${map[role] || 'tbadge-user'}">${labels[role] || role}</span>`;
}
function permBadge(perm) {
    return perm === 'view'
        ? `<span class="tbadge-viewer">👁 Solo ver</span>`
        : `<span class="tbadge-perm">🔓 Interactivo</span>`;
}

// ── USUARIOS ════════════════════════════════════════════════════
async function cargarUsuarios() {
    try {
        const d = await fetch(API_USERS).then(r => r.json());
        if (!d.ok) throw new Error(d.error);
        allUsers = d.users;
        const tbody = document.getElementById('tbody-usuarios');
        tbody.innerHTML = '';
        if (!d.users.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="color:var(--text-dim);text-align:center;">Sin usuarios.</td></tr>';
            return;
        }
        d.users.forEach(u => {
            const isSelf = u.username === ME;
            const delBtn = isSelf
                ? '<button class="btn-del" disabled>Eliminar</button>'
                : `<button class="btn-del" onclick="eliminarUsuario(${u.id},'${esc(u.username)}')">Eliminar</button>`;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="color:var(--text-dim);">${u.id}</td>
                <td><strong>${esc(u.username)}</strong>${isSelf ? '<span class="self-tag">(tú)</span>' : ''}</td>
                <td>${rolBadge(u.role)}</td>
                <td style="color:var(--text-dim);font-size:.8rem;">${u.created_at ?? '—'}</td>
                <td>${delBtn}</td>`;
            tbody.appendChild(tr);
        });
    } catch (e) { alerta('Error cargando usuarios: ' + e.message, false); }
}

async function crearUsuario() {
    const username = document.getElementById('new-username').value.trim();
    const password = document.getElementById('new-password').value;
    const role     = document.getElementById('new-role').value;
    if (!username || username.length < 3) { alerta('Usuario mín. 3 caracteres.', false); return; }
    if (!password || password.length < 4) { alerta('Contraseña mín. 4 caracteres.', false); return; }
    try {
        const d = await fetch(API_USERS, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, password, role }),
        }).then(r => r.json());
        if (!d.ok) throw new Error(d.error);
        alerta(`✔ Usuario "${username}" creado.`, true);
        document.getElementById('new-username').value = '';
        document.getElementById('new-password').value = '';
        cargarUsuarios();
    } catch (e) { alerta('Error: ' + e.message, false); }
}

async function editarUsuario() {
    const id       = parseInt(document.getElementById('edit-id').value);
    const password = document.getElementById('edit-password').value;
    const role     = document.getElementById('edit-role').value;
    if (!id || id < 1) { alerta('ID inválido.', false); return; }
    if (!password && !role) { alerta('Indica contraseña y/o rol.', false); return; }
    if (password && password.length < 4) { alerta('Contraseña mín. 4 caracteres.', false); return; }
    const body = {};
    if (password) body.password = password;
    if (role)     body.role     = role;
    try {
        const d = await fetch(`${API_USERS}?id=${id}`, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        }).then(r => r.json());
        if (!d.ok) throw new Error(d.error);
        alerta(`✔ Usuario #${id} actualizado.`, true);
        document.getElementById('edit-id').value = '';
        document.getElementById('edit-password').value = '';
        document.getElementById('edit-role').value = '';
        cargarUsuarios();
    } catch (e) { alerta('Error: ' + e.message, false); }
}

async function eliminarUsuario(id, nombre) {
    if (!confirm(`¿Eliminar usuario "${nombre}" (ID: ${id})?`)) return;
    try {
        const d = await fetch(`${API_USERS}?id=${id}`, { method: 'DELETE' }).then(r => r.json());
        if (!d.ok) throw new Error(d.error);
        alerta(`✔ Usuario "${nombre}" eliminado.`, true);
        cargarUsuarios();
    } catch (e) { alerta('Error: ' + e.message, false); }
}

// ── PLANOS ══════════════════════════════════════════════════════
async function cargarPlanos() {
    try {
        const d = await fetch(API_PLANES).then(r => r.json());
        if (!d.ok) throw new Error(d.error);
        allPlans = d.plans;
        const tbody = document.getElementById('tbody-planos');
        tbody.innerHTML = '';
        if (!d.plans.length) {
            tbody.innerHTML = '<tr><td colspan="7" style="color:var(--text-dim);text-align:center;padding:20px;">Sin planos. Sube uno desde el panel principal.</td></tr>';
            return;
        }
        d.plans.forEach(p => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="color:var(--text-dim);">${p.id}</td>
                <td><strong>${esc(p.name)}</strong></td>
                <td style="color:var(--text-dim);">${esc(p.creator_name)}</td>
                <td style="font-size:.73rem;color:#64748b;font-family:monospace;">${esc(p.house_id.slice(0, 18))}…</td>
                <td><button class="btn-perm" onclick="abrirModalPermisos(${p.id},'${esc(p.name)}')" style="font-size:.76rem;">🔑 ${p.num_permisos ?? 0}</button></td>
                <td style="color:var(--text-dim);font-size:.8rem;">${p.updated_at ?? '—'}</td>
                <td><button class="btn-del" onclick="eliminarPlano(${p.id},'${esc(p.name)}')">Eliminar</button></td>`;
            tbody.appendChild(tr);
        });
    } catch (e) { alerta('Error cargando planos: ' + e.message, false); }
}

async function eliminarPlano(id, nombre) {
    if (!confirm(`¿Eliminar plano "${nombre}" (ID: ${id})?`)) return;
    try {
        const d = await fetch(`${API_PLANES}?id=${id}`, { method: 'DELETE' }).then(r => r.json());
        if (!d.ok) throw new Error(d.error);
        alerta(`✔ Plano "${nombre}" eliminado.`, true);
        cargarPlanos();
    } catch (e) { alerta('Error: ' + e.message, false); }
}

// ── PERMISOS ════════════════════════════════════════════════════
async function cargarSelectsPermisos() {
    try {
        const [du, dp] = await Promise.all([
            fetch(API_USERS).then(r => r.json()),
            fetch(API_PLANES).then(r => r.json()),
        ]);
        if (du.ok) {
            allUsers = du.users;
            ['perm-user', 'modal-user-select'].forEach(id => {
                const sel = document.getElementById(id);
                if (!sel) return;
                const cur = sel.value;
                sel.innerHTML = '<option value="">— Usuario —</option>';
                du.users.forEach(u => {
                    sel.innerHTML += `<option value="${u.id}">${esc(u.username)} (${u.role})</option>`;
                });
                sel.value = cur;
            });
        }
        if (dp.ok) {
            allPlans = dp.plans;
            const sel = document.getElementById('perm-plan');
            sel.innerHTML = '<option value="">— Plano —</option>';
            dp.plans.forEach(p => {
                sel.innerHTML += `<option value="${p.id}">${esc(p.name)}</option>`;
            });
        }
    } catch (e) { alerta('Error: ' + e.message, false); }
}

async function cargarPermisosActivos() {
    try {
        const d = await fetch(API_PERMS).then(r => r.json());
        if (!d.ok) throw new Error(d.error);
        const tbody = document.getElementById('tbody-permisos');
        tbody.innerHTML = '';
        if (!d.permissions.length) {
            tbody.innerHTML = '<tr><td colspan="6" style="color:var(--text-dim);text-align:center;padding:20px;">Sin permisos asignados.</td></tr>';
            return;
        }
        d.permissions.forEach(p => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><strong>${esc(p.username)}</strong></td>
                <td>${rolBadge(p.role)}</td>
                <td>${esc(p.plan_name)}</td>
                <td>${permBadge(p.perm)}</td>
                <td style="color:var(--text-dim);font-size:.8rem;">${p.granted_at ?? '—'}</td>
                <td><button class="btn-del" style="font-size:.76rem;"
                    onclick="revocarPermiso(${p.user_id},${p.plan_id},'${esc(p.username)}','${esc(p.plan_name)}')">Revocar</button></td>`;
            tbody.appendChild(tr);
        });
    } catch (e) { alerta('Error: ' + e.message, false); }
}

async function otorgarPermiso() {
    const userId = parseInt(document.getElementById('perm-user').value);
    const planId = parseInt(document.getElementById('perm-plan').value);
    const perm   = document.getElementById('perm-nivel').value;
    if (!userId || !planId) { alerta('Selecciona usuario y plano.', false); return; }
    try {
        const d = await fetch(API_PERMS, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: userId, plan_id: planId, perm }),
        }).then(r => r.json());
        if (!d.ok) throw new Error(d.error);
        alerta('✔ Permiso asignado.', true);
        cargarPermisosActivos();
    } catch (e) { alerta('Error: ' + e.message, false); }
}

async function revocarPermiso(userId, planId, uNom, pNom) {
    if (!confirm(`¿Revocar acceso de "${uNom}" al plano "${pNom}"?`)) return;
    try {
        const d = await fetch(`${API_PERMS}?user_id=${userId}&plan_id=${planId}`, { method: 'DELETE' }).then(r => r.json());
        if (!d.ok) throw new Error(d.error);
        alerta('✔ Permiso revocado.', true);
        cargarPermisosActivos();
        if (modalPlanId) cargarPermisosModal(modalPlanId);
    } catch (e) { alerta('Error: ' + e.message, false); }
}

// ── Modal permisos por plano ─────────────────────────────────────
async function abrirModalPermisos(planId, planName) {
    modalPlanId = planId;
    document.getElementById('modal-plan-nombre').textContent = planName;
    document.getElementById('modal-permisos').classList.add('open');
    await cargarSelectsPermisos();
    await cargarPermisosModal(planId);
}

function cerrarModal() {
    document.getElementById('modal-permisos').classList.remove('open');
    modalPlanId = null;
}

async function cargarPermisosModal(planId) {
    try {
        const d = await fetch(`${API_PERMS}?plan_id=${planId}`).then(r => r.json());
        if (!d.ok) throw new Error(d.error);
        const container = document.getElementById('modal-lista-permisos');
        if (!d.permissions.length) {
            container.innerHTML = '<p style="color:var(--text-dim);font-size:.85rem;">Sin usuarios asignados a este plano.</p>';
            return;
        }
        container.innerHTML = '<table class="tbl"><thead><tr><th>Usuario</th><th>Rol</th><th>Nivel</th><th>Asignado</th><th></th></tr></thead><tbody>' +
            d.permissions.map(p => `
                <tr>
                    <td>${esc(p.username)}</td>
                    <td>${rolBadge(p.role)}</td>
                    <td>${permBadge(p.perm)}</td>
                    <td style="color:var(--text-dim);font-size:.78rem;">${p.granted_at ?? '—'}</td>
                    <td><button class="btn-del" style="font-size:.74rem;"
                        onclick="revocarPermiso(${p.id},${planId},'${esc(p.username)}','')">Revocar</button></td>
                </tr>`).join('') +
            '</tbody></table>';
    } catch (e) {
        document.getElementById('modal-lista-permisos').innerHTML = `<p style="color:#ef4444;">${e.message}</p>`;
    }
}

async function otorgarPermisoModal() {
    if (!modalPlanId) return;
    const userId = parseInt(document.getElementById('modal-user-select').value);
    const perm   = document.getElementById('modal-perm-nivel').value;
    if (!userId) { alerta('Selecciona un usuario.', false); return; }
    try {
        const d = await fetch(API_PERMS, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: userId, plan_id: modalPlanId, perm }),
        }).then(r => r.json());
        if (!d.ok) throw new Error(d.error);
        alerta('✔ Permiso asignado.', true);
        cargarPermisosModal(modalPlanId);
        cargarPlanos();
    } catch (e) { alerta('Error: ' + e.message, false); }
}

// ── AUDITORÍA ════════════════════════════════════════════════════
async function cargarAuditoria() {
    try {
        const r = await fetch('api_audit.php?limit=100');
        const d = await r.json();
        if (!d.ok) throw new Error(d.error);
        const tbody = document.getElementById('tbody-auditoria');
        tbody.innerHTML = '';
        if (!d.events.length) {
            tbody.innerHTML = '<tr><td colspan="6" style="color:var(--text-dim);text-align:center;padding:20px;">Sin eventos de auditoría.</td></tr>';
            return;
        }
        d.events.forEach(e => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="color:var(--text-dim);font-size:.8rem;">${e.ts}</td>
                <td><strong>${esc(e.actor)}</strong></td>
                <td><code style="font-size:.78rem;color:#4ade80;">${esc(e.action)}</code></td>
                <td style="font-size:.8rem;">${esc(e.target ?? '—')}</td>
                <td style="color:var(--text-dim);font-size:.78rem;">${esc(e.detail ?? '—')}</td>
                <td style="color:var(--text-dim);font-size:.78rem;font-family:monospace;">${esc(e.ip ?? '—')}</td>`;
            tbody.appendChild(tr);
        });
    } catch (e) { alerta('Error cargando auditoría: ' + e.message, false); }
}

// ── Helpers ──────────────────────────────────────────────────────
function alerta(msg, ok) {
    const el = document.getElementById('alert-box');
    el.textContent  = msg;
    el.className    = 'alert ' + (ok ? 'alert-ok' : 'alert-err');
    el.style.display = 'block';
    clearTimeout(el._timer);
    el._timer = setTimeout(() => { el.style.display = 'none'; }, 5000);
}

function esc(str) {
    return String(str).replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

document.getElementById('modal-permisos').addEventListener('click', function (e) {
    if (e.target === this) cerrarModal();
});

cargarUsuarios();
</script>
</body>
</html>
