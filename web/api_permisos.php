<?php
/**
 * api_permisos.php
 *
 * GET    ?plan_id=N          → usuarios con permiso en ese plano
 * GET    ?user_id=N          → planos accesibles para ese usuario
 * GET    (sin filtro)        → todos los permisos
 * POST   {user_id,plan_id,perm}  → otorgar / actualizar permiso
 * DELETE ?user_id=N&plan_id=N    → revocar permiso
 *
 * Todos los endpoints requieren rol admin.
 */

require_once 'auth.php';
require_once 'conexion.php';

header('Content-Type: application/json; charset=utf-8');

requireAdmin();

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────────────────────
if ($method === 'GET') {
    $planId = isset($_GET['plan_id']) ? (int) $_GET['plan_id'] : 0;
    $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;

    if ($planId > 0) {
        $stmt = $conexion->prepare(
            "SELECT u.id, u.username, u.role, pp.perm, pp.granted_at
               FROM user_plan_permissions pp
               JOIN users u ON u.id = pp.user_id
              WHERE pp.plan_id = ?
              ORDER BY u.username"
        );
        $stmt->execute([$planId]);
        echo json_encode(['ok' => true, 'permissions' => $stmt->fetchAll()]);
        exit();
    }

    if ($userId > 0) {
        $stmt = $conexion->prepare(
            "SELECT p.id, p.house_id, p.name, p.created_at, pp.perm
               FROM user_plan_permissions pp
               JOIN plans p ON p.id = pp.plan_id
              WHERE pp.user_id = ?
              ORDER BY p.updated_at DESC"
        );
        $stmt->execute([$userId]);
        echo json_encode(['ok' => true, 'plans' => $stmt->fetchAll()]);
        exit();
    }

    $rows = $conexion->query(
        "SELECT pp.id, u.id AS user_id, u.username, u.role,
                p.id AS plan_id, p.name AS plan_name, p.house_id,
                pp.perm, pp.granted_at
           FROM user_plan_permissions pp
           JOIN users u ON u.id = pp.user_id
           JOIN plans p ON p.id = pp.plan_id
          ORDER BY p.name, u.username"
    )->fetchAll();
    echo json_encode(['ok' => true, 'permissions' => $rows]);
    exit();
}

// ── POST: otorgar / actualizar ───────────────────────────────────
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true);
    $userId = (int) ($body['user_id'] ?? 0);
    $planId = (int) ($body['plan_id'] ?? 0);
    $perm   = in_array($body['perm'] ?? '', ['view', 'interact'], true) ? $body['perm'] : 'interact';

    if ($userId < 1 || $planId < 1) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'user_id y plan_id son obligatorios.']);
        exit();
    }

    $u = $conexion->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
    $u->execute([$userId]);
    if (!$u->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Usuario no encontrado.']);
        exit();
    }

    $p = $conexion->prepare("SELECT id FROM plans WHERE id = ? LIMIT 1");
    $p->execute([$planId]);
    if (!$p->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Plano no encontrado.']);
        exit();
    }

    $conexion->prepare(
        "INSERT INTO user_plan_permissions (user_id, plan_id, perm)
              VALUES (?, ?, ?)
         ON CONFLICT(user_id, plan_id) DO UPDATE SET perm = excluded.perm, granted_at = CURRENT_TIMESTAMP"
    )->execute([$userId, $planId, $perm]);

    audit('PERM_ASIGNAR', "user={$userId} plan={$planId}", "perm={$perm}");
    echo json_encode(['ok' => true, 'user_id' => $userId, 'plan_id' => $planId, 'perm' => $perm]);
    exit();
}

// ── DELETE: revocar ──────────────────────────────────────────────
if ($method === 'DELETE') {
    $userId = (int) ($_GET['user_id'] ?? 0);
    $planId = (int) ($_GET['plan_id'] ?? 0);

    if ($userId < 1 || $planId < 1) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'user_id y plan_id son obligatorios.']);
        exit();
    }

    $stmt = $conexion->prepare(
        "DELETE FROM user_plan_permissions WHERE user_id = ? AND plan_id = ?"
    );
    $stmt->execute([$userId, $planId]);

    audit('PERM_REVOCAR', "user={$userId} plan={$planId}");
    echo json_encode(['ok' => true, 'deleted' => $stmt->rowCount()]);
    exit();
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
