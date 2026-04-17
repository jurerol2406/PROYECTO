<?php
session_start();
require_once 'conexion.php'; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $userInput = $_POST['username'];
    $passInput = $_POST['password']; // Coincide con el 'name' del input HTML

    try {
        $stmt = $conexion->prepare("SELECT * FROM Usuarios WHERE usuario = ?");
        $stmt->execute([$userInput]);
        $user = $stmt->fetch();

        // IMPORTANTE: Aquí usamos 'passwd' porque así lo llamaste en CreacionBD.php
        if ($user && password_verify($passInput, $user['passwd'])) {
            $_SESSION['usuario'] = $user['usuario'];
            header("Location: index.php");
            exit();
        } else {
            $error = "Usuario o contraseña incorrectos";
        }
    } catch (PDOException $e) {
        $error = "Error en el sistema: " . $e->getMessage();
    }
}
?>


<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>SmartHome - Acceso</title>
    <link rel="stylesheet" href="smarthome.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .login-card { background: var(--card-bg); padding: 30px; border-radius: 12px; width: 320px; border-top: 4px solid var(--accent-color); }
        input { width: 100%; padding: 12px; margin: 10px 0; background: var(--bg-color); border: 1px solid #334155; color: white; border-radius: 6px; box-sizing: border-box; }
        .error { color: #ef4444; font-size: 0.85rem; margin-bottom: 10px; text-align: center; }
    </style>
</head>
<body>
    <div class="login-card">
        <h2 style="text-align: center;">🔐 LOGIN SMARTHOME</h2>
        <?php if(isset($error)) echo "<p class='error'>$error</p>"; ?>
        <form method="POST">
            <input type="text" name="username" placeholder="Usuario" required>
            <input type="password" name="password" placeholder="Contraseña" required>
            <button type="submit" class="btn-primary">Entrar</button>
        </form>
    </div>
</body>
</html>