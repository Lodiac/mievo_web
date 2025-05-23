<?php
// api/get_movimientos_recarga.php - VERSIÓN SIMPLIFICADA
header('Content-Type: application/json; charset=UTF-8');
require_once 'db_connect.php';

// Añadir logs para depuración
error_log("Iniciando get_movimientos_recarga.php");

try {
    // Obtener parámetros
    $uid = isset($_GET['uid']) ? $_GET['uid'] : '';
    $role = isset($_GET['role']) ? $_GET['role'] : '';
    
    // Registrar parámetros recibidos para depuración
    error_log("get_movimientos_recarga.php - Parámetros: uid=$uid, role=$role");
    
    // Conectar a la base de datos
    $con = conexiondb();
    
    if (!$con) {
        throw new Exception("Error al conectar con la base de datos");
    }
    
    // CONSULTA DIRECTA - Sin verificación de rol para debugging
    $query = "SELECT 
                m.id_movimiento,
                m.tipo,
                m.monto,
                m.id_bolsa_origen,
                m.id_bolsa_destino,
                m.referencia,
                m.user_id,
                m.dispositivo_id,
                m.dispositivo_modelo,
                m.fecha_creacion,
                m.estado,
                m.fecha_aprobacion,
                m.aprobado_por,
                CASE 
                    WHEN m.imagen_comprobante IS NOT NULL AND LENGTH(m.imagen_comprobante) > 0 
                    THEN 1 
                    ELSE 0 
                END as tiene_comprobante
              FROM movimientos m
              WHERE m.tipo = 'RECARGA'
              ORDER BY m.fecha_creacion DESC";
    
    error_log("Ejecutando consulta: " . $query);
    
    $result = mysqli_query($con, $query);
    
    if (!$result) {
        throw new Exception("Error en la consulta: " . mysqli_error($con));
    }
    
    // Construir array de resultados
    $movimientos = [];
    $count = 0;
    
    while ($row = mysqli_fetch_assoc($result)) {
        $count++;
        // Formatear algunos campos para mejorar presentación
        $row['fecha_formateada'] = date('d/m/Y H:i', strtotime($row['fecha_creacion']));
        $row['monto_formateado'] = '$' . number_format(floatval($row['monto']), 2);
        
        if ($row['fecha_aprobacion']) {
            $row['fecha_aprobacion_formateada'] = date('d/m/Y H:i', strtotime($row['fecha_aprobacion']));
        } else {
            $row['fecha_aprobacion_formateada'] = 'N/A';
        }
        
        // Convertir tiene_comprobante a entero
        $row['tiene_comprobante'] = intval($row['tiene_comprobante']);
        
        // Información básica de tienda
        $row['nombre_tienda'] = 'Usuario #' . $row['user_id'];
        
        $movimientos[] = $row;
    }
    
    error_log("Se encontraron $count movimientos");
    
    // Estadísticas básicas
    $totalRecargas = count($movimientos);
    $montoPendiente = 0;
    $montoAprobado = 0;
    $montoRechazado = 0;
    $recargasPendientes = 0;
    $recargasAprobadas = 0;
    $recargasRechazadas = 0;
    
    foreach ($movimientos as $mov) {
        $monto = floatval($mov['monto']);
        
        if ($mov['estado'] === 'pendiente') {
            $montoPendiente += $monto;
            $recargasPendientes++;
        } elseif ($mov['estado'] === 'aprobado') {
            $montoAprobado += $monto;
            $recargasAprobadas++;
        } elseif ($mov['estado'] === 'rechazado') {
            $montoRechazado += $monto;
            $recargasRechazadas++;
        }
    }
    
    $estadisticas = [
        'total_recargas' => $totalRecargas,
        'monto_total_pendiente' => $montoPendiente,
        'monto_total_aprobado' => $montoAprobado,
        'monto_total_rechazado' => $montoRechazado,
        'monto_total' => $montoPendiente + $montoAprobado + $montoRechazado,
        'recargas_pendientes' => $recargasPendientes,
        'recargas_aprobadas' => $recargasAprobadas,
        'recargas_rechazadas' => $recargasRechazadas
    ];
    
    // Cerrar conexión
    mysqli_close($con);
    
    // Respuesta final simplificada
    echo json_encode([
        "success" => true,
        "movimientos" => $movimientos,
        "estadisticas" => $estadisticas,
        "debug" => [
            "total_movimientos" => count($movimientos),
            "role_recibido" => $role,
            "uid_recibido" => $uid,
            "timestamp" => date('Y-m-d H:i:s')
        ]
    ]);
    
    error_log("Respuesta enviada con éxito. Total movimientos: $totalRecargas");
    
} catch (Exception $e) {
    // Log del error
    error_log("ERROR en get_movimientos_recarga.php: " . $e->getMessage());
    
    // Devolver error
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage(),
        "debug" => [
            "file" => "get_movimientos_recarga.php",
            "line" => $e->getLine(),
            "role_recibido" => $role ?? 'no proporcionado',
            "uid_recibido" => $uid ?? 'no proporcionado'
        ]
    ]);
}
?>