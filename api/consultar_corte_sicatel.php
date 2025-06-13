<?php
// api/consultar_corte_sicatel.php - API para consultar cortes diarios de Sicatel (Telcel y Telmex)
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
    
    // Generar consulta de corte Sicatel
    $resultado = consultarCorteSicatel($con, $uid, $fecha);
    
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
            "estado_corte" => determinarEstadoCorte($diferencia)
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
// FUNCIÓN PARA CONSULTAR CORTE SICATEL
// =====================================================

function consultarCorteSicatel($con, $uid, $fecha) {
    // Query específica para corte Sicatel - solo Telcel y Telmex procesadas por el usuario actual
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
    $contadorProveedores = [
        'telcel' => 0,
        'telmex' => 0
    ];
    $montosProveedores = [
        'telcel' => 0,
        'telmex' => 0
    ];
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Formatear monto
        $monto = (float)$row['monto'];
        $row['monto'] = number_format($monto, 2, '.', '');
        $row['monto_formateado'] = '$' . number_format($monto, 2);
        
        // Normalizar proveedor
        $proveedorNormalizado = strtolower(trim($row['proveedor']));
        
        // Sumar al total
        $totalMonto += $monto;
        
        // Contar por proveedor
        if ($proveedorNormalizado === 'telcel') {
            $contadorProveedores['telcel']++;
            $montosProveedores['telcel'] += $monto;
        } elseif ($proveedorNormalizado === 'telmex') {
            $contadorProveedores['telmex']++;
            $montosProveedores['telmex'] += $monto;
        }
        
        $solicitudes[] = $row;
    }
    
    mysqli_stmt_close($stmt);
    
    // Log del resultado
    error_log("Corte Sicatel generado exitosamente. Fecha: $fecha, Usuario: $uid, Total solicitudes: " . count($solicitudes) . ", Monto total: $" . number_format($totalMonto, 2));
    
    return [
        'fecha' => $fecha,
        'usuario_procesador' => $uid,
        'solicitudes' => $solicitudes,
        'total_solicitudes' => count($solicitudes),
        'monto_total' => $totalMonto,
        'monto_total_formateado' => '$' . number_format($totalMonto, 2),
        'proveedores' => $contadorProveedores,
        'montos' => $montosProveedores,
        'desglose' => [
            'telcel' => [
                'operaciones' => $contadorProveedores['telcel'],
                'monto' => $montosProveedores['telcel'],
                'monto_formateado' => '$' . number_format($montosProveedores['telcel'], 2),
                'porcentaje' => $totalMonto > 0 ? round(($montosProveedores['telcel'] / $totalMonto) * 100, 2) : 0
            ],
            'telmex' => [
                'operaciones' => $contadorProveedores['telmex'],
                'monto' => $montosProveedores['telmex'],
                'monto_formateado' => '$' . number_format($montosProveedores['telmex'], 2),
                'porcentaje' => $totalMonto > 0 ? round(($montosProveedores['telmex'] / $totalMonto) * 100, 2) : 0
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