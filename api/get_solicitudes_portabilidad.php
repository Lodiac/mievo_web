<?php
// get_solicitudes_portabilidad.php - VERSIÓN FINAL CON pdv_supervendedor_relacion
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
    $es_subdistribuidor = false;
    
    // Verificar en vendedor_tienda_relacion
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
    
    // Verificar si es subdistribuidor en pdv_supervendedor_relacion
    $query_subdist = "SELECT pdv_id FROM pdv_supervendedor_relacion 
                      WHERE supervendedor_id = ? COLLATE utf8mb4_general_ci";
    
    $stmt_subdist = mysqli_prepare($con, $query_subdist);
    if ($stmt_subdist) {
        mysqli_stmt_bind_param($stmt_subdist, "s", $uid);
        if (mysqli_stmt_execute($stmt_subdist)) {
            $result_subdist = mysqli_stmt_get_result($stmt_subdist);
            if ($row_subdist = mysqli_fetch_assoc($result_subdist)) {
                $es_subdistribuidor = true;
                // Si es subdistribuidor pero no tiene tienda_usuario, usar pdv_id
                if (!$tienda_usuario) {
                    $tienda_usuario = $row_subdist['pdv_id'];
                }
            }
        }
        mysqli_stmt_close($stmt_subdist);
    }
    
    // 2. Array para almacenar todos los user_ids finales
    $lista_uids = [];
    $usuarios_con_tiendas = [];
    
    // 3. Agregar el usuario actual SIEMPRE
    $lista_uids[] = $uid;
    $usuarios_con_tiendas[$uid] = [
        'es_propio' => true,
        'nombre_tienda' => null,
        'tipo_relacion' => $tipo_vendedor,
        'es_subdistribuidor' => $es_subdistribuidor
    ];
    
    // 4. LÓGICA PRINCIPAL POR ROLES
    if ($role === 'subdistribuidor') {
        // SUBDISTRIBUIDOR: Usar tanto vendedor_tienda_relacion como pdv_supervendedor_relacion
        
        // Obtener vendedores directos asignados
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
                    'tipo_relacion' => $row['tipo_relacion'],
                    'es_subdistribuidor' => false
                ];
            }
        }
        mysqli_stmt_close($stmt_vendedores);
        
        // NUEVO: Obtener usuarios de la misma tienda usando pdv_supervendedor_relacion
        if ($tienda_usuario) {
            $query_misma_tienda = "SELECT DISTINCT psr.supervendedor_id, s.nombre_tienda
                                   FROM pdv_supervendedor_relacion psr
                                   LEFT JOIN sucursales s ON psr.pdv_id = s.id
                                   WHERE psr.pdv_id = ?";
            
            $stmt_misma_tienda = mysqli_prepare($con, $query_misma_tienda);
            mysqli_stmt_bind_param($stmt_misma_tienda, "i", $tienda_usuario);
            mysqli_stmt_execute($stmt_misma_tienda);
            $result_misma_tienda = mysqli_stmt_get_result($stmt_misma_tienda);
            
            while ($row = mysqli_fetch_assoc($result_misma_tienda)) {
                if (!in_array($row['supervendedor_id'], $lista_uids)) {
                    $lista_uids[] = $row['supervendedor_id'];
                    $usuarios_con_tiendas[$row['supervendedor_id']] = [
                        'es_propio' => false,
                        'nombre_tienda' => $row['nombre_tienda'] . ' (Mismo PDV)',
                        'tipo_relacion' => 'externo',
                        'es_subdistribuidor' => true
                    ];
                }
            }
            mysqli_stmt_close($stmt_misma_tienda);
        }
        
    } elseif ($role === 'admin') {
        // ADMIN: Lógica completa usando ambas tablas
        
        // Paso 1: Obtener vendedores internos directos
        $query_internos = "SELECT vtr.vendedor_id, vtr.tienda_id, s.nombre_tienda, vtr.tipo_relacion
                          FROM vendedor_tienda_relacion vtr
                          LEFT JOIN sucursales s ON vtr.tienda_id = s.id
                          WHERE vtr.asignado_por = ? COLLATE utf8mb4_general_ci 
                          AND vtr.tipo_relacion = 'interno'";
        
        $stmt_internos = mysqli_prepare($con, $query_internos);
        mysqli_stmt_bind_param($stmt_internos, "s", $uid);
        mysqli_stmt_execute($stmt_internos);
        $result_internos = mysqli_stmt_get_result($stmt_internos);
        
        $tiendas_internas = [];
        while ($row = mysqli_fetch_assoc($result_internos)) {
            if (!in_array($row['vendedor_id'], $lista_uids)) {
                $lista_uids[] = $row['vendedor_id'];
                $usuarios_con_tiendas[$row['vendedor_id']] = [
                    'es_propio' => false,
                    'nombre_tienda' => $row['nombre_tienda'],
                    'tipo_relacion' => $row['tipo_relacion'],
                    'es_subdistribuidor' => false
                ];
            }
            if ($row['tienda_id']) {
                $tiendas_internas[] = $row['tienda_id'];
            }
        }
        mysqli_stmt_close($stmt_internos);
        
        // Paso 2: Obtener subdistribuidores directos usando vendedor_tienda_relacion
        $query_subs_directos = "SELECT vtr.vendedor_id, vtr.tienda_id, s.nombre_tienda, vtr.tipo_relacion
                               FROM vendedor_tienda_relacion vtr
                               LEFT JOIN sucursales s ON vtr.tienda_id = s.id
                               WHERE vtr.asignado_por = ? COLLATE utf8mb4_general_ci 
                               AND (vtr.tipo_relacion = 'externo' OR vtr.tipo_relacion IS NULL OR vtr.tipo_relacion = '')";
        
        $stmt_subs_directos = mysqli_prepare($con, $query_subs_directos);
        mysqli_stmt_bind_param($stmt_subs_directos, "s", $uid);
        mysqli_stmt_execute($stmt_subs_directos);
        $result_subs_directos = mysqli_stmt_get_result($stmt_subs_directos);
        
        while ($row = mysqli_fetch_assoc($result_subs_directos)) {
            if (!in_array($row['vendedor_id'], $lista_uids)) {
                $lista_uids[] = $row['vendedor_id'];
                $usuarios_con_tiendas[$row['vendedor_id']] = [
                    'es_propio' => false,
                    'nombre_tienda' => $row['nombre_tienda'] . ' (Subdistribuidor)',
                    'tipo_relacion' => $row['tipo_relacion'],
                    'es_subdistribuidor' => true
                ];
            }
        }
        mysqli_stmt_close($stmt_subs_directos);
        
        // NUEVO: Obtener todos los subdistribuidores de pdv_supervendedor_relacion
        $query_todos_subs = "SELECT DISTINCT psr.supervendedor_id, s.nombre_tienda
                             FROM pdv_supervendedor_relacion psr
                             LEFT JOIN sucursales s ON psr.pdv_id = s.id";
        
        $result_todos_subs = mysqli_query($con, $query_todos_subs);
        
        if ($result_todos_subs) {
            while ($row = mysqli_fetch_assoc($result_todos_subs)) {
                if (!in_array($row['supervendedor_id'], $lista_uids)) {
                    $lista_uids[] = $row['supervendedor_id'];
                    $usuarios_con_tiendas[$row['supervendedor_id']] = [
                        'es_propio' => false,
                        'nombre_tienda' => $row['nombre_tienda'] . ' (PDV)',
                        'tipo_relacion' => 'externo',
                        'es_subdistribuidor' => true
                    ];
                }
            }
        }
        
        // Paso 3: Query inversa para tiendas externas
        if (!empty($tiendas_internas)) {
            $tiendas_internas_str = implode(',', array_map('intval', $tiendas_internas));
            
            $query_externas = "SELECT DISTINCT tienda_externa_id 
                              FROM atencion_clientes 
                              WHERE tienda_interna_id IN ($tiendas_internas_str) 
                              AND estado = 1";
            
            $result_externas = mysqli_query($con, $query_externas);
            $tiendas_externas = [];
            
            if ($result_externas) {
                while ($row = mysqli_fetch_assoc($result_externas)) {
                    $tiendas_externas[] = $row['tienda_externa_id'];
                }
                
                if (!empty($tiendas_externas)) {
                    $tiendas_externas_str = implode(',', array_map('intval', $tiendas_externas));
                    
                    // Buscar en vendedor_tienda_relacion
                    $query_usuarios_externas = "SELECT DISTINCT vtr.vendedor_id, vtr.asignado_por, 
                                               vtr.tienda_id, s.nombre_tienda, vtr.tipo_relacion
                                               FROM vendedor_tienda_relacion vtr
                                               LEFT JOIN sucursales s ON vtr.tienda_id = s.id
                                               WHERE vtr.tienda_id IN ($tiendas_externas_str)";
                    
                    $result_usuarios_externas = mysqli_query($con, $query_usuarios_externas);
                    
                    if ($result_usuarios_externas) {
                        while ($row = mysqli_fetch_assoc($result_usuarios_externas)) {
                            if (!in_array($row['vendedor_id'], $lista_uids)) {
                                $lista_uids[] = $row['vendedor_id'];
                                $usuarios_con_tiendas[$row['vendedor_id']] = [
                                    'es_propio' => false,
                                    'nombre_tienda' => $row['nombre_tienda'] . ' (Externa)',
                                    'tipo_relacion' => $row['tipo_relacion'],
                                    'es_subdistribuidor' => false
                                ];
                            }
                            
                            if ($row['asignado_por'] && !in_array($row['asignado_por'], $lista_uids)) {
                                $lista_uids[] = $row['asignado_por'];
                                $usuarios_con_tiendas[$row['asignado_por']] = [
                                    'es_propio' => false,
                                    'nombre_tienda' => 'Subdistribuidor de ' . $row['nombre_tienda'],
                                    'tipo_relacion' => 'externo',
                                    'es_subdistribuidor' => true
                                ];
                            }
                        }
                    }
                    
                    // NUEVO: Buscar también en pdv_supervendedor_relacion
                    $query_subs_externas = "SELECT DISTINCT psr.supervendedor_id, s.nombre_tienda
                                            FROM pdv_supervendedor_relacion psr
                                            LEFT JOIN sucursales s ON psr.pdv_id = s.id
                                            WHERE psr.pdv_id IN ($tiendas_externas_str)";
                    
                    $result_subs_externas = mysqli_query($con, $query_subs_externas);
                    
                    if ($result_subs_externas) {
                        while ($row = mysqli_fetch_assoc($result_subs_externas)) {
                            if (!in_array($row['supervendedor_id'], $lista_uids)) {
                                $lista_uids[] = $row['supervendedor_id'];
                                $usuarios_con_tiendas[$row['supervendedor_id']] = [
                                    'es_propio' => false,
                                    'nombre_tienda' => $row['nombre_tienda'] . ' (PDV Externa)',
                                    'tipo_relacion' => 'externo',
                                    'es_subdistribuidor' => true
                                ];
                            }
                        }
                    }
                }
            }
        }
        
    } elseif ($tipo_vendedor === 'interno') {
        // VENDEDOR INTERNO: Query inversa desde su tienda
        
        if ($tienda_usuario) {
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
            
            if (!empty($tiendas_externas_vendedor)) {
                $tiendas_externas_str = implode(',', array_map('intval', $tiendas_externas_vendedor));
                
                // Buscar en vendedor_tienda_relacion
                $query_usuarios_externas = "SELECT DISTINCT vtr.vendedor_id, vtr.asignado_por, 
                                           vtr.tienda_id, s.nombre_tienda, vtr.tipo_relacion
                                           FROM vendedor_tienda_relacion vtr
                                           LEFT JOIN sucursales s ON vtr.tienda_id = s.id
                                           WHERE vtr.tienda_id IN ($tiendas_externas_str)";
                
                $result_usuarios_externas = mysqli_query($con, $query_usuarios_externas);
                
                if ($result_usuarios_externas) {
                    while ($row = mysqli_fetch_assoc($result_usuarios_externas)) {
                        if (!in_array($row['vendedor_id'], $lista_uids)) {
                            $lista_uids[] = $row['vendedor_id'];
                            $usuarios_con_tiendas[$row['vendedor_id']] = [
                                'es_propio' => false,
                                'nombre_tienda' => $row['nombre_tienda'] . ' (Externa)',
                                'tipo_relacion' => $row['tipo_relacion'],
                                'es_subdistribuidor' => false
                            ];
                        }
                        
                        if ($row['asignado_por'] && !in_array($row['asignado_por'], $lista_uids)) {
                            $lista_uids[] = $row['asignado_por'];
                            $usuarios_con_tiendas[$row['asignado_por']] = [
                                'es_propio' => false,
                                'nombre_tienda' => 'Subdistribuidor de ' . $row['nombre_tienda'],
                                'tipo_relacion' => 'externo',
                                'es_subdistribuidor' => true
                            ];
                        }
                    }
                }
                
                // NUEVO: Buscar también en pdv_supervendedor_relacion
                $query_subs_externas = "SELECT DISTINCT psr.supervendedor_id, s.nombre_tienda
                                        FROM pdv_supervendedor_relacion psr
                                        LEFT JOIN sucursales s ON psr.pdv_id = s.id
                                        WHERE psr.pdv_id IN ($tiendas_externas_str)";
                
                $result_subs_externas = mysqli_query($con, $query_subs_externas);
                
                if ($result_subs_externas) {
                    while ($row = mysqli_fetch_assoc($result_subs_externas)) {
                        if (!in_array($row['supervendedor_id'], $lista_uids)) {
                            $lista_uids[] = $row['supervendedor_id'];
                            $usuarios_con_tiendas[$row['supervendedor_id']] = [
                                'es_propio' => false,
                                'nombre_tienda' => $row['nombre_tienda'] . ' (PDV Externa)',
                                'tipo_relacion' => 'externo',
                                'es_subdistribuidor' => true
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
                         id, user_id, nombre_completo, numero_portar, nip_portabilidad,
                         operador_destino, fecha_creacion, estado_solicitud, comentarios,
                         curp, fecha_nacimiento, numero_contacto, iccid, device_id, device_model,
                         estado
                         FROM sol_portabilidad 
                         WHERE user_id COLLATE utf8mb4_general_ci IN ($lista_uids_str)
                         ORDER BY id DESC";
    
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
            'tipo_relacion' => 'externo',
            'es_subdistribuidor' => false
        ];
        
        $row['es_propia'] = ($row['user_id'] == $uid) ? 1 : 0;
        $row['nombre_tienda'] = $user_info['nombre_tienda'] ?: 'Sin tienda asignada';
        $row['tipo_relacion_solicitud'] = $user_info['tipo_relacion'];
        $row['es_subdistribuidor'] = $user_info['es_subdistribuidor'];
        
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
            return in_array(strtolower($s['estado_solicitud']), ['procesando', 'en proceso']); 
        })),
        'completadas' => count(array_filter($solicitudes, function($s) { 
            return in_array(strtolower($s['estado_solicitud']), ['completada', 'hecho']); 
        })),
        'rechazadas' => count(array_filter($solicitudes, function($s) { 
            return in_array(strtolower($s['estado_solicitud']), ['rechazada', 'revision']); 
        })),
        'canceladas' => count(array_filter($solicitudes, function($s) { 
            return strtolower($s['estado_solicitud']) == 'cancelada'; 
        }))
    ];
    
    // 9. Debug info actualizado
    $debug_info = [
        'total_usuarios_incluidos' => count($lista_uids),
        'usuarios_incluidos' => $lista_uids,
        'tipo_vendedor_actual' => $tipo_vendedor,
        'tienda_usuario_actual' => $tienda_usuario,
        'es_subdistribuidor' => $es_subdistribuidor,
        'role' => $role,
        'metodo' => 'QUERY_INVERSA_CON_PDV_SUPERVENDEDOR',
        'tiendas_internas_procesadas' => isset($tiendas_internas) ? count($tiendas_internas) : 0,
        'tiendas_externas_procesadas' => isset($tiendas_externas) ? count($tiendas_externas) : 0
    ];
    
    mysqli_close($con);
    
    // 10. Respuesta final
    echo json_encode([
        "success" => true,
        "solicitudes" => $solicitudes,
        "estadisticas" => $estadisticas,
        "tipo_vendedor" => $tipo_vendedor,
        "es_subdistribuidor" => $es_subdistribuidor,
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