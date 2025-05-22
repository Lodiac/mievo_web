<?php
// api/get_solicitudes_pagoservicios.php
header('Content-Type: application/json; charset=UTF-8');
require_once 'db_connect.php';

try {
    // Verificar que el usuario tenga permiso
    if (!isset($_GET['uid']) || !isset($_GET['role'])) {
        throw new Exception("No autorizado");
    }

    $uid = $_GET['uid'];
    $role = $_GET['role'];
    
    // Permitir tanto a subdistribuidores como vendedores usar esta API
    if ($role !== 'subdistribuidor' && $role !== 'vendedor') {
        throw new Exception("Esta API solo está disponible para subdistribuidores y vendedores");
    }
    
    $con = conexiondb();
    
    // 1. Obtener el tipo de relación del usuario y su tienda (similar a portabilidad)
    $tipo_vendedor = 'externo'; // Default
    $tienda_usuario = null;
    
    $query_tipo = "SELECT tipo_relacion, tienda_id FROM vendedor_tienda_relacion 
                   WHERE vendedor_id = ? COLLATE utf8mb4_general_ci";
    
    $stmt_tipo = mysqli_prepare($con, $query_tipo);
    if ($stmt_tipo) {
        mysqli_stmt_bind_param($stmt_tipo, "s", $uid);
        if (mysqli_stmt_execute($stmt_tipo)) {
            $result_tipo = mysqli_stmt_get_result($stmt_tipo);
            if ($row_tipo = mysqli_fetch_assoc($result_tipo)) {
                $tipo_vendedor = $row_tipo['tipo_relacion'] ?: 'externo';
                $tienda_usuario = $row_tipo['tienda_id'];
            }
        }
        mysqli_stmt_close($stmt_tipo);
    }
    
    // 2. Array para almacenar todos los user_ids finales
    $lista_uids = [];
    $usuarios_con_tiendas = [];
    
    // 3. Agregar el usuario actual SIEMPRE
    $lista_uids[] = $uid;
    $usuarios_con_tiendas[$uid] = [
        'es_propio' => true,
        'nombre_tienda' => null,
        'tipo_relacion' => $tipo_vendedor
    ];
    
    // 4. LÓGICA PRINCIPAL POR ROLES
    if ($role === 'subdistribuidor') {
        // SUBDISTRIBUIDOR: Lógica original
        $query_vendedores = "SELECT vtr.vendedor_id, vtr.tienda_id, s.nombre_tienda, vtr.tipo_relacion
                             FROM vendedor_tienda_relacion vtr
                             LEFT JOIN sucursales s ON vtr.tienda_id = s.id
                             WHERE vtr.asignado_por = ? COLLATE utf8mb4_general_ci";
        
        $stmt_vendedores = mysqli_prepare($con, $query_vendedores);
        mysqli_stmt_bind_param($stmt_vendedores, "s", $uid);
        mysqli_stmt_execute($stmt_vendedores);
        $result_vendedores = mysqli_stmt_get_result($stmt_vendedores);
        
        while ($row = mysqli_fetch_assoc($result_vendedores)) {
            if (!in_array($row['vendedor_id'], $lista_uids)) {
                $lista_uids[] = $row['vendedor_id'];
                $usuarios_con_tiendas[$row['vendedor_id']] = [
                    'es_propio' => false,
                    'nombre_tienda' => $row['nombre_tienda'],
                    'tipo_relacion' => $row['tipo_relacion']
                ];
            }
        }
        mysqli_stmt_close($stmt_vendedores);
        
    } elseif ($tipo_vendedor === 'interno') {
        // VENDEDOR INTERNO: Query inversa desde su tienda
        
        if ($tienda_usuario) {
            // Obtener tiendas externas asignadas a su tienda
            $query_externas_vendedor = "SELECT DISTINCT tienda_externa_id 
                                       FROM atencion_clientes 
                                       WHERE tienda_interna_id = ? AND estado = 1";
            
            $stmt_externas_vendedor = mysqli_prepare($con, $query_externas_vendedor);
            mysqli_stmt_bind_param($stmt_externas_vendedor, "i", $tienda_usuario);
            mysqli_stmt_execute($stmt_externas_vendedor);
            $result_externas_vendedor = mysqli_stmt_get_result($stmt_externas_vendedor);
            
            $tiendas_externas_vendedor = [];
            while ($row = mysqli_fetch_assoc($result_externas_vendedor)) {
                $tiendas_externas_vendedor[] = $row['tienda_externa_id'];
            }
            mysqli_stmt_close($stmt_externas_vendedor);
            
            // Query inversa: usuarios de tiendas externas
            if (!empty($tiendas_externas_vendedor)) {
                $tiendas_externas_str = implode(',', array_map('intval', $tiendas_externas_vendedor));
                
                $query_usuarios_externas = "SELECT DISTINCT 
                                           vtr.vendedor_id, 
                                           vtr.asignado_por, 
                                           vtr.tienda_id,
                                           s.nombre_tienda,
                                           vtr.tipo_relacion
                                           FROM vendedor_tienda_relacion vtr
                                           LEFT JOIN sucursales s ON vtr.tienda_id = s.id
                                           WHERE vtr.tienda_id IN ($tiendas_externas_str)";
                
                $result_usuarios_externas = mysqli_query($con, $query_usuarios_externas);
                
                if ($result_usuarios_externas) {
                    while ($row = mysqli_fetch_assoc($result_usuarios_externas)) {
                        // Agregar vendedor_id
                        if (!in_array($row['vendedor_id'], $lista_uids)) {
                            $lista_uids[] = $row['vendedor_id'];
                            $usuarios_con_tiendas[$row['vendedor_id']] = [
                                'es_propio' => false,
                                'nombre_tienda' => $row['nombre_tienda'] . ' (Externa)',
                                'tipo_relacion' => $row['tipo_relacion']
                            ];
                        }
                        
                        // Agregar asignado_por (subdistribuidor)
                        if ($row['asignado_por'] && !in_array($row['asignado_por'], $lista_uids)) {
                            $lista_uids[] = $row['asignado_por'];
                            $usuarios_con_tiendas[$row['asignado_por']] = [
                                'es_propio' => false,
                                'nombre_tienda' => 'Subdistribuidor de ' . $row['nombre_tienda'],
                                'tipo_relacion' => 'externo'
                            ];
                        }
                    }
                }
            }
        }
    }
    
    // 5. Obtener nombre de tienda del usuario actual si no lo tiene
    if ($tienda_usuario && (!isset($usuarios_con_tiendas[$uid]['nombre_tienda']) || !$usuarios_con_tiendas[$uid]['nombre_tienda'])) {
        $query_tienda_usuario = "SELECT nombre_tienda FROM sucursales WHERE id = ?";
        $stmt_tienda_usuario = mysqli_prepare($con, $query_tienda_usuario);
        mysqli_stmt_bind_param($stmt_tienda_usuario, "i", $tienda_usuario);
        mysqli_stmt_execute($stmt_tienda_usuario);
        $result_tienda_usuario = mysqli_stmt_get_result($stmt_tienda_usuario);
        
        if ($row_tienda = mysqli_fetch_assoc($result_tienda_usuario)) {
            $usuarios_con_tiendas[$uid]['nombre_tienda'] = $row_tienda['nombre_tienda'];
        }
        mysqli_stmt_close($stmt_tienda_usuario);
    }
    
    // 6. Construir string para consulta IN
    $lista_uids_str = "'" . implode("','", array_map(function($id) use ($con) {
        return mysqli_real_escape_string($con, $id);
    }, $lista_uids)) . "'";
    
    // 7. CONSULTA ÚNICA A SOLICITUDES
    $query_solicitudes = "SELECT 
                         id, user_id, user_name, proveedor, tipo_servicio, cuenta, monto, numero_contacto, 
                         estado_solicitud, fecha_creacion, comentarios, 
                         estado
                         FROM sol_pagoservicios 
                         WHERE user_id COLLATE utf8mb4_general_ci IN ($lista_uids_str)
                         AND estado = 1 
                         ORDER BY fecha_creacion DESC";
    
    $result_solicitudes = mysqli_query($con, $query_solicitudes);
    
    if (!$result_solicitudes) {
        throw new Exception("Error en la consulta de solicitudes: " . mysqli_error($con));
    }
    
    $solicitudes = [];
    while ($row = mysqli_fetch_assoc($result_solicitudes)) {
        // Enriquecer con información de tienda
        $user_info = $usuarios_con_tiendas[$row['user_id']] ?? [
            'es_propio' => false,
            'nombre_tienda' => 'Tienda no identificada',
            'tipo_relacion' => 'externo'
        ];
        
        $row['es_propia'] = ($row['user_id'] == $uid) ? 1 : 0;
        $row['nombre_tienda'] = $user_info['nombre_tienda'] ?: 'Sin tienda asignada';
        $row['tipo_relacion_solicitud'] = $user_info['tipo_relacion'];
        
        // Agregar indicador especial para solicitudes propias
        if ($row['es_propia'] == 1) {
            if ($tipo_vendedor === 'interno') {
                $row['nombre_tienda'] = ($row['nombre_tienda'] ?: 'Tu solicitud') . ' (INTERNO)';
            } else {
                $row['nombre_tienda'] = $row['nombre_tienda'] ?: 'Tu solicitud';
            }
        }
        
        $solicitudes[] = $row;
    }
    
    // 8. Estadísticas
    $estadisticas = [
        'total' => count($solicitudes),
        'pendientes' => count(array_filter($solicitudes, function($s) { 
            return strtolower($s['estado_solicitud']) == 'pendiente'; 
        })),
        'procesando' => count(array_filter($solicitudes, function($s) { 
            return strtolower($s['estado_solicitud']) == 'procesando'; 
        })),
        'completadas' => count(array_filter($solicitudes, function($s) { 
            return strtolower($s['estado_solicitud']) == 'completada'; 
        })),
        'rechazadas' => count(array_filter($solicitudes, function($s) { 
            return strtolower($s['estado_solicitud']) == 'rechazada'; 
        })),
        'canceladas' => count(array_filter($solicitudes, function($s) { 
            return strtolower($s['estado_solicitud']) == 'cancelada'; 
        }))
    ];
    
    // 9. Debug info
    $debug_info = [
        'total_usuarios_incluidos' => count($lista_uids),
        'usuarios_incluidos' => $lista_uids,
        'tipo_vendedor_actual' => $tipo_vendedor,
        'tienda_usuario_actual' => $tienda_usuario,
        'role' => $role,
        'metodo' => 'QUERY_INVERSA_PAGOS',
        'tiendas_externas_procesadas' => isset($tiendas_externas_vendedor) ? count($tiendas_externas_vendedor) : 0
    ];
    
    mysqli_close($con);
    
    // 10. Respuesta final
    echo json_encode([
        "success" => true,
        "solicitudes" => $solicitudes,
        "estadisticas" => $estadisticas,
        "role" => $role,
        "tipo_vendedor" => $tipo_vendedor,
        "debug_info" => $debug_info
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "error" => $e->getMessage()
    ]);
}
?>