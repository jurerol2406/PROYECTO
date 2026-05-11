<?php
/**
 * api_usuarios.php
 *
 * GET    /api_usuarios.php        → listar usuarios       [admin]
 * POST   /api_usuarios.php        → crear usuario         [admin]
 * PUT    /api_usuarios.php?id=N   → actualizar usuario    [admin]
 * DELETE /api_usuarios.php?id=N   → eliminar usuario      [admin]
 */

require_once 'auth.php';
require_once 'conexion.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acceso denegado.']);
    exit();
}

$method      = $_SERVER['REQUEST_METHOD'];
$rolesValidos = ['admin', 'user', 'viewer'];

// ── GET ──────────────────────────────────────────────────────────
if ($method === 'GET') {
    try {
        $users = $conexion->query(
            "SELECT id, username, role, created_at FROM users ORDER BY id ASC"
        )->fetchAll();
        echo json_encode(['ok' => true, 'users' => $users]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── POST: crear ──────────────────────────────────────────────────
if ($method === 'POST') {
    $body     = json_decode(file_get_contents('php://input'), true);
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';
    $role     = in_array($body['role'] ?? '', $rolesValidos, true) ? $body['role'] : 'user';

    if ($username === '' || strlen($username) < 3 || strlen($username) > 30) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Username debe tener entre 3 y 30 caracteres.']);
        exit();
    }
    if ($password === '' || strlen($password) < 4) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Contraseña debe tener al menos 4 caracteres.']);
        exit();
    }

    try {
        $conexion->prepare(
            "INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)"
        )->execute([
            $username,
            password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            $role,
        ]);
        $id = (int) $conexion->lastInsertId();
        audit('USER_CREAR', $username, "rol={$role}");
        echo json_encode(['ok' => true, 'id' => $id]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => "El usuario '{$username}' ya existe."]);
        } else {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
    exit();
}

// ── PUT: actualizar ──────────────────────────────────────────────
if ($method === 'PUT') {
    $id   = (int) ($_GET['id'] ?? 0);
    $body = json_decode(file_get_contents('php://input'), true);

    if ($id < 1) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'ID inválido.']);
        exit();
    }

    $updates = [];
    $params  = [];

    if (!empty($body['password'])) {
        if (strlen($body['password']) < 4) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Contraseña debe tener al menos 4 caracteres.']);
            exit();
        }
        $updates[] = 'password_hash = ?';
        $params[]  = password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    }

    if (!empty($body['role']) && in_array($body['role'], $rolesValidos, true)) {
        $updates[] = 'role = ?';
        $params[]  = $body['role'];
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Nada que actualizar.']);
        exit();
    }

    $params[] = $id;
    try {
        $conexion->prepare(
            "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?"
        )->execute($params);
        audit('USER_EDITAR', "id={$id}");
        echo json_encode(['ok' => true, 'updated' => 1]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── DELETE ───────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id < 1) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'ID inválido.']);
        exit();
    }

    $chk = $conexion->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
    $chk->execute([$id]);
    $target = $chk->fetch();

    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Usuario no encontrado.']);
        exit();
    }
    if ($target['username'] === currentUser()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No puedes eliminar tu propia cuenta.']);
        exit();
    }

    try {
        $conexion->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        audit('USER_ELIMINAR', $target['username']);
        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
