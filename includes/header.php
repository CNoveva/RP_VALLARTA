<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

// Carpeta del panel del rol (la carpeta se llama igual que el rol), para que el menú
// funcione también desde páginas comunes como cuenta/password.php
$nav_base = '../' . $_SESSION['rol'] . '/';

// Solicitudes de "olvidé mi contraseña" pendientes: aviso en el menú de gerente (solo RPs) y admin
$reset_pendientes = 0;
if (in_array($_SESSION['rol'], ['admin', 'gerente'])) {
    try {
        $filtro_rol_reset = ($_SESSION['rol'] == 'gerente') ? " AND rol = 'rp'" : "";
        $reset_pendientes = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE reset_solicitado_en IS NOT NULL AND activo = 1" . $filtro_rol_reset)->fetchColumn();
    } catch (PDOException $e) {
        // Aún no se ejecuta ajustes_2026-09-17.sql (columna reset_solicitado_en): sin aviso
    }
}
?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo htmlspecialchars(ucfirst($_SESSION['rol'])); ?></title>
    <link rel="icon" type="image/png" href="/includes/bartola.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    
    <script>
      tailwind.config = {
        theme: {
          extend: {
            fontFamily: {
              'sans': ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
            },
            colors: {
              'btla-blue': '#0A3D62', 
              'btla-yellow': '#F3B431',
            }
          }
        }
      }
    </script>

    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        
        /* Subtle background pattern */
        .bg-app {
            background-color: #0a0a0a;
            background-image: radial-gradient(ellipse at top, rgba(30,30,30,0.5) 0%, transparent 60%);
        }
        
        /* Glassmorphism card */
        .glass-card {
            background: rgba(38, 38, 38, 0.6);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(64, 64, 64, 0.4);
        }
        
        /* Premium input style */
        .input-premium {
            background: rgba(23, 23, 23, 0.8);
            border: 1px solid rgba(64, 64, 64, 0.6);
            transition: all 0.2s ease;
        }
        .input-premium:focus {
            border-color: #f59e0b;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
            outline: none;
        }
        
        /* Amber gradient button */
        .btn-amber {
            background: linear-gradient(135deg, #d97706, #b45309);
            transition: all 0.3s ease;
        }
        .btn-amber:hover {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.25);
        }
        
        /* Secondary button */
        .btn-secondary {
            background: rgba(38, 38, 38, 0.8);
            border: 1px solid rgba(64, 64, 64, 0.6);
            transition: all 0.3s ease;
        }
        .btn-secondary:hover {
            background: rgba(55, 55, 55, 0.9);
            border-color: rgba(100, 100, 100, 0.6);
        }
        
        /* Table premium styles */
        .table-premium thead {
            background: linear-gradient(135deg, rgba(38, 38, 38, 0.9), rgba(23, 23, 23, 0.9));
        }
        .table-premium tbody tr {
            transition: background-color 0.15s ease;
        }
        .table-premium tbody tr:hover {
            background-color: rgba(55, 55, 55, 0.5);
        }

        /* Tablas que deben caber completas en pantalla (sin scroll horizontal):
           el texto largo se acomoda en varias líneas y el espaciado se compacta
           conforme hay menos ancho. Las celdas con .whitespace-nowrap no se parten. */
        .table-fit { width: 100%; table-layout: auto; }
        .table-fit th, .table-fit td { padding-left: 0.5rem; padding-right: 0.5rem; }
        .table-fit th:first-child, .table-fit td:first-child { padding-left: 0.75rem; }
        .table-fit th:last-child, .table-fit td:last-child { padding-right: 0.75rem; }
        .table-fit td { white-space: normal; overflow-wrap: anywhere; vertical-align: top; }
        .table-fit td.whitespace-nowrap, .table-fit .whitespace-nowrap { white-space: nowrap; overflow-wrap: normal; }
        @media (max-width: 1400px) {
            .table-fit th { padding-left: 0.375rem; padding-right: 0.375rem; font-size: 0.65rem; letter-spacing: 0.04em; }
            .table-fit td { padding: 0.625rem 0.375rem; font-size: 0.8rem; }
            .table-fit td .text-sm { font-size: 0.8rem; }
            .table-fit td .text-xs { font-size: 0.7rem; }
        }
        @media (max-width: 1100px) {
            .table-fit td { font-size: 0.75rem; }
            .table-fit td .text-sm { font-size: 0.75rem; }
            .table-fit td .text-xs { font-size: 0.66rem; }
        }

        /* Barra de desplazamiento horizontal fija (se agrega por JS a cada tabla ancha).
           Se queda pegada abajo de la pantalla mientras la tabla esté a la vista. */
        .scroll-sync {
            position: sticky;
            bottom: 0;
            overflow-x: auto;
            overflow-y: hidden;
            height: 16px;
            z-index: 20;
            background: rgba(23, 23, 23, 0.95);
            border-top: 1px solid rgba(64, 64, 64, 0.5);
            scrollbar-width: thin;
            scrollbar-color: #737373 #171717;
        }
        .scroll-sync::-webkit-scrollbar { height: 14px; }
        .scroll-sync::-webkit-scrollbar-track { background: #171717; }
        .scroll-sync::-webkit-scrollbar-thumb { background: #737373; border-radius: 7px; border: 3px solid #171717; }
        .scroll-sync::-webkit-scrollbar-thumb:hover { background: #a3a3a3; }
        .scroll-sync > div { height: 1px; }
        /* La tabla oculta su barra propia para no mostrar dos */
        .has-scroll-sync { scrollbar-width: none; }
        .has-scroll-sync::-webkit-scrollbar { display: none; }
        .has-scroll-sync.arrastrando { cursor: grabbing; user-select: none; }

        /* Mobile menu transition */
        #mobile-menu {
            transition: all 0.3s ease;
            max-height: 0;
            overflow: hidden;
            opacity: 0;
        }
        #mobile-menu.menu-open {
            max-height: 520px;
            opacity: 1;
        }
        
        /* Nav link hover effect */
        .nav-link {
            position: relative;
            transition: all 0.2s ease;
        }
        .nav-link::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 50%;
            width: 0;
            height: 2px;
            background: linear-gradient(90deg, #f59e0b, #d97706);
            transition: all 0.3s ease;
            transform: translateX(-50%);
            border-radius: 1px;
        }
        .nav-link:hover::after {
            width: 80%;
        }

        /* Alert styles */
        .alert-success {
            background: rgba(22, 101, 52, 0.2);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
        }
        .alert-error {
            background: rgba(127, 29, 29, 0.2);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }
    </style>

</head>
<body class="h-full bg-app text-white">
<div class="min-h-full">
    <nav class="bg-gradient-to-r from-neutral-900 via-neutral-900 to-neutral-800 border-b border-neutral-800/80">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex h-16 items-center justify-between">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <a href="/login.php" class="transition-transform duration-200 hover:scale-110 inline-block">
                            <img src="/includes/bartola.png" alt="Bartola" class="h-9 w-9">
                        </a>
                    </div>
                    <div class="hidden md:block">
                        <div class="ml-10 flex items-baseline space-x-2">
                            <?php if ($_SESSION['rol'] == 'admin'): ?>
                                <a href="<?php echo $nav_base; ?>index.php" class="nav-link text-neutral-300 hover:text-white rounded-lg px-3 py-2 text-sm font-medium">Usuarios</a>
                                <a href="<?php echo $nav_base; ?>eventos.php" class="nav-link text-neutral-300 hover:text-white rounded-lg px-3 py-2 text-sm font-medium">Eventos</a>
                            <?php endif; ?>
                            <?php if ($_SESSION['rol'] == 'rp'): ?>
                                <a href="<?php echo $nav_base; ?>index.php" class="nav-link text-neutral-300 hover:text-white rounded-lg px-3 py-2 text-sm font-medium">Mis Reservas</a>
                                <a href="<?php echo $nav_base; ?>crear_reserva.php" class="nav-link text-neutral-300 hover:text-white rounded-lg px-3 py-2 text-sm font-medium">Crear Reserva</a>
                            <?php endif; ?>
                            <?php if ($_SESSION['rol'] == 'hostes'): ?>
                                <a href="<?php echo $nav_base; ?>index.php" class="nav-link text-neutral-300 hover:text-white rounded-lg px-3 py-2 text-sm font-medium">Reservas Activas</a>
                                <a href="<?php echo $nav_base; ?>visitas.php" class="nav-link text-neutral-300 hover:text-white rounded-lg px-3 py-2 text-sm font-medium">Sin Reserva</a>
                                <a href="<?php echo $nav_base; ?>crear_reserva.php" class="nav-link text-neutral-300 hover:text-white rounded-lg px-3 py-2 text-sm font-medium">Nueva Reserva</a>
                            <?php endif; ?>
                             <?php if ($_SESSION['rol'] == 'caja'): ?>
                                <a href="<?php echo $nav_base; ?>index.php" class="nav-link text-neutral-300 hover:text-white rounded-lg px-3 py-2 text-sm font-medium">Cerrar Cuentas</a>
                                <a href="<?php echo $nav_base; ?>comisiones.php" class="nav-link text-neutral-300 hover:text-white rounded-lg px-3 py-2 text-sm font-medium">Comisiones</a>
                            <?php endif; ?>
                            <?php if ($_SESSION['rol'] == 'gerente'): ?>
                                <a href="<?php echo $nav_base; ?>dashboard.php" class="nav-link text-neutral-300 hover:text-white rounded-lg px-3 py-2 text-sm font-medium">Dashboard</a>
                                <a href="<?php echo $nav_base; ?>index.php" class="nav-link text-neutral-300 hover:text-white rounded-lg px-3 py-2 text-sm font-medium">Reportes</a>
                                <a href="<?php echo $nav_base; ?>crear_reserva.php" class="nav-link text-neutral-300 hover:text-white rounded-lg px-3 py-2 text-sm font-medium">Nueva Reserva</a>
                                <a href="<?php echo $nav_base; ?>usuarios.php" class="nav-link text-neutral-300 hover:text-white rounded-lg px-3 py-2 text-sm font-medium">RPs</a>
                            <?php endif; ?>
                            <?php if ($_SESSION['rol'] == 'coordinador'): ?>
                                <a href="<?php echo $nav_base; ?>index.php" class="nav-link text-neutral-300 hover:text-white rounded-lg px-3 py-2 text-sm font-medium">Mis Registros</a>
                                <a href="<?php echo $nav_base; ?>crear_reserva.php" class="nav-link text-neutral-300 hover:text-white rounded-lg px-3 py-2 text-sm font-medium">Nueva Reserva</a>
                            <?php endif; ?>
                            <?php if (in_array($_SESSION['rol'], ['admin', 'gerente'])): ?>
                                <a href="../cuenta/restablecer.php" class="nav-link text-neutral-300 hover:text-white rounded-lg px-3 py-2 text-sm font-medium">
                                    Contraseñas<?php if ($reset_pendientes > 0): ?> <span class="ml-1 inline-flex items-center justify-center rounded-full bg-amber-500 px-2 py-0.5 text-xs font-bold text-black"><?php echo $reset_pendientes; ?></span><?php endif; ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="hidden md:flex items-center space-x-4">
                    <div class="flex items-center space-x-2">
                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-amber-500 to-amber-700 flex items-center justify-center text-sm font-bold text-white">
                            <?php echo strtoupper(substr($_SESSION['nombre'], 0, 1)); ?>
                        </div>
                        <span class="text-neutral-300 text-sm font-medium"><?php echo htmlspecialchars($_SESSION['nombre']); ?></span>
                    </div>
                    <a href="../cuenta/password.php" class="text-neutral-400 hover:text-white text-sm font-medium transition-colors duration-200">
                        Cambiar contraseña
                    </a>
                    <a href="../logout.php" class="btn-secondary text-white font-medium py-2 px-4 rounded-lg text-sm">
                        Salir
                    </a>
                </div>
                <div class="-mr-2 flex md:hidden">
                    <button type="button" class="inline-flex items-center justify-center rounded-lg bg-neutral-800 p-2 text-neutral-400 hover:text-white hover:bg-neutral-700 focus:outline-none focus:ring-2 focus:ring-amber-500 transition-colors duration-200" onclick="toggleMenu()">
                        <span class="sr-only">Abrir menú</span>
                        <svg id="menu-icon-open" class="block h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                        <svg id="menu-icon-close" class="hidden h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                           <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <div class="md:hidden" id="mobile-menu">
            <div class="space-y-1 px-3 pt-3 pb-3">
                <?php if ($_SESSION['rol'] == 'admin'): ?>
                    <a href="<?php echo $nav_base; ?>index.php" class="text-neutral-300 hover:bg-neutral-800 hover:text-white block rounded-lg px-3 py-2.5 text-base font-medium transition-colors duration-200">Usuarios</a>
                    <a href="<?php echo $nav_base; ?>eventos.php" class="text-neutral-300 hover:bg-neutral-800 hover:text-white block rounded-lg px-3 py-2.5 text-base font-medium transition-colors duration-200">Eventos</a>
                <?php endif; ?>
                <?php if ($_SESSION['rol'] == 'rp'): ?>
                    <a href="<?php echo $nav_base; ?>index.php" class="text-neutral-300 hover:bg-neutral-800 hover:text-white block rounded-lg px-3 py-2.5 text-base font-medium transition-colors duration-200">Mis Reservas</a>
                    <a href="<?php echo $nav_base; ?>crear_reserva.php" class="text-neutral-300 hover:bg-neutral-800 hover:text-white block rounded-lg px-3 py-2.5 text-base font-medium transition-colors duration-200">Crear Reserva</a>
                <?php endif; ?>
                <?php if ($_SESSION['rol'] == 'hostes'): ?>
                    <a href="<?php echo $nav_base; ?>index.php" class="text-neutral-300 hover:bg-neutral-800 hover:text-white block rounded-lg px-3 py-2.5 text-base font-medium transition-colors duration-200">Reservas Activas</a>
                    <a href="<?php echo $nav_base; ?>visitas.php" class="text-neutral-300 hover:bg-neutral-800 hover:text-white block rounded-lg px-3 py-2.5 text-base font-medium transition-colors duration-200">Sin Reserva</a>
                    <a href="<?php echo $nav_base; ?>crear_reserva.php" class="text-neutral-300 hover:bg-neutral-800 hover:text-white block rounded-lg px-3 py-2.5 text-base font-medium transition-colors duration-200">Nueva Reserva</a>
                <?php endif; ?>
                <?php if ($_SESSION['rol'] == 'caja'): ?>
                    <a href="<?php echo $nav_base; ?>index.php" class="text-neutral-300 hover:bg-neutral-800 hover:text-white block rounded-lg px-3 py-2.5 text-base font-medium transition-colors duration-200">Cerrar Cuentas</a>
                    <a href="<?php echo $nav_base; ?>comisiones.php" class="text-neutral-300 hover:bg-neutral-800 hover:text-white block rounded-lg px-3 py-2.5 text-base font-medium transition-colors duration-200">Comisiones</a>
                <?php endif; ?>
                <?php if ($_SESSION['rol'] == 'gerente'): ?>
                    <a href="<?php echo $nav_base; ?>dashboard.php" class="text-neutral-300 hover:bg-neutral-800 hover:text-white block rounded-lg px-3 py-2.5 text-base font-medium transition-colors duration-200">Dashboard</a>
                    <a href="<?php echo $nav_base; ?>index.php" class="text-neutral-300 hover:bg-neutral-800 hover:text-white block rounded-lg px-3 py-2.5 text-base font-medium transition-colors duration-200">Reportes</a>
                    <a href="<?php echo $nav_base; ?>crear_reserva.php" class="text-neutral-300 hover:bg-neutral-800 hover:text-white block rounded-lg px-3 py-2.5 text-base font-medium transition-colors duration-200">Nueva Reserva</a>
                    <a href="<?php echo $nav_base; ?>usuarios.php" class="text-neutral-300 hover:bg-neutral-800 hover:text-white block rounded-lg px-3 py-2.5 text-base font-medium transition-colors duration-200">RPs</a>
                <?php endif; ?>
                <?php if ($_SESSION['rol'] == 'coordinador'): ?>
                    <a href="<?php echo $nav_base; ?>index.php" class="text-neutral-300 hover:bg-neutral-800 hover:text-white block rounded-lg px-3 py-2.5 text-base font-medium transition-colors duration-200">Mis Registros</a>
                    <a href="<?php echo $nav_base; ?>crear_reserva.php" class="text-neutral-300 hover:bg-neutral-800 hover:text-white block rounded-lg px-3 py-2.5 text-base font-medium transition-colors duration-200">Nueva Reserva</a>
                <?php endif; ?>
                <?php if (in_array($_SESSION['rol'], ['admin', 'gerente'])): ?>
                    <a href="../cuenta/restablecer.php" class="text-neutral-300 hover:bg-neutral-800 hover:text-white block rounded-lg px-3 py-2.5 text-base font-medium transition-colors duration-200">
                        Contraseñas<?php if ($reset_pendientes > 0): ?> <span class="ml-1 inline-flex items-center justify-center rounded-full bg-amber-500 px-2 py-0.5 text-xs font-bold text-black"><?php echo $reset_pendientes; ?></span><?php endif; ?>
                    </a>
                <?php endif; ?>
            </div>
            <div class="border-t border-neutral-700/50 pt-4 pb-3 px-4">
                <div class="flex items-center mb-3">
                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-amber-500 to-amber-700 flex items-center justify-center text-sm font-bold text-white mr-3">
                        <?php echo strtoupper(substr($_SESSION['nombre'], 0, 1)); ?>
                    </div>
                    <div>
                        <div class="text-base font-semibold text-white"><?php echo htmlspecialchars($_SESSION['nombre']); ?></div>
                        <div class="text-sm text-neutral-400"><?php echo htmlspecialchars(ucfirst($_SESSION['rol'])); ?></div>
                    </div>
                </div>
                <a href="../cuenta/password.php" class="block w-full text-center rounded-lg px-3 py-2.5 text-base font-medium text-neutral-300 hover:bg-neutral-800 hover:text-white transition-colors duration-200">
                    Cambiar Contraseña
                </a>
                <a href="../logout.php" class="block w-full text-center rounded-lg px-3 py-2.5 text-base font-medium text-neutral-300 hover:bg-neutral-800 hover:text-white transition-colors duration-200">
                    Cerrar Sesión
                </a>
            </div>
        </div>
    </nav>

    <header class="border-b border-neutral-800/50">
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <h1 class="text-2xl sm:text-3xl font-bold tracking-tight text-white">
                Panel de <?php echo htmlspecialchars(ucfirst($_SESSION['rol'])); ?>
            </h1>
        </div>
    </header>
    <main>
        <div class="mx-auto max-w-7xl py-6 px-4 sm:px-6 lg:px-8">