<?php
/**
 * api_audit.php
 *
 * GET  ?limit=N  → últimos N eventos de auditoría   [solo admin]
 */

require_once 'auth.php';
require_once 'conexion.php';

header('Content-Type: application/json; charset=utf-8');

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit();
}

$limit = max(1, min((int) ($_GET['limit'] ?? 100), 1000));

try {
    $stmt = $conexion->prepare(
        "SELECT id, actor, action, target, detail, ip, ts
           FROM audit_events
          ORDER BY ts DESC
          LIMIT ?"
    );
    $stmt->execute([$limit]);
    echo json_encode(['ok' => true, 'events' => $stmt->fetchAll()]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
