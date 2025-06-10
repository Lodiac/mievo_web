<?php
// api/generar_reporte.php - VERSIÓN SIMPLIFICADA SOLO TIENDAS
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
    $fechaInicio = $input['fecha_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
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
    $resultado = generarReporteTiendas($con, $fechaInicio, $fechaFin);
    
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
        "datos" => $resultado['datos'],
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
// FUNCIÓN PARA GENERAR REPORTE DE TIENDAS
// =====================================================

function generarReporteTiendas($con, $fechaInicio, $fechaFin) {
    // CONSULTA CON DESGLOSE POR PROVEEDOR Y TIPO_SERVICIO
    $query = "SELECT 
                s.id,
                s.nombre_tienda,
                s.canal,
                s.tipoTienda,
                s.sicatel,
                s.encargado,
                s.telefono,
                COALESCE(b.saldo_actual, 0) as saldo_actual,
                (SELECT COUNT(*) 
                 FROM vendedor_tienda_relacion vtr 
                 WHERE vtr.tienda_id = s.id) as total_vendedores,
                
                -- === DESGLOSE POR PROVEEDOR Y SERVICIO ===
                COALESCE(sp.proveedor, 'Sin actividad') as proveedor,
                COALESCE(sp.tipo_servicio, 'Sin actividad') as tipo_servicio,
                
                -- Totales del desglose específico (proveedor + tipo_servicio)
                COALESCE(COUNT(sp.id), 0) as solicitudes_desglose,
                COALESCE(SUM(sp.monto), 0) as monto_desglose,
                COALESCE(SUM(sp.comision_subdistribuidor), 0) as comision_subdistribuidor_desglose,
                
                -- === TOTALES GENERALES DE LA TIENDA ===
                (SELECT COUNT(*) 
                 FROM sol_pagoservicios sp2 
                 WHERE sp2.procesada_por COLLATE utf8mb4_general_ci = s.user_id COLLATE utf8mb4_general_ci
                   AND sp2.fecha_actualizacion BETWEEN CONCAT(?, ' 00:00:00') AND CONCAT(?, ' 23:59:59')
                   AND sp2.estado = 1
                   AND sp2.estado_solicitud = 'completada') as total_solicitudes_tienda,
                
                (SELECT COALESCE(SUM(sp2.monto), 0) 
                 FROM sol_pagoservicios sp2 
                 WHERE sp2.procesada_por COLLATE utf8mb4_general_ci = s.user_id COLLATE utf8mb4_general_ci
                   AND sp2.fecha_actualizacion BETWEEN CONCAT(?, ' 00:00:00') AND CONCAT(?, ' 23:59:59')
                   AND sp2.estado = 1 
                   AND sp2.estado_solicitud = 'completada') as total_monto_tienda,
                   
                (SELECT COALESCE(SUM(sp2.comision_subdistribuidor), 0) 
                 FROM sol_pagoservicios sp2 
                 WHERE sp2.procesada_por COLLATE utf8mb4_general_ci = s.user_id COLLATE utf8mb4_general_ci
                   AND sp2.fecha_actualizacion BETWEEN CONCAT(?, ' 00:00:00') AND CONCAT(?, ' 23:59:59')
                   AND sp2.estado = 1 
                   AND sp2.estado_solicitud = 'completada') as total_comision_subdistribuidor_tienda

            FROM sucursales s
            LEFT JOIN bolsas b ON s.id = b.id_sucursal
            LEFT JOIN sol_pagoservicios sp ON sp.procesada_por COLLATE utf8mb4_general_ci = s.user_id COLLATE utf8mb4_general_ci
                AND sp.fecha_actualizacion BETWEEN CONCAT(?, ' 00:00:00') AND CONCAT(?, ' 23:59:59')
                AND sp.estado = 1
                AND sp.estado_solicitud = 'completada'

            WHERE s.estado = 1 
              AND s.canal = 'internas'

            GROUP BY s.id, s.nombre_tienda, s.canal, s.tipoTienda, s.sicatel, s.encargado, s.telefono, 
                     s.user_id, b.saldo_actual, sp.proveedor, sp.tipo_servicio

            ORDER BY total_monto_tienda DESC, s.nombre_tienda, sp.proveedor, sp.tipo_servicio";
    
    $stmt = mysqli_prepare($con, $query);
    mysqli_stmt_bind_param($stmt, "ssssssss", $fechaInicio, $fechaFin, $fechaInicio, $fechaFin, $fechaInicio, $fechaFin, $fechaInicio, $fechaFin);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $datos = [];
    $estadisticas = [
        'total_tiendas_activas' => 0,
        'total_tiendas_sicatel' => 0,
        'saldo_total_sistema' => 0,
        'total_vendedores_sistema' => 0,
        'total_solicitudes_periodo' => 0,
        'total_monto_periodo' => 0,
        'total_comision_subdistribuidor_periodo' => 0,
        'proveedores_activos' => [],
        'servicios_activos' => []
    ];
    
    $tiendasProcesadas = [];
    $proveedoresSet = [];
    $serviciosSet = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Convertir sicatel a booleano
        $row['sicatel'] = (bool)$row['sicatel'];
        
        // Formatear números para display
        $row['saldo_actual'] = number_format($row['saldo_actual'], 2);
        $row['monto_desglose'] = number_format($row['monto_desglose'], 2);
        $row['comision_subdistribuidor_desglose'] = number_format($row['comision_subdistribuidor_desglose'], 2);
        $row['total_monto_tienda'] = number_format($row['total_monto_tienda'], 2);
        $row['total_comision_subdistribuidor_tienda'] = number_format($row['total_comision_subdistribuidor_tienda'], 2);
        
        $datos[] = $row;
        
        // Calcular estadísticas generales (solo una vez por tienda)
        if (!isset($tiendasProcesadas[$row['id']])) {
            $estadisticas['total_tiendas_activas']++;
            $estadisticas['saldo_total_sistema'] += (float)str_replace(',', '', $row['saldo_actual']);
            $estadisticas['total_vendedores_sistema'] += $row['total_vendedores'];
            $estadisticas['total_solicitudes_periodo'] += $row['total_solicitudes_tienda'];
            $estadisticas['total_monto_periodo'] += (float)str_replace(',', '', $row['total_monto_tienda']);
            $estadisticas['total_comision_subdistribuidor_periodo'] += (float)str_replace(',', '', $row['total_comision_subdistribuidor_tienda']);
            
            if ($row['sicatel']) {
                $estadisticas['total_tiendas_sicatel']++;
            }
            
            $tiendasProcesadas[$row['id']] = true;
        }
        
        // Recopilar proveedores y servicios únicos (excluyendo "Sin actividad")
        if ($row['proveedor'] !== 'Sin actividad') {
            $proveedoresSet[$row['proveedor']] = true;
        }
        if ($row['tipo_servicio'] !== 'Sin actividad') {
            $serviciosSet[$row['tipo_servicio']] = true;
        }
    }
    
    mysqli_stmt_close($stmt);
    
    // Formatear estadísticas finales
    $estadisticas['saldo_total_sistema'] = number_format($estadisticas['saldo_total_sistema'], 2);
    $estadisticas['total_monto_periodo'] = number_format($estadisticas['total_monto_periodo'], 2);
    $estadisticas['total_comision_subdistribuidor_periodo'] = number_format($estadisticas['total_comision_subdistribuidor_periodo'], 2);
    $estadisticas['proveedores_activos'] = array_keys($proveedoresSet);
    $estadisticas['servicios_activos'] = array_keys($serviciosSet);
    
    // Calcular porcentajes
    $estadisticas['porcentaje_sicatel'] = $estadisticas['total_tiendas_activas'] > 0 
        ? round(($estadisticas['total_tiendas_sicatel'] / $estadisticas['total_tiendas_activas']) * 100, 1) . '%' 
        : '0%';
    
    return [
        'datos' => $datos,
        'estadisticas' => $estadisticas
    ];
}
?>