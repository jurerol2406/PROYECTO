<?php
session_start();
session_destroy(); // Destruye toda la información de la sesión
header("Location: login.php"); // Lo devuelve al login
exit();
?>