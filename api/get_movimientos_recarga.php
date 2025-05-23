<?php
// api/get_movimientos_recarga.php
header('Content-Type: application/json; charset=UTF-8');
require_once 'db_connect.php';

try {
    // Verificar autorización
    if (!isset($_GET['uid']) || !isset($_GET['role'])) {
        throw new Exception("No autorizado");
    }

    $uid = $_GET['uid'];
    $role = $_GET['role'];
    
    // Solo root puede acceder
    if ($role !== 'root') {
        throw new Exception("Solo el usuario root puede acceder a esta información");
    }
    
    // Conectar a la base de datos
    $con = conexiondb();
    
    // Consulta para obtener TODOS los movimientos de tipo RECARGA con información de tienda
    $query = "SELECT 
                m.id_movimiento,
                m.tipo,
                m.monto,
                m.id_bolsa_origen,
                m.id_bolsa_destino,
                m.referencia,
                m.imagen_comprobante,
                m.user_id,
                m.dispositivo_id,
                m.dispositivo_modelo,
                m.fecha_creacion,
                m.estado,
                m.fecha_aprobacion,
                m.aprobado_por,
                COALESCE(s.nombre_tienda, 'Sin tienda asignada') as nombre_tienda,
                s.id as tienda_id,
                psr.pdv_id,
                CASE 
                    WHEN m.imagen_comprobante IS NOT NULL AND LENGTH(m.imagen_comprobante) > 0 
                    THEN 1 
                    ELSE 0 
                END as tiene_comprobante
              FROM movimientos m
              LEFT JOIN pdv_supervendedor_relacion psr ON m.user_id = psr.supervendedor_id COLLATE utf8mb4_general_ci
              LEFT JOIN sucursales s ON psr.pdv_id = s.id
              WHERE m.tipo = 'RECARGA'
              ORDER BY m.fecha_creacion DESC";
    
    $result = mysqli_query($con, $query);
    
    if (!$result) {
        throw new Exception("Error en la consulta: " . mysqli_error($con));
    }
    
    // Construir array de resultados
    $movimientos = [];
    $totalRecargas = 0;
    $montoPendiente = 0;
    $montoAprobado = 0;
    $montoRechazado = 0;
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Calcular estadísticas
        $totalRecargas++;
        $monto = floatval($row['monto']);
        
        if ($row['estado'] === 'pendiente') {
            $montoPendiente += $monto;
        } elseif ($row['estado'] === 'aprobado') {
            $montoAprobado += $monto;
        } elseif ($row['estado'] === 'rechazado') {
            $montoRechazado += $monto;
        }
        
        // Agregar información adicional
        $row['nombre_tienda'] = $row['nombre_tienda'] ?: 'Tienda no identificada';
        $row['monto_formateado'] = '$' . number_format($monto, 2);
        $row['fecha_formateada'] = date('d/m/Y H:i', strtotime($row['fecha_creacion']));
        
        if ($row['fecha_aprobacion']) {
            $row['fecha_aprobacion_formateada'] = date('d/m/Y H:i', strtotime($row['fecha_aprobacion']));
        } else {
            $row['fecha_aprobacion_formateada'] = 'N/A';
        }
        
        // Convertir tiene_comprobante a entero
        $row['tiene_comprobante'] = intval($row['tiene_comprobante']);
        
        $movimientos[] = $row;
    }
    
    // Obtener información de bolsas relacionadas
    $bolsasIds = [];
    foreach ($movimientos as $mov) {
        if ($mov['id_bolsa_origen']) {
            $bolsasIds[] = $mov['id_bolsa_origen'];
        }
        if ($mov['id_bolsa_destino']) {
            $bolsasIds[] = $mov['id_bolsa_destino'];
        }
    }
    
    $bolsasInfo = [];
    if (!empty($bolsasIds)) {
        $bolsasIds = array_unique($bolsasIds);
        $bolsasIdsStr = implode(',', array_map('intval', $bolsasIds));
        
        $queryBolsas = "SELECT b.id, b.nombre_bolsa, b.saldo_actual, s.nombre_tienda 
                        FROM bolsas b
                        LEFT JOIN sucursales s ON b.id_sucursal = s.id
                        WHERE b.id IN ($bolsasIdsStr)";
        
        $resultBolsas = mysqli_query($con, $queryBolsas);
        
        if ($resultBolsas) {
            while ($bolsa = mysqli_fetch_assoc($resultBolsas)) {
                $bolsasInfo[$bolsa['id']] = [
                    'nombre' => $bolsa['nombre_bolsa'] ?: 'Bolsa #' . $bolsa['id'],
                    'tienda' => $bolsa['nombre_tienda'] ?: 'Sin tienda',
                    'saldo' => floatval($bolsa['saldo_actual'])
                ];
            }
        }
    }
    
    // Enriquecer movimientos con información de bolsas
    foreach ($movimientos as &$mov) {
        if ($mov['id_bolsa_destino'] && isset($bolsasInfo[$mov['id_bolsa_destino']])) {
            $mov['bolsa_destino_info'] = $bolsasInfo[$mov['id_bolsa_destino']];
        } else {
            $mov['bolsa_destino_info'] = null;
        }
        
        if ($mov['id_bolsa_origen'] && isset($bolsasInfo[$mov['id_bolsa_origen']])) {
            $mov['bolsa_origen_info'] = $bolsasInfo[$mov['id_bolsa_origen']];
        } else {
            $mov['bolsa_origen_info'] = null;
        }
    }
    
    // Estadísticas
    $estadisticas = [
        'total_recargas' => $totalRecargas,
        'monto_total_pendiente' => $montoPendiente,
        'monto_total_aprobado' => $montoAprobado,
        'monto_total_rechazado' => $montoRechazado,
        'monto_total' => $montoPendiente + $montoAprobado + $montoRechazado,
        'recargas_pendientes' => count(array_filter($movimientos, function($m) { 
            return $m['estado'] === 'pendiente'; 
        })),
        'recargas_aprobadas' => count(array_filter($movimientos, function($m) { 
            return $m['estado'] === 'aprobado'; 
        })),
        'recargas_rechazadas' => count(array_filter($movimientos, function($m) { 
            return $m['estado'] === 'rechazado'; 
        }))
    ];
    
    // Cerrar conexión
    mysqli_close($con);
    
    // Devolver resultados
    echo json_encode([
        "success" => true,
        "movimientos" => $movimientos,
        "estadisticas" => $estadisticas,
        "bolsas" => $bolsasInfo
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>