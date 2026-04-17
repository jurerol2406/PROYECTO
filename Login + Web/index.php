<?php
session_start();

// Si la variable de sesión 'usuario' no existe, lo mandamos al login
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartHome - Panel de Control ASIR</title>
    <link rel="stylesheet" href="smarthome.css">
</head>

<body>
    <div class="dashboard">
        <header class="header">
            <div class="logo">🏠 SmartHome <span>Sistema de Domótica</span></div>
            <div class="user-info" style="color: var(--accent-color); font-size: 0.9rem; margin-left: auto; margin-right: 20px;">
                Bienvenido, <strong><?php echo htmlspecialchars($_SESSION['usuario']); ?></strong> | 
                <a href="logout.php" style="color: #ef4444; text-decoration: none; font-weight: bold;">Cerrar Sesión</a>
            </div>
            <div class="status" id="header-status">🟢 0/0 puertas protegidas</div>
        </header>

        <main class="main-content">
            <section class="blueprint-section">
                <h2>Plano Interactivo</h2>
                <div id="canvas-container" class="canvas-container">
                    <p class="placeholder-text">Pega el JSON para generar el plano...</p>
                </div>
            </section>

            <aside class="sidebar">
                <div class="card">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <h3>🔓 Candados Disponibles</h3>
                        <div>
                            <button id="btn-add-lock" title="Añadir candado"
                                style="cursor:pointer; background:#10b981; border:none; color:white; border-radius:4px; padding:2px 8px;">+</button>
                            <button id="btn-remove-lock" title="Quitar candado"
                                style="cursor:pointer; background:#ef4444; border:none; color:white; border-radius:4px; padding:2px 9px;">-</button>
                        </div>
                    </div>
                    <div class="lock-pool" id="lock-pool">
                    </div>
                </div>

                <div id="panel-control-detallado" class="card" style="display:none;">
                    <h3>🖥️ Control de Candados</h3>
                    <div id="lista-dispositivos" style="display: flex; flex-direction: column; gap: 10px;"></div>
                </div>

                <div class="card">
                    <h3>📄 Configuración JSON</h3>
                    <textarea id="json-input" placeholder="Pega aquí el código de la API..."></textarea>
                    <button id="btn-generate" class="btn-primary">Generar Plano</button>
                </div>
            </aside>
        </main>

        <footer class="stats-bar">
            <div class="stat-card">
                <h4>Habitaciones</h4>
                <p id="count-rooms">0</p>
            </div>
            <div class="stat-card">
                <h4>Puertas</h4>
                <p id="count-doors">0</p>
            </div>
            <div class="stat-card highlight">
                <h4>Asignados</h4>
                <p id="count-assigned">0</p>
            </div>
            <div class="stat-card">
                <h4>Disponibles</h4>
                <p id="count-available">0</p>
            </div>
        </footer>
    </div>
    <script src="smarthome.js"></script>
</body>

</html>
