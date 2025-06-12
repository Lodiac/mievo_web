<?php
// api/generar_reporte.php - VERSIÓN ACTUALIZADA CON NUEVA QUERY
header('Content-Type: application/json; charset=UTF-8');
require_once 'db_connect.php';

try {
    // Verificar método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Método no permitido");
    }
    
    // Obtener datos del cuerpo de la solicitud
    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true);
    
    // Validar datos requeridos
    if (!isset($input['uid']) || !isset($input['role'])) {
        throw new Exception("Faltan datos requeridos: uid, role");
    }
    
    $uid = $input['uid'];
    $role = $input['role'];
    $fechaInicio = $input['fecha_inicio'] ?? date('Y-m-d', strtotime('-7 days'));
    $fechaFin = $input['fecha_fin'] ?? date('Y-m-d');
    
    // Log para debugging
    error_log("Generando reporte de tiendas: usuario=$uid, role=$role, desde=$fechaInicio, hasta=$fechaFin");
    
    // Verificar permisos - solo root puede generar reportes
    if ($role !== 'root') {
        throw new Exception("No tienes permisos para generar reportes");
    }
    
    // Conectar a la base de datos
    $con = conexiondb();
    
    // Generar reporte de tiendas
    $resultado = generarReporteTiendasActualizado($con, $fechaInicio, $fechaFin);
    
    // Cerrar conexión
    mysqli_close($con);
    
    // Respuesta exitosa
    echo json_encode([
        "success" => true,
        "tipo_reporte" => "tiendas",
        "periodo" => [
            "inicio" => $fechaInicio,
            "fin" => $fechaFin
        ],
        "datos" => $resultado,
        "timestamp" => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    // Cerrar conexión si existe
    if (isset($con)) {
        mysqli_close($con);
    }
    
    error_log("Error en generar_reporte.php: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage(),
        "timestamp" => date('Y-m-d H:i:s')
    ]);
}

// =====================================================
// FUNCIÓN ACTUALIZADA PARA GENERAR REPORTE DE TIENDAS
// =====================================================

function generarReporteTiendasActualizado($con, $fechaInicio, $fechaFin) {
    // QUERY ACTUALIZADA CON NUEVOS CAMPOS Y ESTRUCTURA
    $query = "SELECT 
                s.id,
                s.nombre_tienda,
                s.canal,
                s.tipoTienda,
                s.sicatel,
                s.encargado,
                s.telefono,
                (SELECT COUNT(*) 
                 FROM vendedor_tienda_relacion vtr 
                 WHERE vtr.tienda_id = s.id) as total_vendedores,
                
                COALESCE(sp.proveedor, 'Sin actividad') as proveedor,
                COALESCE(sp.tipo_servicio, 'Sin actividad') as tipo_servicio,
                
                COALESCE(COUNT(sp.id), 0) as solicitudes_desglose,
                COALESCE(SUM(sp.monto), 0) as monto_desglose,
                
                COUNT(CASE WHEN sp.estado_solicitud = 'completada' THEN 1 END) as solicitudes_completadas_desglose,
                COUNT(CASE WHEN sp.estado_solicitud = 'rechazada' THEN 1 END) as solicitudes_rechazadas_desglose,
                COUNT(CASE WHEN sp.estado_solicitud = 'cancelada' THEN 1 END) as solicitudes_canceladas_desglose,
                
                SUM(CASE WHEN sp.estado_solicitud = 'completada' THEN sp.monto ELSE 0 END) as monto_completado_desglose,
                SUM(CASE WHEN sp.estado_solicitud = 'rechazada' THEN sp.monto ELSE 0 END) as monto_rechazado_desglose,
                SUM(CASE WHEN sp.estado_solicitud = 'cancelada' THEN sp.monto ELSE 0 END) as monto_cancelado_desglose,
                
                (SELECT COUNT(*) 
                 FROM sol_pagoservicios sp2 
                 WHERE sp2.procesada_por COLLATE utf8mb4_general_ci = s.user_id COLLATE utf8mb4_general_ci
                   AND sp2.fecha_actualizacion BETWEEN CONCAT(?, ' 00:00:00') AND CONCAT(?, ' 23:59:59')
                   AND sp2.estado = 1
                   AND sp2.estado_solicitud IN ('completada', 'rechazada', 'cancelada')) as total_solicitudes_tienda,
                
                (SELECT COALESCE(SUM(sp2.monto), 0) 
                 FROM sol_pagoservicios sp2 
                 WHERE sp2.procesada_por COLLATE utf8mb4_general_ci = s.user_id COLLATE utf8mb4_general_ci
                   AND sp2.fecha_actualizacion BETWEEN CONCAT(?, ' 00:00:00') AND CONCAT(?, ' 23:59:59')
                   AND sp2.estado = 1 
                   AND sp2.estado_solicitud IN ('completada', 'rechazada', 'cancelada')) as total_monto_tienda,
                   
                (SELECT COUNT(*) 
                 FROM sol_pagoservicios sp2 
                 WHERE sp2.procesada_por COLLATE utf8mb4_general_ci = s.user_id COLLATE utf8mb4_general_ci
                   AND sp2.fecha_actualizacion BETWEEN CONCAT(?, ' 00:00:00') AND CONCAT(?, ' 23:59:59')
                   AND sp2.estado = 1 AND sp2.estado_solicitud = 'completada') as total_completadas_tienda,
                   
                (SELECT COUNT(*) 
                 FROM sol_pagoservicios sp2 
                 WHERE sp2.procesada_por COLLATE utf8mb4_general_ci = s.user_id COLLATE utf8mb4_general_ci
                   AND sp2.fecha_actualizacion BETWEEN CONCAT(?, ' 00:00:00') AND CONCAT(?, ' 23:59:59')
                   AND sp2.estado = 1 AND sp2.estado_solicitud = 'rechazada') as total_rechazadas_tienda,
                   
                (SELECT COUNT(*) 
                 FROM sol_pagoservicios sp2 
                 WHERE sp2.procesada_por COLLATE utf8mb4_general_ci = s.user_id COLLATE utf8mb4_general_ci
                   AND sp2.fecha_actualizacion BETWEEN CONCAT(?, ' 00:00:00') AND CONCAT(?, ' 23:59:59')
                   AND sp2.estado = 1 AND sp2.estado_solicitud = 'cancelada') as total_canceladas_tienda

            FROM sucursales s
            LEFT JOIN sol_pagoservicios sp ON sp.procesada_por COLLATE utf8mb4_general_ci = s.user_id COLLATE utf8mb4_general_ci
                AND sp.fecha_actualizacion BETWEEN CONCAT(?, ' 00:00:00') AND CONCAT(?, ' 23:59:59')
                AND sp.estado = 1
                AND sp.estado_solicitud IN ('completada', 'rechazada', 'cancelada')

            WHERE s.estado = 1 
              AND s.canal = 'internas'
              AND EXISTS (
                SELECT 1 FROM sol_pagoservicios sp_check 
                WHERE sp_check.procesada_por COLLATE utf8mb4_general_ci = s.user_id COLLATE utf8mb4_general_ci
                  AND sp_check.fecha_actualizacion BETWEEN CONCAT(?, ' 00:00:00') AND CONCAT(?, ' 23:59:59')
                  AND sp_check.estado = 1
                  AND sp_check.estado_solicitud IN ('completada', 'rechazada', 'cancelada')
              )

            GROUP BY s.id, s.nombre_tienda, s.canal, s.tipoTienda, s.sicatel, s.encargado, s.telefono, 
                     s.user_id, sp.proveedor, sp.tipo_servicio

            ORDER BY total_monto_tienda DESC, s.nombre_tienda, sp.proveedor, sp.tipo_servicio";
    
    // Preparar statement con todos los parámetros de fecha
    $stmt = mysqli_prepare($con, $query);
    if (!$stmt) {
        throw new Exception("Error preparando la consulta: " . mysqli_error($con));
    }
    
    // Bind de parámetros (12 fechas en total)
    mysqli_stmt_bind_param($stmt, "ssssssssssss", 
        $fechaInicio, $fechaFin,  // total_solicitudes_tienda
        $fechaInicio, $fechaFin,  // total_monto_tienda
        $fechaInicio, $fechaFin,  // total_completadas_tienda
        $fechaInicio, $fechaFin,  // total_rechazadas_tienda
        $fechaInicio, $fechaFin,  // total_canceladas_tienda
        $fechaInicio, $fechaFin,  // LEFT JOIN sp
        $fechaInicio, $fechaFin   // EXISTS subquery
    );
    
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Error ejecutando la consulta: " . mysqli_stmt_error($stmt));
    }
    
    $result = mysqli_stmt_get_result($stmt);
    if (!$result) {
        throw new Exception("Error obteniendo resultados: " . mysqli_stmt_error($stmt));
    }
    
    $datos = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Convertir sicatel a booleano
        $row['sicatel'] = (bool)$row['sicatel'];
        
        // Formatear números para consistencia
        $row['monto_desglose'] = number_format((float)$row['monto_desglose'], 2, '.', '');
        $row['monto_completado_desglose'] = number_format((float)$row['monto_completado_desglose'], 2, '.', '');
        $row['monto_rechazado_desglose'] = number_format((float)$row['monto_rechazado_desglose'], 2, '.', '');
        $row['monto_cancelado_desglose'] = number_format((float)$row['monto_cancelado_desglose'], 2, '.', '');
        $row['total_monto_tienda'] = number_format((float)$row['total_monto_tienda'], 2, '.', '');
        
        // Convertir números a enteros donde corresponde
        $row['total_vendedores'] = (int)$row['total_vendedores'];
        $row['solicitudes_desglose'] = (int)$row['solicitudes_desglose'];
        $row['solicitudes_completadas_desglose'] = (int)$row['solicitudes_completadas_desglose'];
        $row['solicitudes_rechazadas_desglose'] = (int)$row['solicitudes_rechazadas_desglose'];
        $row['solicitudes_canceladas_desglose'] = (int)$row['solicitudes_canceladas_desglose'];
        $row['total_solicitudes_tienda'] = (int)$row['total_solicitudes_tienda'];
        $row['total_completadas_tienda'] = (int)$row['total_completadas_tienda'];
        $row['total_rechazadas_tienda'] = (int)$row['total_rechazadas_tienda'];
        $row['total_canceladas_tienda'] = (int)$row['total_canceladas_tienda'];
        
        $datos[] = $row;
    }
    
    mysqli_stmt_close($stmt);
    
    // Log del resultado
    error_log("Reporte generado exitosamente. Total de filas: " . count($datos));
    
    return $datos;
}
?>