<?php
/**
 * save_plan.php
 * POST { house_id, name, json_data } → guarda o actualiza plano [solo admin]
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado.']);
    exit();
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solo administradores pueden guardar planos.']);
    exit();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit();
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Body no es JSON válido.']);
    exit();
}

$houseId  = trim($body['house_id'] ?? '');
$name     = trim($body['name']     ?? '');
$jsonData = $body['json_data']      ?? null;

if ($houseId === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Campo house_id vacío.']); exit(); }
if ($name    === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Campo name vacío.']);    exit(); }
if ($jsonData === null) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Campo json_data ausente.']); exit(); }

$jsonStr = is_string($jsonData) ? $jsonData : json_encode($jsonData, JSON_UNESCAPED_UNICODE);

$dbDir  = '/var/lib/smarthome';
$dbPath = $dbDir . '/smarthome.db';

if (!is_dir($dbDir)) {
    if (!@mkdir($dbDir, 0775, true)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => "No se pudo crear directorio: {$dbDir}"]);
        exit();
    }
}

try {
    $pdo = new PDO("sqlite:{$dbPath}", null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec('PRAGMA journal_mode=WAL;');
    $pdo->exec('PRAGMA synchronous=NORMAL;');
    $pdo->exec('PRAGMA foreign_keys=ON;');

    $pdo->exec("
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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_plan_permissions (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            plan_id    INTEGER NOT NULL,
            perm       TEXT    NOT NULL DEFAULT 'interact',
            granted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (user_id, plan_id)
        )
    ");

    $check = $pdo->prepare("SELECT id FROM plans WHERE house_id = ? LIMIT 1");
    $check->execute([$houseId]);
    $existing = $check->fetch();

    if ($existing) {
        $pdo->prepare(
            "UPDATE plans SET name = ?, json_data = ?, updated_at = CURRENT_TIMESTAMP WHERE house_id = ?"
        )->execute([$name, $jsonStr, $houseId]);
        $planId = (int) $existing['id'];
        $action = 'updated';
    } else {
        $pdo->prepare(
            "INSERT INTO plans (house_id, name, json_data, creator_id) VALUES (?, ?, ?, ?)"
        )->execute([$houseId, $name, $jsonStr, $userId ?: 1]);
        $planId = (int) $pdo->lastInsertId();
        $action = 'created';

        if ($userId > 0) {
            $pdo->prepare(
                "INSERT OR IGNORE INTO user_plan_permissions (user_id, plan_id, perm) VALUES (?, ?, 'interact')"
            )->execute([$userId, $planId]);
        }
    }

    echo json_encode([
        'ok'       => true,
        'action'   => $action,
        'plan_id'  => $planId,
        'house_id' => $houseId,
        'name'     => $name,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error PDO: ' . $e->getMessage()]);
}
