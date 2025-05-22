<?php
// get_solicitudes_portabilidad.php - LÓGICA COMPLETA MEJORADA
header('Content-Type: application/json; charset=UTF-8');
require_once 'db_connect.php';

try {
    // Verificaciones básicas
    if (!isset($_GET['uid'])) {
        throw new Exception("Parámetro uid requerido");
    }
    
    $uid = $_GET['uid'];
    $role = $_GET['role'] ?? 'vendedor';
    $con = conexiondb();
    
    // 1. Obtener el tipo de relación del usuario y su tienda
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
    
    // 2. Array para almacenar todos los user_ids y sus tiendas
    $usuarios_con_tiendas = [];
    
    // 3. Agregar el usuario actual
    $usuarios_con_tiendas[$uid] = [
        'es_propio' => true,
        'nombre_tienda' => null, // Se obtendrá después si tiene tienda
        'tipo_relacion' => $tipo_vendedor
    ];
    
    // 4. Si es subdistribuidor o admin, obtener sus vendedores CON nombres de tiendas
    if ($role === 'subdistribuidor' || $role === 'admin') {
        $query_vendedores = "SELECT vtr.vendedor_id, vtr.tienda_id, s.nombre_tienda, vtr.tipo_relacion
                             FROM vendedor_tienda_relacion vtr
                             LEFT JOIN sucursales s ON vtr.tienda_id = s.id
                             WHERE vtr.asignado_por = ? COLLATE utf8mb4_general_ci";
        
        $stmt_vendedores = mysqli_prepare($con, $query_vendedores);
        
        if (!$stmt_vendedores) {
            throw new Exception("Error al preparar la consulta de vendedores: " . mysqli_error($con));
        }
        
        mysqli_stmt_bind_param($stmt_vendedores, "s", $uid);
        
        if (!mysqli_stmt_execute($stmt_vendedores)) {
            throw new Exception("Error al ejecutar la consulta de vendedores: " . mysqli_stmt_error($stmt_vendedores));
        }
        
        $result_vendedores = mysqli_stmt_get_result($stmt_vendedores);
        
        while ($row = mysqli_fetch_assoc($result_vendedores)) {
            $usuarios_con_tiendas[$row['vendedor_id']] = [
                'es_propio' => false,
                'nombre_tienda' => $row['nombre_tienda'],
                'tipo_relacion' => $row['tipo_relacion']
            ];
        }
        
        mysqli_stmt_close($stmt_vendedores);
    }
    
    // 5. Si es tipo_relacion='interno', obtener tiendas externas asignadas
    if ($tipo_vendedor === 'interno' && $tienda_usuario) {
        // Obtener tiendas externas asignadas
        $query_externas = "SELECT tienda_externa_id 
                           FROM atencion_clientes 
                           WHERE tienda_interna_id = ? AND estado = 1";
        
        $stmt_externas = mysqli_prepare($con, $query_externas);
        if ($stmt_externas) {
            mysqli_stmt_bind_param($stmt_externas, "i", $tienda_usuario);
            if (mysqli_stmt_execute($stmt_externas)) {
                $result_externas = mysqli_stmt_get_result($stmt_externas);
                $tiendas_externas = [];
                
                while ($row = mysqli_fetch_assoc($result_externas)) {
                    $tiendas_externas[] = $row['tienda_externa_id'];
                }
                
                // Si hay tiendas externas asignadas, obtener sus vendedores
                if (!empty($tiendas_externas)) {
                    $tiendas_str = implode(',', array_map('intval', $tiendas_externas));
                    
                    $query_vendedores_ext = "SELECT vtr.vendedor_id, vtr.tienda_id, s.nombre_tienda, vtr.tipo_relacion
                                             FROM vendedor_tienda_relacion vtr
                                             LEFT JOIN sucursales s ON vtr.tienda_id = s.id
                                             WHERE vtr.tienda_id IN ($tiendas_str)";
                    
                    $result_vendedores_ext = mysqli_query($con, $query_vendedores_ext);
                    
                    if ($result_vendedores_ext) {
                        while ($row = mysqli_fetch_assoc($result_vendedores_ext)) {
                            // Solo agregar si no existe ya
                            if (!isset($usuarios_con_tiendas[$row['vendedor_id']])) {
                                $usuarios_con_tiendas[$row['vendedor_id']] = [
                                    'es_propio' => false,
                                    'nombre_tienda' => $row['nombre_tienda'] . ' (Externa)',
                                    'tipo_relacion' => $row['tipo_relacion']
                                ];
                            }
                        }
                    }
                }
            }
            mysqli_stmt_close($stmt_externas);
        }
    }
    
    // 6. Obtener nombre de tienda del usuario actual si tiene
    if ($tienda_usuario && !$usuarios_con_tiendas[$uid]['nombre_tienda']) {
        $query_tienda_usuario = "SELECT nombre_tienda FROM sucursales WHERE id = ?";
        $stmt_tienda_usuario = mysqli_prepare($con, $query_tienda_usuario);
        if ($stmt_tienda_usuario) {
            mysqli_stmt_bind_param($stmt_tienda_usuario, "i", $tienda_usuario);
            if (mysqli_stmt_execute($stmt_tienda_usuario)) {
                $result_tienda_usuario = mysqli_stmt_get_result($stmt_tienda_usuario);
                if ($row_tienda = mysqli_fetch_assoc($result_tienda_usuario)) {
                    $usuarios_con_tiendas[$uid]['nombre_tienda'] = $row_tienda['nombre_tienda'];
                }
            }
            mysqli_stmt_close($stmt_tienda_usuario);
        }
    }
    
    // 7. Construir lista de UIDs para la consulta IN
    $lista_uids = array_keys($usuarios_con_tiendas);
    $lista_uids_str = "'" . implode("','", array_map(function($id) use ($con) {
        return mysqli_real_escape_string($con, $id);
    }, $lista_uids)) . "'";
    
    // 8. Consulta principal de solicitudes
    $query = "SELECT 
              id, user_id, nombre_completo, numero_portar, nip_portabilidad,
              operador_destino, fecha_creacion, estado_solicitud, comentarios,
              curp, fecha_nacimiento, numero_contacto, iccid, device_id, device_model,
              estado
              FROM sol_portabilidad 
              WHERE user_id COLLATE utf8mb4_general_ci IN ($lista_uids_str)
              ORDER BY id DESC";
    
    $result = mysqli_query($con, $query);
    
    if (!$result) {
        throw new Exception("Error en la consulta: " . mysqli_error($con));
    }
    
    $solicitudes = [];
    while ($row = mysqli_fetch_assoc($result)) {
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
            $row['nombre_tienda'] = ($row['nombre_tienda'] ?: 'Tu solicitud') . 
                                   ($tipo_vendedor === 'interno' ? ' (INTERNO)' : '');
        }
        
        $solicitudes[] = $row;
    }
    
    // 9. Verificación de duplicados (por seguridad)
    $solicitudes_unicas = [];
    $ids_vistos = [];
    
    foreach ($solicitudes as $solicitud) {
        if (!in_array($solicitud['id'], $ids_vistos)) {
            $ids_vistos[] = $solicitud['id'];
            $solicitudes_unicas[] = $solicitud;
        }
    }
    
    // 10. Estadísticas mejoradas
    $estadisticas = [
        'total' => count($solicitudes_unicas),
        'pendientes' => count(array_filter($solicitudes_unicas, function($s) { 
            return strtolower($s['estado_solicitud']) == 'pendiente'; 
        })),
        'procesando' => count(array_filter($solicitudes_unicas, function($s) { 
            return in_array(strtolower($s['estado_solicitud']), ['procesando', 'en proceso']); 
        })),
        'completadas' => count(array_filter($solicitudes_unicas, function($s) { 
            return in_array(strtolower($s['estado_solicitud']), ['completada', 'hecho']); 
        })),
        'rechazadas' => count(array_filter($solicitudes_unicas, function($s) { 
            return in_array(strtolower($s['estado_solicitud']), ['rechazada', 'revision']); 
        })),
        'canceladas' => count(array_filter($solicitudes_unicas, function($s) { 
            return strtolower($s['estado_solicitud']) == 'cancelada'; 
        }))
    ];
    
    // Información adicional para debugging
    $debug_info = [
        'total_usuarios_incluidos' => count($usuarios_con_tiendas),
        'tipo_vendedor_actual' => $tipo_vendedor,
        'tienda_usuario_actual' => $tienda_usuario,
        'role' => $role
    ];
    
    mysqli_close($con);
    
    // 11. Devolver respuesta completa
    echo json_encode([
        "success" => true,
        "solicitudes" => $solicitudes_unicas,
        "estadisticas" => $estadisticas,
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