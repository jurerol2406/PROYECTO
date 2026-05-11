<?php
require_once 'auth.php';
requireLogin();
require_once 'conexion.php';

$rolLabel  = match($auth_role) {
    'admin'  => '⚡ Admin',
    'viewer' => '👁 Solo Ver',
    default  => '👷 Usuario',
};
$rolBadge = "badge-{$auth_role}";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CerradurasIoT – Panel</title>
    <link rel="stylesheet" href="smarthome.css">
    <script src="https://unpkg.com/mqtt@5.3.4/dist/mqtt.min.js"></script>
    <script>
        window.CURRENT_USER  = <?= json_encode($auth_user) ?>;
        window.CURRENT_ROLE  = <?= json_encode($auth_role) ?>;
        window.ES_ADMIN      = <?= $is_admin  ? 'true' : 'false' ?>;
        window.ES_VISOR      = <?= $is_viewer ? 'true' : 'false' ?>;
        // Para admin siempre true; viewer siempre false; user se actualiza al cargar plano
        window.INTERACTUAR_INICIAL = <?= ($is_admin ? 'true' : ($is_viewer ? 'false' : 'false')) ?>;
    </script>
    <style>
        #btn-guardar-plano {
            display: none;
            background: #f59e0b;
            color: #000;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: bold;
            font-size: .88rem;
            cursor: pointer;
            animation: pulse-btn 1.8s infinite;
            white-space: nowrap;
        }
        #btn-guardar-plano:hover  { background: #d97706; color: white; animation: none; }
        #btn-guardar-plano.saving { opacity: .6; cursor: not-allowed; animation: none; }
        #btn-guardar-plano.saved  { background: #10b981; color: white; animation: none; }
        @keyframes pulse-btn {
            0%,100% { box-shadow: 0 0 0 0 rgba(245,158,11,.6); }
            50%      { box-shadow: 0 0 0 6px rgba(245,158,11,0); }
        }
        .save-feedback {
            font-size: .8rem;
            padding: 4px 10px;
            border-radius: 5px;
            display: none;
        }
        .save-feedback.ok  { background: rgba(16,185,129,.15); color: #10b981; }
        .save-feedback.err { background: rgba(239,68,68,.15);  color: #ef4444; }

        .viewer-notice {
            background: rgba(245,158,11,.08);
            border: 1px solid rgba(245,158,11,.3);
            border-left: 4px solid #f59e0b;
            color: #fbbf24;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: .85rem;
        }
    </style>
</head>
<body>
<div class="dashboard">

    <header class="header">
        <div class="logo">🏢 Cerraduras IoT <span>Control de Accesos</span></div>

        <div style="display:flex;gap:10px;align-items:center;margin-left:auto;flex-wrap:wrap;">
            <span style="color:var(--text-dim);font-size:.85rem;">
                👤 <strong><?= htmlspecialchars($auth_user, ENT_QUOTES) ?></strong>
                <span class="badge-rol <?= $rolBadge ?>"><?= $rolLabel ?></span>
            </span>
            <?php if ($is_admin): ?>
                <a href="admin.php" class="btn-sm btn-purple">👥 Gestión</a>
            <?php endif; ?>
            <button class="btn-sm btn-teal" onclick="toggleLogs()" id="btn-logs">📋 Logs</button>
            <a href="logout.php" class="btn-sm btn-red">🚪 Salir</a>
        </div>

        <div id="mqtt-badge">🔴 MQTT</div>
        <div class="status" id="header-status" style="margin-left:10px;">🟢 0/0 accesos protegidos</div>
    </header>

    <!-- Aviso visual modo solo-lectura -->
    <?php if ($is_viewer): ?>
    <div class="viewer-notice">
        👁 <strong>Modo visualización.</strong>
        Estás en modo solo lectura — puedes ver el estado de las cerraduras pero no operar sobre ellas.
    </div>
    <?php endif; ?>

    <!-- Barra superior: selector + subida (solo admin) + guardar -->
    <div class="upload-panel">
        <label style="white-space:nowrap;">📂 Plano:</label>
        <select id="select-plan" onchange="cargarPlanSeleccionado()"
            style="background:#0f172a;border:1px solid #334155;color:white;
                   padding:8px 12px;border-radius:6px;font-size:.85rem;min-width:200px;flex:1;max-width:280px;">
            <option value="">— Sin plano —</option>
        </select>
        <button class="btn-sm btn-teal" onclick="refrescarListaPlanes()" title="Actualizar lista">🔄</button>

        <?php if ($is_admin): ?>
        <span style="color:#334155;margin:0 2px;">│</span>
        <label style="white-space:nowrap;">📐 Subir:</label>
        <input type="file" id="plano-file" accept=".jpg,.jpeg,.png"
               style="flex:1;min-width:140px;max-width:200px;background:#0f172a;border:1px solid #334155;
                      color:white;padding:6px 8px;border-radius:6px;font-size:.82rem;">
        <input type="text" id="empresa-nombre" placeholder="Nombre empresa" value="Mi Empresa"
               style="background:#0f172a;border:1px solid #334155;color:white;padding:8px 10px;
                      border-radius:6px;font-size:.82rem;width:140px;">
        <button class="btn-upload" id="btn-analizar" onclick="analizarPlanoDesdeArchivo()">
            🔍 Analizar
        </button>
        <div class="spinner" id="spinner-upload"></div>
        <button id="btn-guardar-plano" onclick="guardarPlanActual()">💾 Guardar plano</button>
        <span id="save-feedback" class="save-feedback"></span>
        <?php endif; ?>

        <span id="upload-msg" style="font-size:.82rem;color:var(--text-dim);white-space:nowrap;"></span>
    </div>

    <!-- Panel de logs -->
    <div id="panel-logs" class="card" style="display:none;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <h3>📋 Registro de Actividad</h3>
            <div style="display:flex;gap:8px;">
                <button onclick="cargarLogsDesdeDB()" class="btn-sm btn-teal">🔄</button>
                <button onclick="limpiarLogsUI()" class="btn-sm btn-gray">🗑</button>
                <?php if ($is_admin): ?>
                <button onclick="exportarLogs()" class="btn-sm btn-purple">⬇️ CSV</button>
                <?php endif; ?>
            </div>
        </div>
        <div id="logs-container" style="
            background:#020617;border-radius:8px;padding:12px;height:200px;
            overflow-y:auto;font-family:monospace;font-size:.78rem;color:#4ade80;border:1px solid #1e293b;">
            <div style="color:#475569;">Eventos en tiempo real…</div>
        </div>
    </div>

    <main class="main-content">

        <section class="blueprint-section">
            <h2>Plano Interactivo
                <span id="plan-title-badge"
                      style="font-size:.75rem;color:var(--text-dim);font-weight:normal;margin-left:8px;"></span>
            </h2>
            <div id="canvas-container" class="canvas-container">
                <p class="placeholder-text">
                    <?php if ($is_admin): ?>
                        📐 Selecciona un plano guardado o analiza uno nuevo…
                    <?php elseif ($is_viewer): ?>
                        👁 Selecciona un plano para visualizarlo…
                    <?php else: ?>
                        📂 Selecciona un plano autorizado para empezar…
                    <?php endif; ?>
                </p>
            </div>
        </section>

        <aside class="sidebar">

            <?php if ($is_admin): ?>
            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <h3>🔒 Cerraduras</h3>
                    <div>
                        <button id="btn-add-lock"
                            style="cursor:pointer;background:#10b981;border:none;color:white;
                                   border-radius:4px;padding:2px 8px;font-size:1rem;" title="Añadir">+</button>
                        <button id="btn-remove-lock"
                            style="cursor:pointer;background:#ef4444;border:none;color:white;
                                   border-radius:4px;padding:2px 9px;font-size:1rem;" title="Quitar">−</button>
                    </div>
                </div>
                <p style="color:var(--text-dim);font-size:.78rem;margin:0 0 8px 0;">
                    Arrastra 🔒 sobre una puerta del plano.
                </p>
                <div class="lock-pool" id="lock-pool"></div>
            </div>
            <?php endif; ?>

            <div id="panel-control-detallado" class="card" style="display:none;">
                <h3>🖥️ Control de Accesos</h3>
                <?php if ($is_viewer): ?>
                <p style="color:#fbbf24;font-size:.8rem;margin:0 0 10px;">
                    👁 Modo solo visualización — sin permisos de operación
                </p>
                <?php endif; ?>
                <div id="lista-dispositivos" style="display:flex;flex-direction:column;gap:8px;"></div>
            </div>

            <?php if ($is_admin): ?>
            <div class="card">
                <h3>📄 JSON Manual</h3>
                <textarea id="json-input"
                    placeholder='Pega JSON de la API…&#10;{"name":"Empresa","rooms":[…]}'></textarea>
                <button id="btn-generate" class="btn-primary">⚙️ Generar desde JSON</button>
            </div>
            <?php else: ?>
            <div class="card">
                <h3>ℹ️ Acceso</h3>
                <p style="color:var(--text-dim);font-size:.85rem;line-height:1.6;">
                    <?php if ($is_viewer): ?>
                        Estás en modo <strong>solo visualización</strong>. Puedes ver el estado de los accesos
                        asignados pero no operar sobre las cerraduras.
                    <?php else: ?>
                        Selecciona un plano del desplegable para interactuar con sus cerraduras.<br><br>
                        Solo ves los planos que un administrador te ha asignado.
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>

        </aside>
    </main>

    <footer class="stats-bar">
        <div class="stat-card"><h4>Departamentos</h4><p id="count-rooms">0</p></div>
        <div class="stat-card"><h4>Accesos</h4>      <p id="count-doors">0</p></div>
        <div class="stat-card highlight"><h4>Protegidos</h4><p id="count-assigned">0</p></div>
        <div class="stat-card"><h4>Disponibles</h4>  <p id="count-available">0</p></div>
        <div class="stat-card"><h4>ID Plano</h4>
            <p id="house-id-display"
               style="font-size:.6rem;word-break:break-all;color:var(--text-dim);">—</p>
        </div>
    </footer>

</div>
<script src="smarthome.js"></script>
</body>
</html>
