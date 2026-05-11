<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['username'])) {
    header('Location: index.php');
    exit();
}

require_once 'conexion.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Rellena todos los campos.';
    } else {
        try {
            $stmt = $conexion->prepare(
                "SELECT id, username, password_hash, role FROM users WHERE username = ? LIMIT 1"
            );
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']  = (int) $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role']     = $user['role'];

                if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
                    $conexion->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                             ->execute([password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $user['id']]);
                }

                header('Location: index.php');
                exit();
            } else {
                $error = 'Usuario o contraseña incorrectos.';
            }
        } catch (PDOException $e) {
            $error = 'Error del sistema. Inténtalo de nuevo.';
            error_log('[login] PDOException: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CerradurasIoT – Acceso</title>
    <link rel="stylesheet" href="smarthome.css">
    <style>
        body { display:flex; align-items:center; justify-content:center; height:100vh; margin:0; }
        .login-card {
            background: var(--card-bg);
            padding: 40px 36px;
            border-radius: 16px;
            width: 380px;
            border-top: 4px solid var(--accent-color);
            box-shadow: 0 12px 40px rgba(0,0,0,0.6);
        }
        .login-logo    { text-align:center; margin-bottom:8px; font-size:2.5rem; }
        .login-card h2 { text-align:center; margin:0 0 4px; color:var(--accent-color); font-size:1.3rem; }
        .login-sub     { text-align:center; color:var(--text-dim); font-size:.8rem; margin-bottom:28px; }
        .field         { position:relative; margin:10px 0; }
        .field span    { position:absolute; left:12px; top:50%; transform:translateY(-50%); pointer-events:none; }
        .login-card input {
            width:100%; padding:12px 14px 12px 38px;
            background:var(--bg-color); border:1px solid #334155; color:white;
            border-radius:8px; box-sizing:border-box; font-size:.95rem; transition:border-color .2s;
        }
        .login-card input:focus { outline:none; border-color:var(--accent-color); }
        .error-box {
            color:#ef4444; font-size:.85rem; margin-bottom:12px; text-align:center;
            background:rgba(239,68,68,.1); padding:10px; border-radius:6px;
            border:1px solid rgba(239,68,68,.3);
        }
        .hint {
            color:var(--text-dim); font-size:.75rem; text-align:center;
            margin-top:18px; line-height:1.9;
            border-top:1px solid #1e293b; padding-top:14px;
        }
        .hint code { background:#0f172a; padding:1px 6px; border-radius:4px; color:var(--accent-color); }
        .btn-login {
            margin-top:18px; background:var(--accent-color); color:white;
            border:none; padding:13px; width:100%; border-radius:8px;
            font-weight:bold; font-size:1rem; cursor:pointer; transition:opacity .2s;
        }
        .btn-login:hover { opacity:.88; }
    </style>
</head>
<body>
<div class="login-card">
    <div class="login-logo">🏢</div>
    <h2>Cerraduras IoT</h2>
    <p class="login-sub">Sistema de Control de Accesos</p>

    <?php if ($error !== ''): ?>
        <div class="error-box">⚠️ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <div class="field">
            <span>👤</span>
            <input type="text" name="username" placeholder="Usuario"
                   value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                   required autofocus maxlength="60">
        </div>
        <div class="field">
            <span>🔑</span>
            <input type="password" name="password" placeholder="Contraseña" required maxlength="128">
        </div>
        <button type="submit" class="btn-login">Entrar al Sistema</button>
    </form>

    <p class="hint">
        <strong>Admin:</strong> <code>admin</code> / <code>admin123</code><br>
        <strong>Usuario:</strong> <code>user</code> / <code>user123</code><br>
        <strong>Solo ver:</strong> <code>visor</code> / <code>visor123</code>
    </p>
</div>
</body>
</html>
