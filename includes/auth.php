<?php
session_start();

// 1. Verificar si está logueado
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: ../login.php'); // Ajusta la ruta a login.php
    exit;
}

// 2. Si entró con una contraseña temporal, solo puede ir a cambiarla (o salir)
if (!empty($_SESSION['debe_cambiar_password']) && basename($_SERVER['SCRIPT_NAME']) !== 'password.php') {
    header('Location: ../cuenta/password.php');
    exit;
}

// 3. Función para verificar roles específicos
function check_role($roles_permitidos) {
    if (!in_array($_SESSION['rol'], $roles_permitidos)) {
        header('Location: ../index.php'); // Redirigir al enrutador principal
        exit;
    }
}
?>