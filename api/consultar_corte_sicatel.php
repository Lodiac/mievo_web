<?php
// api/consultar_corte_sicatel.php - API para consultar cortes diarios de Sicatel con DESGLOSE POR TIPO DE SERVICIO
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
    if (!isset($input['uid']) || !isset($input['role']) || !isset($input['fecha']) || !isset($input['tipo_corte'])) {
        throw new Exception("Faltan datos requeridos: uid, role, fecha, tipo_corte");
    }
    
    $uid = $input['uid'];
    $role = $input['role'];
    $fecha = $input['fecha'];
    $montoEsperado = isset($input['monto_esperado']) ? (float)$input['monto_esperado'] : 0;
    $tipoCorte = $input['tipo_corte'];
    
    // Log para debugging
    error_log("Consultando corte Sicatel: usuario=$uid, role=$role, fecha=$fecha, monto_esperado=$montoEsperado");
    
    // Verificar permisos - todos los roles pueden hacer cortes
    $rolesPermitidos = ['root', 'admin', 'subdistribuidor', 'vendedor'];
    if (!in_array($role, $rolesPermitidos)) {
        throw new Exception("No tienes permisos para consultar cortes");
    }
    
    // Validar fecha
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        throw new Exception("Formato de fecha inválido. Use YYYY-MM-DD");
    }
    
    // Validar que no sea una fecha futura
    $fechaObj = new DateTime($fecha);
    $hoy = new DateTime();
    if ($fechaObj > $hoy) {
        throw new Exception("No se pueden consultar fechas futuras");
    }
    
    // Solo aceptar Sicatel por ahora
    if ($tipoCorte !== 'sicatel') {
        throw new Exception("Tipo de corte no soportado. Solo 'sicatel' está disponible");
    }
    
    // Conectar a la base de datos
    $con = conexiondb();
    
    // Generar consulta de corte Sicatel CON DESGLOSE POR TIPO DE SERVICIO
    $resultado = consultarCorteSicatelConDesglose($con, $uid, $fecha);
    
    // Calcular diferencias
    $diferencia = $resultado['monto_total'] - $montoEsperado;
    $porcentajeDiferencia = $montoEsperado > 0 ? (($diferencia / $montoEsperado) * 100) : 0;
    
    // Cerrar conexión
    mysqli_close($con);
    
    // Respuesta exitosa
    echo json_encode([
        "success" => true,
        "tipo_corte" => $tipoCorte,
        "fecha_consulta" => $fecha,
        "usuario_procesador" => $uid,
        "monto_esperado" => $montoEsperado,
        "data" => array_merge($resultado, [
            "diferencia" => $diferencia,
            "porcentaje_diferencia" => round($porcentajeDiferencia, 2),
            "estado_corte" => determinarEstadoCorte($diferencia),
            "fecha_hora_corte" => date('d/m/Y H:i'),
            "monto_mievo" => $resultado['monto_total'] // Para compatibilidad con frontend
        ]),
        "timestamp" => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    // Cerrar conexión si existe
    if (isset($con)) {
        mysqli_close($con);
    }
    
    error_log("Error en consultar_corte_sicatel.php: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage(),
        "timestamp" => date('Y-m-d H:i:s')
    ]);
}

// =====================================================
// FUNCIÓN PARA CONSULTAR CORTE SICATEL CON DESGLOSE POR TIPO DE SERVICIO
// =====================================================

function consultarCorteSicatelConDesglose($con, $uid, $fecha) {
    // Query principal para obtener todas las solicitudes
    $query = "SELECT 
                sp.id as solicitud_id,
                sp.user_id,
                sp.user_name,
                sp.nombre_cliente,
                sp.proveedor,
                sp.tipo_servicio,
                sp.cuenta,
                sp.monto,
                sp.fecha_creacion,
                sp.fecha_actualizacion,
                sp.procesada_por,
                sp.comentarios,
                
                -- Formatear fechas
                DATE_FORMAT(sp.fecha_creacion, '%d/%m/%Y %H:%i') as fecha_creacion_formateada,
                DATE_FORMAT(sp.fecha_actualizacion, '%d/%m/%Y %H:%i') as fecha_actualizacion_formateada

            FROM sol_pagoservicios sp
            
            WHERE DATE(sp.fecha_actualizacion) = ?
              AND sp.estado = 1
              AND sp.estado_solicitud = 'completada'
              AND sp.procesada_por = ? COLLATE utf8mb4_general_ci
              AND (UPPER(sp.proveedor) = 'TELCEL' OR UPPER(sp.proveedor) = 'TELMEX')
              
            ORDER BY sp.fecha_actualizacion DESC, sp.id DESC";
    
    // Preparar statement
    $stmt = mysqli_prepare($con, $query);
    if (!$stmt) {
        throw new Exception("Error preparando la consulta: " . mysqli_error($con));
    }
    
    // Bind de parámetros
    mysqli_stmt_bind_param($stmt, "ss", $fecha, $uid);
    
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Error ejecutando la consulta: " . mysqli_stmt_error($stmt));
    }
    
    $result = mysqli_stmt_get_result($stmt);
    if (!$result) {
        throw new Exception("Error obteniendo resultados: " . mysqli_stmt_error($stmt));
    }
    
    $solicitudes = [];
    $totalMonto = 0;
    
    // Estructura para desglose por proveedor y tipo de servicio
    $desglosePorProveedor = [
        'telcel' => [
            'total_operaciones' => 0,
            'total_monto' => 0,
            'servicios' => []
        ],
        'telmex' => [
            'total_operaciones' => 0,
            'total_monto' => 0,
            'servicios' => []
        ]
    ];
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Formatear monto
        $monto = (float)$row['monto'];
        $row['monto'] = number_format($monto, 2, '.', '');
        $row['monto_formateado'] = '$' . number_format($monto, 2);
        
        // Normalizar proveedor
        $proveedorNormalizado = strtolower(trim($row['proveedor']));
        $tipoServicio = trim($row['tipo_servicio']);
        
        // Sumar al total
        $totalMonto += $monto;
        
        // Agregar al desglose por proveedor
        if ($proveedorNormalizado === 'telcel' || $proveedorNormalizado === 'telmex') {
            // Incrementar totales del proveedor
            $desglosePorProveedor[$proveedorNormalizado]['total_operaciones']++;
            $desglosePorProveedor[$proveedorNormalizado]['total_monto'] += $monto;
            
            // Incrementar por tipo de servicio
            if (!isset($desglosePorProveedor[$proveedorNormalizado]['servicios'][$tipoServicio])) {
                $desglosePorProveedor[$proveedorNormalizado]['servicios'][$tipoServicio] = [
                    'operaciones' => 0,
                    'monto' => 0
                ];
            }
            
            $desglosePorProveedor[$proveedorNormalizado]['servicios'][$tipoServicio]['operaciones']++;
            $desglosePorProveedor[$proveedorNormalizado]['servicios'][$tipoServicio]['monto'] += $monto;
        }
        
        $solicitudes[] = $row;
    }
    
    mysqli_stmt_close($stmt);
    
    // Formatear montos y crear resumen
    foreach ($desglosePorProveedor as $proveedor => &$data) {
        $data['total_monto_formateado'] = '$' . number_format($data['total_monto'], 2);
        $data['porcentaje'] = $totalMonto > 0 ? round(($data['total_monto'] / $totalMonto) * 100, 2) : 0;
        
        // Formatear cada servicio
        foreach ($data['servicios'] as $servicio => &$servicioData) {
            $servicioData['monto_formateado'] = '$' . number_format($servicioData['monto'], 2);
            $servicioData['porcentaje_del_proveedor'] = $data['total_monto'] > 0 ? round(($servicioData['monto'] / $data['total_monto']) * 100, 2) : 0;
        }
        
        // Ordenar servicios por monto descendente
        uasort($data['servicios'], function($a, $b) {
            return $b['monto'] <=> $a['monto'];
        });
    }
    
    // Log del resultado
    error_log("Corte Sicatel con desglose generado exitosamente. Fecha: $fecha, Usuario: $uid, Total solicitudes: " . count($solicitudes) . ", Monto total: $" . number_format($totalMonto, 2));
    
    return [
        'fecha' => $fecha,
        'usuario_procesador' => $uid,
        'solicitudes' => $solicitudes,
        'total_solicitudes' => count($solicitudes),
        'monto_total' => $totalMonto,
        'monto_total_formateado' => '$' . number_format($totalMonto, 2),
        'desglose_por_proveedor' => $desglosePorProveedor,
        
        // Mantener compatibilidad con versión anterior
        'proveedores' => [
            'telcel' => $desglosePorProveedor['telcel']['total_operaciones'],
            'telmex' => $desglosePorProveedor['telmex']['total_operaciones']
        ],
        'montos' => [
            'telcel' => $desglosePorProveedor['telcel']['total_monto'],
            'telmex' => $desglosePorProveedor['telmex']['total_monto']
        ],
        'desglose' => [
            'telcel' => [
                'operaciones' => $desglosePorProveedor['telcel']['total_operaciones'],
                'monto' => $desglosePorProveedor['telcel']['total_monto'],
                'monto_formateado' => $desglosePorProveedor['telcel']['total_monto_formateado'],
                'porcentaje' => $desglosePorProveedor['telcel']['porcentaje']
            ],
            'telmex' => [
                'operaciones' => $desglosePorProveedor['telmex']['total_operaciones'],
                'monto' => $desglosePorProveedor['telmex']['total_monto'],
                'monto_formateado' => $desglosePorProveedor['telmex']['total_monto_formateado'],
                'porcentaje' => $desglosePorProveedor['telmex']['porcentaje']
            ]
        ]
    ];
}

// =====================================================
// FUNCIÓN PARA DETERMINAR ESTADO DEL CORTE
// =====================================================

function determinarEstadoCorte($diferencia) {
    $tolerancia = 0.01; // 1 centavo de tolerancia
    
    if (abs($diferencia) <= $tolerancia) {
        return [
            'status' => 'exacto',
            'descripcion' => 'Monto exacto',
            'icono' => 'fas fa-check-circle',
            'color' => '#28a745'
        ];
    } elseif ($diferencia > 0) {
        return [
            'status' => 'excedente',
            'descripcion' => 'Excedente',
            'icono' => 'fas fa-arrow-up',
            'color' => '#007bff'
        ];
    } else {
        return [
            'status' => 'faltante',
            'descripcion' => 'Faltante',
            'icono' => 'fas fa-arrow-down',
            'color' => '#dc3545'
        ];
    }
}
?>