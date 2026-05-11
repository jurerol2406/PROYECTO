<?php
/**
 * api_logs.php
 *
 * GET  ?limit=N&house_id=X  → últimos N logs (filtrable por house_id)
 * POST JSON                  → insertar log (solo usuarios que pueden interactuar)
 */

require_once 'auth.php';
require_once 'conexion.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

// ── GET: obtener logs ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (empty($_SESSION['username'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'No autenticado.']);
        exit();
    }

    $limit    = max(1, min((int) ($_GET['limit'] ?? 100), 500));
    $house_id = trim($_GET['house_id'] ?? '');

    try {
        if ($house_id !== '') {
            $stmt = $conexion->prepare(
                "SELECT id, house_id, lock_id, accion, usuario, ts
                   FROM Logs WHERE house_id = ?
                   ORDER BY ts DESC LIMIT ?"
            );
            $stmt->execute([$house_id, $limit]);
        } else {
            $stmt = $conexion->prepare(
                "SELECT id, house_id, lock_id, accion, usuario, ts
                   FROM Logs ORDER BY ts DESC LIMIT ?"
            );
            $stmt->execute([$limit]);
        }
        echo json_encode(['ok' => true, 'logs' => $stmt->fetchAll()]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── POST: insertar log ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Viewers nunca pueden insertar logs de operación
    if (!empty($_SESSION['role']) && $_SESSION['role'] === 'viewer') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permiso denegado — rol visualizador.']);
        exit();
    }

    $body = json_decode(file_get_contents('php://input'), true);

    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'JSON inválido.']);
        exit();
    }

    $house_id = trim($body['house_id'] ?? '');
    $lock_id  = trim($body['lock_id']  ?? '');
    $accion   = trim($body['accion']   ?? '');
    $usuario  = trim($body['usuario']  ?? 'sistema');

    if ($house_id === '' || $lock_id === '' || $accion === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Faltan campos: house_id, lock_id, accion.']);
        exit();
    }

    // Si hay sesión activa de usuario, verificar que tiene permiso de interacción en ese plano
    if (!empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'user') {
        $userId = (int) $_SESSION['user_id'];
        $chk = $conexion->prepare(
            "SELECT pp.perm FROM user_plan_permissions pp
               JOIN plans p ON p.id = pp.plan_id
              WHERE pp.user_id = ? AND p.house_id = ? LIMIT 1"
        );
        $chk->execute([$userId, $house_id]);
        $row = $chk->fetch();
        if ($row && $row['perm'] === 'view') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Permiso de solo visualización — no puede operar cerraduras.']);
            exit();
        }
    }

    try {
        $stmt = $conexion->prepare(
            "INSERT INTO Logs (house_id, lock_id, accion, usuario) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$house_id, $lock_id, $accion, $usuario]);
        echo json_encode(['ok' => true, 'id' => (int) $conexion->lastInsertId()]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
