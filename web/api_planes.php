<?php
/**
 * api_planes.php
 *
 * GET    /api_planes.php           → lista planos accesibles al usuario
 * GET    /api_planes.php?id=N      → plano concreto (con perm para no-admins)
 * POST   /api_planes.php           → crear/actualizar plano  [admin]
 * DELETE /api_planes.php?id=N      → eliminar plano          [admin]
 */

require_once 'auth.php';
require_once 'conexion.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$userId = (int) ($_SESSION['user_id'] ?? 0);

// ── GET ──────────────────────────────────────────────────────────
if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($id > 0) {
        if (isAdmin()) {
            $stmt = $conexion->prepare(
                "SELECT p.*, u.username AS creator_name
                   FROM plans p
                   JOIN users u ON u.id = p.creator_id
                  WHERE p.id = ?"
            );
            $stmt->execute([$id]);
            $plan = $stmt->fetch();
            if ($plan) $plan['perm'] = 'interact'; // admin siempre puede interactuar
        } else {
            $stmt = $conexion->prepare(
                "SELECT p.*, u.username AS creator_name, pp.perm
                   FROM plans p
                   JOIN users u ON u.id = p.creator_id
                   JOIN user_plan_permissions pp ON pp.plan_id = p.id AND pp.user_id = ?
                  WHERE p.id = ?"
            );
            $stmt->execute([$userId, $id]);
            $plan = $stmt->fetch();
        }

        if (!$plan) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Plano no encontrado o sin acceso.']);
            exit();
        }

        $plan['json_data'] = json_decode($plan['json_data'], true);
        echo json_encode(['ok' => true, 'plan' => $plan]);
        exit();
    }

    // Lista
    if (isAdmin()) {
        $rows = $conexion->query(
            "SELECT p.id, p.house_id, p.name, p.creator_id, p.created_at, p.updated_at,
                    u.username AS creator_name,
                    (SELECT COUNT(*) FROM user_plan_permissions WHERE plan_id = p.id) AS num_permisos
               FROM plans p
               JOIN users u ON u.id = p.creator_id
              ORDER BY p.updated_at DESC"
        )->fetchAll();
    } else {
        $stmt = $conexion->prepare(
            "SELECT p.id, p.house_id, p.name, p.creator_id, p.created_at, p.updated_at,
                    u.username AS creator_name, pp.perm
               FROM plans p
               JOIN users u ON u.id = p.creator_id
               JOIN user_plan_permissions pp ON pp.plan_id = p.id AND pp.user_id = ?
              ORDER BY p.updated_at DESC"
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
    }

    echo json_encode(['ok' => true, 'plans' => $rows]);
    exit();
}

// ── POST: crear / actualizar ─────────────────────────────────────
if ($method === 'POST') {
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Solo admins pueden guardar planos.']);
        exit();
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'JSON inválido.']);
        exit();
    }

    $houseId  = trim($body['house_id'] ?? '');
    $name     = trim($body['name']     ?? '');
    $jsonData = $body['json_data']      ?? null;

    if ($houseId === '' || $name === '' || $jsonData === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Faltan campos: house_id, name, json_data.']);
        exit();
    }

    $jsonStr  = is_string($jsonData) ? $jsonData : json_encode($jsonData);
    $existing = $conexion->prepare("SELECT id FROM plans WHERE house_id = ? LIMIT 1");
    $existing->execute([$houseId]);
    $row = $existing->fetch();

    if ($row) {
        $conexion->prepare(
            "UPDATE plans SET name = ?, json_data = ?, updated_at = CURRENT_TIMESTAMP WHERE house_id = ?"
        )->execute([$name, $jsonStr, $houseId]);
        $planId = (int) $row['id'];
        $action = 'updated';
    } else {
        $conexion->prepare(
            "INSERT INTO plans (house_id, name, json_data, creator_id) VALUES (?, ?, ?, ?)"
        )->execute([$houseId, $name, $jsonStr, $userId]);
        $planId = (int) $conexion->lastInsertId();
        $action = 'created';

        $conexion->prepare(
            "INSERT OR IGNORE INTO user_plan_permissions (user_id, plan_id, perm) VALUES (?, ?, 'interact')"
        )->execute([$userId, $planId]);
    }

    audit($action === 'created' ? 'PLAN_CREAR' : 'PLAN_ACTUALIZAR', $houseId, $name);
    echo json_encode(['ok' => true, 'action' => $action, 'plan_id' => $planId, 'house_id' => $houseId]);
    exit();
}

// ── DELETE ───────────────────────────────────────────────────────
if ($method === 'DELETE') {
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Solo admins pueden eliminar planos.']);
        exit();
    }

    $id = (int) ($_GET['id'] ?? 0);
    if ($id < 1) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'ID inválido.']);
        exit();
    }

    $chk = $conexion->prepare("SELECT house_id FROM plans WHERE id = ? LIMIT 1");
    $chk->execute([$id]);
    $plan = $chk->fetch();
    if (!$plan) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Plano no encontrado.']);
        exit();
    }

    $conexion->prepare("DELETE FROM plans WHERE id = ?")->execute([$id]);
    audit('PLAN_ELIMINAR', $plan['house_id']);
    echo json_encode(['ok' => true, 'deleted_house_id' => $plan['house_id']]);
    exit();
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
