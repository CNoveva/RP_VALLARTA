<?php
session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

$rol = $_SESSION['rol'];

switch ($rol) {
    case 'rp':
        header('Location: rp/index.php');
        break;
    case 'hostes':
        header('Location: hostes/index.php');
        break;
    case 'caja':
        header('Location: caja/index.php');
        break;
    case 'gerente':
        header('Location: gerente/index.php');
        break;
    case 'admin':
        header('Location: admin/index.php');
        break;
    case 'coordinador':
        header('Location: coordinador/index.php');
        break;
    default:
        session_destroy();
        header('Location: login.php?err=rol_invalido');
        break;
}
exit;
?>