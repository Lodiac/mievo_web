<?php
// api/generar_reporte.php - VERSIÓN ACTUALIZADA PARA SOLICITUDES INDIVIDUALES
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
    error_log("Generando reporte de solicitudes individuales: usuario=$uid, role=$role, desde=$fechaInicio, hasta=$fechaFin");
    
    // Verificar permisos - solo root puede generar reportes
    if ($role !== 'root') {
        throw new Exception("No tienes permisos para generar reportes");
    }
    
    // Conectar a la base de datos
    $con = conexiondb();
    
    // Generar reporte de solicitudes individuales
    $resultado = generarReporteSolicitudesIndividuales($con, $fechaInicio, $fechaFin);
    
    // Cerrar conexión
    mysqli_close($con);
    
    // Respuesta exitosa
    echo json_encode([
        "success" => true,
        "tipo_reporte" => "solicitudes_individuales",
        "periodo" => [
            "inicio" => $fechaInicio,
            "fin" => $fechaFin
        ],
        "datos" => $resultado['solicitudes'],
        "estadisticas" => $resultado['estadisticas'],
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
// FUNCIÓN PARA GENERAR REPORTE DE SOLICITUDES INDIVIDUALES
// =====================================================

function generarReporteSolicitudesIndividuales($con, $fechaInicio, $fechaFin) {
    // NUEVA QUERY PARA MOSTRAR SOLICITUDES INDIVIDUALES
    $query = "SELECT 
                sp.id as solicitud_id,
                sp.user_id,
                sp.user_name,
                sp.nombre_cliente,
                sp.proveedor,
                sp.tipo_servicio,
                sp.cuenta,
                sp.monto,
                sp.estado_solicitud,
                sp.fecha_creacion,
                sp.fecha_actualizacion,
                sp.procesada_por,
                sp.comentarios,
                
                -- Información de la tienda procesadora
                s.id as tienda_id,
                s.nombre_tienda,
                s.canal,
                s.tipoTienda,
                s.sicatel,
                s.encargado as tienda_encargado,
                s.telefono as tienda_telefono,
                
                -- Información adicional útil
                CASE 
                    WHEN sp.estado_solicitud = 'completada' THEN 'Completada'
                    WHEN sp.estado_solicitud = 'rechazada' THEN 'Rechazada'
                    WHEN sp.estado_solicitud = 'cancelada' THEN 'Cancelada'
                    ELSE sp.estado_solicitud
                END as estado_legible,
                
                DATE_FORMAT(sp.fecha_creacion, '%d/%m/%Y %H:%i') as fecha_creacion_formateada,
                DATE_FORMAT(sp.fecha_actualizacion, '%d/%m/%Y %H:%i') as fecha_actualizacion_formateada

            FROM sol_pagoservicios sp
            LEFT JOIN sucursales s ON sp.procesada_por COLLATE utf8mb4_general_ci = s.user_id COLLATE utf8mb4_general_ci
                AND s.estado = 1
                AND s.canal = 'internas'
                
            WHERE sp.fecha_actualizacion BETWEEN CONCAT(?, ' 00:00:00') AND CONCAT(?, ' 23:59:59')
              AND sp.estado = 1
              AND sp.estado_solicitud IN ('completada', 'rechazada', 'cancelada')
              
            ORDER BY sp.fecha_actualizacion DESC, sp.id DESC";
    
    // Preparar statement con solo 2 parámetros de fecha
    $stmt = mysqli_prepare($con, $query);
    if (!$stmt) {
        throw new Exception("Error preparando la consulta: " . mysqli_error($con));
    }
    
    // Bind de parámetros (solo 2 fechas)
    mysqli_stmt_bind_param($stmt, "ss", $fechaInicio, $fechaFin);
    
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Error ejecutando la consulta: " . mysqli_stmt_error($stmt));
    }
    
    $result = mysqli_stmt_get_result($stmt);
    if (!$result) {
        throw new Exception("Error obteniendo resultados: " . mysqli_stmt_error($stmt));
    }
    
    $datos = [];
    $totalMonto = 0;
    $contadorEstados = [
        'completadas' => 0,
        'rechazadas' => 0,
        'canceladas' => 0
    ];
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Convertir sicatel a booleano
        $row['sicatel'] = (bool)$row['sicatel'];
        
        // Formatear números para consistencia
        $row['monto'] = number_format((float)$row['monto'], 2, '.', '');
        $totalMonto += (float)$row['monto'];
        
        // Contar estados
        $estado = strtolower($row['estado_solicitud']);
        if (isset($contadorEstados[$estado])) {
            $contadorEstados[$estado]++;
        }
        
        // Agregar campos calculados
        $row['monto_formateado'] = '$' . number_format((float)$row['monto'], 2);
        
        $datos[] = $row;
    }
    
    mysqli_stmt_close($stmt);
    
    // Agregar estadísticas generales
    $estadisticas = [
        'total_solicitudes' => count($datos),
        'total_monto' => number_format($totalMonto, 2, '.', ''),
        'total_monto_formateado' => '$' . number_format($totalMonto, 2),
        'por_estado' => $contadorEstados,
        'tiendas_unicas' => count(array_unique(array_column($datos, 'tienda_id'))),
        'procesadores_unicos' => count(array_unique(array_filter(array_column($datos, 'procesada_por'))))
    ];
    
    // Log del resultado
    error_log("Reporte individual generado exitosamente. Total de solicitudes: " . count($datos) . ", Monto total: $" . number_format($totalMonto, 2));
    
    return [
        'solicitudes' => $datos,
        'estadisticas' => $estadisticas
    ];
}
?>