<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8">
        <title>Setup - SmartHome</title>
    </head>
    <body>
        <?php
        $dsn = "mysql:host=localhost";
        $usuario = "root";
        $clave = "";

        try {
            // 1. Conexión mediante PDO
            $conexion = new PDO($dsn, $usuario, $clave);
            // Configuramos PDO para que lance excepciones en caso de error
            $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 2. Crear BD (Añadimos IF NOT EXISTS para evitar errores si ya existe)
            $conexion->exec("CREATE DATABASE IF NOT EXISTS smarthome CHARACTER SET utf8 COLLATE utf8_spanish_ci");
            $conexion->exec("USE smarthome");

            // 3. Crear tabla Usuarios
            // Nota: He cambiado 'contraseña' por 'password' para evitar problemas con la 'ñ' en código
            $createtable = "CREATE TABLE IF NOT EXISTS Usuarios (
                id INT(6) AUTO_INCREMENT PRIMARY KEY,
                usuario VARCHAR(30) NOT NULL UNIQUE,
                passwd VARCHAR(255) NOT NULL, 
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB";
            
            $conexion->exec($createtable);

            // 4. Insertar Usuario Admin
            $user_admin = "admin";
            $pass_admin = password_hash("admin123", PASSWORD_DEFAULT);

            // >>> CORRECCIÓN: Comprobar si existe usando PDO, no mysqli <<<
            $stmt = $conexion->prepare("SELECT * FROM Usuarios WHERE usuario = ?");
            $stmt->execute([$user_admin]);
            
            if ($stmt->rowCount() == 0) {
                $sql_insert = "INSERT INTO Usuarios (usuario, passwd) VALUES (?, ?)";
                $insert_stmt = $conexion->prepare($sql_insert);
                if ($insert_stmt->execute([$user_admin, $pass_admin])) {
                    echo "<p style='color:green;'>👤 Usuario administrador creado con éxito (admin / admin123).</p>";
                }
            } else {
                echo "<p style='color:orange;'>ℹ️ El usuario administrador ya existe.</p>";
            }

            echo "<h3>🚀 Sistema listo. Ya puedes ir al <a href='login.php'>Login</a></h3>";
            echo "Base de datos y tablas verificadas con éxito.";

        } catch (PDOException $e) {
            // Unificamos el error en una sola captura para limpiar el código
            echo "<p style='color:red;'>❌ Fallo en el sistema: " . $e->getMessage() . "</p>";
        }

        
        $conexion = null;
        ?>
    </body>
</html>