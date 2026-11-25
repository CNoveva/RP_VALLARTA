<?php
// Día operativo (turno): va de las 5:00 pm a las 4:59 pm del día siguiente.
// "19 de octubre" = del 19/10 17:00:00 al 20/10 16:59:59.
define('TURNO_HORA_INICIO', 17);

// Inicio del turno de una fecha (Y-m-d) como datetime
function turno_inicio($fecha) {
    return $fecha . ' ' . sprintf('%02d', TURNO_HORA_INICIO) . ':00:00';
}

// Fin EXCLUSIVO del turno de una fecha: el inicio del turno del día siguiente.
// Se usa con "<" para cubrir hasta las 16:59:59.
function turno_fin($fecha) {
    return turno_inicio(date('Y-m-d', strtotime($fecha . ' +1 day')));
}

// Fecha del turno al que pertenece un datetime (las 02:00 del día 20 pertenecen al turno del 19)
function turno_de($datetime) {
    return date('Y-m-d', strtotime($datetime) - TURNO_HORA_INICIO * 3600);
}

// Turno en curso
function turno_hoy() {
    return turno_de(date('Y-m-d H:i:s'));
}

// Expresión SQL que convierte una columna datetime en la fecha de su turno
function turno_sql($columna) {
    return "DATE($columna - INTERVAL " . TURNO_HORA_INICIO . " HOUR)";
}
