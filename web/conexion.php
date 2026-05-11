<?php
define('DB_PATH', '/var/lib/smarthome/smarthome.db');
define('DB_DIR',  '/var/lib/smarthome');

if (!is_dir(DB_DIR)) {
    @mkdir(DB_DIR, 0775, true);
}

try {
    $conexion = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $conexion->exec('PRAGMA journal_mode=WAL;');
    $conexion->exec('PRAGMA synchronous=NORMAL;');
    $conexion->exec('PRAGMA foreign_keys=ON;');

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER  PRIMARY KEY AUTOINCREMENT,
            username      TEXT     UNIQUE NOT NULL COLLATE NOCASE,
            password_hash TEXT     NOT NULL,
            role          TEXT     NOT NULL DEFAULT 'user'
                          CHECK (role IN ('admin','user','viewer')),
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS Logs (
            id       INTEGER  PRIMARY KEY AUTOINCREMENT,
            house_id TEXT     NOT NULL,
            lock_id  TEXT     NOT NULL,
            accion   TEXT     NOT NULL,
            usuario  TEXT     DEFAULT 'sistema',
            ts       DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS plans (
            id           INTEGER  PRIMARY KEY AUTOINCREMENT,
            house_id     TEXT     UNIQUE NOT NULL,
            name         TEXT     NOT NULL,
            json_data    TEXT     NOT NULL,
            creator_id   INTEGER  NOT NULL DEFAULT 1,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS user_plan_permissions (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            plan_id    INTEGER NOT NULL,
            perm       TEXT    NOT NULL DEFAULT 'interact'
                       CHECK (perm IN ('view','interact')),
            granted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (user_id, plan_id)
        )
    ");

    // Tabla de auditoría para acciones de administración
    $conexion->exec("
        CREATE TABLE IF NOT EXISTS audit_events (
            id         INTEGER  PRIMARY KEY AUTOINCREMENT,
            actor      TEXT     NOT NULL,
            action     TEXT     NOT NULL,
            target     TEXT,
            detail     TEXT,
            ip         TEXT,
            ts         DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Usuarios por defecto (solo si la tabla está vacía)
    $count = (int) $conexion->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count === 0) {
        $stmt = $conexion->prepare(
            "INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)"
        );
        $stmt->execute(['admin',  password_hash('admin123', PASSWORD_BCRYPT, ['cost' => 12]), 'admin']);
        $stmt->execute(['user',   password_hash('user123',  PASSWORD_BCRYPT, ['cost' => 12]), 'user']);
        $stmt->execute(['visor',  password_hash('visor123', PASSWORD_BCRYPT, ['cost' => 12]), 'viewer']);
    }

    // Migración: añadir columna si existe una BD anterior con CHECK distinto
    // (SQLite no permite ALTER COLUMN, pero el CHECK no se puede actualizar sin recrear)

} catch (PDOException $e) {
    $isApi = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
          || in_array(basename($_SERVER['SCRIPT_FILENAME'] ?? ''), [
                'api_logs.php','api_usuarios.php','api_planes.php',
                'api_permisos.php','save_plan.php',
             ]);

    header('Content-Type: ' . ($isApi ? 'application/json' : 'text/html') . '; charset=utf-8');
    http_response_code(500);

    if ($isApi) {
        echo json_encode(['ok' => false, 'error' => 'Error BD: ' . $e->getMessage()]);
    } else {
        echo '<!DOCTYPE html><html><body style="font-family:monospace;background:#0f172a;color:#ef4444;padding:40px">';
        echo '<h2>Error crítico de base de datos</h2>';
        echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<p>Ruta: ' . DB_PATH . '</p>';
        echo '</body></html>';
    }
    exit();
}

/**
 * Registra un evento de auditoría de administración.
 */
function audit(string $action, string $target = '', string $detail = ''): void
{
    global $conexion;
    $actor = $_SESSION['username'] ?? 'sistema';
    $ip    = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    try {
        $conexion->prepare(
            "INSERT INTO audit_events (actor, action, target, detail, ip) VALUES (?, ?, ?, ?, ?)"
        )->execute([$actor, $action, $target, $detail, $ip]);
    } catch (Throwable) {}
}
?>
