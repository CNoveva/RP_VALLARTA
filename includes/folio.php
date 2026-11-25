<?php
// Interpreta un folio de ticket: prefijo obligatorio VTA (reserva) o EV (evento),
// número de reserva y sufijo opcional de cuenta dividida (VTA355, VTA355A, VTA355-B, VTA713G1).
// Devuelve null si no cumple el formato; sin prefijo no se acepta ("355" o "M355" no son folios).
function parsear_folio($folio) {
    // Se toleran espacios entre prefijo, número y sufijo ("VTA 355 - B"), pero no dentro del número ("VTA12 34")
    $folio = strtoupper(trim((string)$folio));
    if (!preg_match('/^(VTA|EV)\s*(\d+)\s*(-\s*[A-Z0-9]{1,3}|[A-Z][A-Z0-9]{0,2})?$/', $folio, $m)) {
        return null;
    }
    $id = intval($m[2]);
    if ($id <= 0) {
        return null;
    }
    return [
        'folio' => $m[1] . $m[2] . preg_replace('/\s+/', '', $m[3] ?? ''),
        'es_evento' => ($m[1] === 'EV'),
        'id' => $id,
    ];
}
