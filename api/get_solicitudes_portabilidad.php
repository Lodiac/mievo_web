<?php
// get_solicitudes_portabilidad.php - NUEVA LÓGICA SIMPLIFICADA
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
    
    // Arrays para almacenar todos los user_ids finales
    $lista_uids = [];
    $usuarios_con_info = [];
    
    // Siempre agregar el usuario actual
    $lista_uids[] = $uid;
    $usuarios_con_info[$uid] = [
        'es_propio' => true,
        'nombre_tienda' => 'Solicitud propia',
        'tipo_relacion' => null,
        'tag' => 'Tú'
    ];
    
    // =============================================
    // LÓGICA POR ROL - NUEVA IMPLEMENTACIÓN SIMPLIFICADA
    // =============================================
    
    if ($role === 'subdistribuidor') {
        
        // ===========================================
        // SUBDISTRIBUIDOR
        // ===========================================
        
        // 1. Obtener su tienda principal
        $query_pdv = "SELECT pdv_id FROM pdv_supervendedor_relacion WHERE supervendedor_id = ?";
        $stmt_pdv = mysqli_prepare($con, $query_pdv);
        mysqli_stmt_bind_param($stmt_pdv, "s", $uid);
        mysqli_stmt_execute($stmt_pdv);
        $result_pdv = mysqli_stmt_get_result($stmt_pdv);
        
        if ($row_pdv = mysqli_fetch_assoc($result_pdv)) {
            $pdv_id = $row_pdv['pdv_id'];
            
            // 2. Obtener todas las tiendas/sucursales de su grupo
            $query_tiendas = "SELECT id FROM sucursales 
                             WHERE tienda_principal_id = ? OR id = ?";
            $stmt_tiendas = mysqli_prepare($con, $query_tiendas);
            mysqli_stmt_bind_param($stmt_tiendas, "ii", $pdv_id, $pdv_id);
            mysqli_stmt_execute($stmt_tiendas);
            $result_tiendas = mysqli_stmt_get_result($stmt_tiendas);
            
            $coleccion_tiendas = [];
            while ($row_tienda = mysqli_fetch_assoc($result_tiendas)) {
                $coleccion_tiendas[] = $row_tienda['id'];
            }
            mysqli_stmt_close($stmt_tiendas);
            
            // 3. Obtener vendedores de sus tiendas
            if (!empty($coleccion_tiendas)) {
                $tiendas_str = implode(',', array_map('intval', $coleccion_tiendas));
                
                $query_vendedores = "SELECT vendedor_id, tienda_id FROM vendedor_tienda_relacion 
                                   WHERE tienda_id IN ($tiendas_str) AND asignado_por = ?";
                $stmt_vendedores = mysqli_prepare($con, $query_vendedores);
                mysqli_stmt_bind_param($stmt_vendedores, "s", $uid);
                mysqli_stmt_execute($stmt_vendedores);
                $result_vendedores = mysqli_stmt_get_result($stmt_vendedores);
                
                while ($row_vendedor = mysqli_fetch_assoc($result_vendedores)) {
                    $vendedor_id = $row_vendedor['vendedor_id'];
                    if (!in_array($vendedor_id, $lista_uids)) {
                        $lista_uids[] = $vendedor_id;
                        
                        // Obtener nombre real de la tienda
                        $query_nombre_tienda = "SELECT nombre_tienda FROM sucursales WHERE id = ?";
                        $stmt_nombre_tienda = mysqli_prepare($con, $query_nombre_tienda);
                        mysqli_stmt_bind_param($stmt_nombre_tienda, "i", $row_vendedor['tienda_id']);
                        mysqli_stmt_execute($stmt_nombre_tienda);
                        $result_nombre_tienda = mysqli_stmt_get_result($stmt_nombre_tienda);
                        
                        $nombre_tienda_real = 'Tienda ID: ' . $row_vendedor['tienda_id'];
                        if ($row_nombre = mysqli_fetch_assoc($result_nombre_tienda)) {
                            $nombre_tienda_real = $row_nombre['nombre_tienda'];
                        }
                        mysqli_stmt_close($stmt_nombre_tienda);
                        
                        $usuarios_con_info[$vendedor_id] = [
                            'es_propio' => false,
                            'nombre_tienda' => $nombre_tienda_real,
                            'tipo_relacion' => 'vendedor',
                            'tag' => 'Vendedor'
                        ];
                    }
                }
                mysqli_stmt_close($stmt_vendedores);
            }
        }
        mysqli_stmt_close($stmt_pdv);
        
    } elseif ($role === 'admin') {
        
        // ===========================================
        // ADMIN
        // ===========================================
        
        // 1. Obtener su tienda interna
        $query_tienda_admin = "SELECT id FROM sucursales WHERE user_id = ? AND canal = 'internas'";
        $stmt_tienda_admin = mysqli_prepare($con, $query_tienda_admin);
        mysqli_stmt_bind_param($stmt_tienda_admin, "s", $uid);
        mysqli_stmt_execute($stmt_tienda_admin);
        $result_tienda_admin = mysqli_stmt_get_result($stmt_tienda_admin);
        
        if ($row_tienda_admin = mysqli_fetch_assoc($result_tienda_admin)) {
            $id_tienda_admin = $row_tienda_admin['id'];
            
            // 2. Obtener vendedores internos de su tienda
            $query_vendedores_internos = "SELECT vendedor_id FROM vendedor_tienda_relacion 
                                         WHERE tienda_id = ? AND tipo_relacion = 'interno'";
            $stmt_vendedores_internos = mysqli_prepare($con, $query_vendedores_internos);
            mysqli_stmt_bind_param($stmt_vendedores_internos, "i", $id_tienda_admin);
            mysqli_stmt_execute($stmt_vendedores_internos);
            $result_vendedores_internos = mysqli_stmt_get_result($stmt_vendedores_internos);
            
            while ($row_vendedor = mysqli_fetch_assoc($result_vendedores_internos)) {
                $vendedor_id = $row_vendedor['vendedor_id'];
                if (!in_array($vendedor_id, $lista_uids)) {
                    $lista_uids[] = $vendedor_id;
                    $usuarios_con_info[$vendedor_id] = [
                        'es_propio' => false,
                        'nombre_tienda' => 'Mi Tienda - Vendedor Interno',
                        'tipo_relacion' => 'interno',
                        'tag' => 'Vendedor'
                    ];
                }
            }
            mysqli_stmt_close($stmt_vendedores_internos);
            
            // 3. Obtener solicitudes de tiendas que atiende (atencion_clientes)
            $query_atencion = "SELECT tienda_externa_id, observaciones FROM atencion_clientes 
                              WHERE tienda_interna_id = ? AND estado = 1";
            $stmt_atencion = mysqli_prepare($con, $query_atencion);
            mysqli_stmt_bind_param($stmt_atencion, "i", $id_tienda_admin);
            mysqli_stmt_execute($stmt_atencion);
            $result_atencion = mysqli_stmt_get_result($stmt_atencion);
            
            while ($row_atencion = mysqli_fetch_assoc($result_atencion)) {
                $tienda_externa_id = $row_atencion['tienda_externa_id'];
                $observaciones = $row_atencion['observaciones'];
                
                if (strpos($observaciones, 'interna-interna') !== false) {
                    // BRECHA 1: Asignación interna-interna (sicatel) → tienda interna
                    
                    // 1. Obtener admin/owner de la tienda interna
                    $query_tienda_interna = "SELECT user_id, nombre_tienda FROM sucursales WHERE id = ?";
                    $stmt_tienda_interna = mysqli_prepare($con, $query_tienda_interna);
                    mysqli_stmt_bind_param($stmt_tienda_interna, "i", $tienda_externa_id);
                    mysqli_stmt_execute($stmt_tienda_interna);
                    $result_tienda_interna = mysqli_stmt_get_result($stmt_tienda_interna);
                    
                    if ($row_tienda_interna = mysqli_fetch_assoc($result_tienda_interna)) {
                        $admin_tienda_id = $row_tienda_interna['user_id'];
                        $nombre_tienda_interna = $row_tienda_interna['nombre_tienda'];
                        
                        // Agregar el admin de la tienda interna
                        if ($admin_tienda_id && !in_array($admin_tienda_id, $lista_uids)) {
                            $lista_uids[] = $admin_tienda_id;
                            $usuarios_con_info[$admin_tienda_id] = [
                                'es_propio' => false,
                                'nombre_tienda' => $nombre_tienda_interna,
                                'tipo_relacion' => 'admin_interno',
                                'tag' => 'Interna'
                            ];
                        }
                    }
                    mysqli_stmt_close($stmt_tienda_interna);
                    
                    // 2. Obtener vendedores internos de esa tienda
                    $query_vendedores_tienda_interna = "SELECT vendedor_id FROM vendedor_tienda_relacion 
                                                       WHERE tienda_id = ? AND tipo_relacion = 'interno'";
                    $stmt_vendedores_tienda_interna = mysqli_prepare($con, $query_vendedores_tienda_interna);
                    mysqli_stmt_bind_param($stmt_vendedores_tienda_interna, "i", $tienda_externa_id);
                    mysqli_stmt_execute($stmt_vendedores_tienda_interna);
                    $result_vendedores_tienda_interna = mysqli_stmt_get_result($stmt_vendedores_tienda_interna);
                    
                    while ($row_vendedor_interno = mysqli_fetch_assoc($result_vendedores_tienda_interna)) {
                        $vendedor_interno_id = $row_vendedor_interno['vendedor_id'];
                        if (!in_array($vendedor_interno_id, $lista_uids)) {
                            $lista_uids[] = $vendedor_interno_id;
                            $usuarios_con_info[$vendedor_interno_id] = [
                                'es_propio' => false,
                                'nombre_tienda' => $nombre_tienda_interna ?? "Tienda Interna ID: $tienda_externa_id",
                                'tipo_relacion' => 'vendedor_interno',
                                'tag' => 'Interna'
                            ];
                        }
                    }
                    mysqli_stmt_close($stmt_vendedores_tienda_interna);
                    
                } elseif (strpos($observaciones, 'interna-externa') !== false) {
                    // BRECHA 2: Asignación interna-externa → tienda externa (subdist + vendedores)
                    
                    // 1. Obtener subdistribuidor principal
                    $query_subdist_externo = "SELECT supervendedor_id FROM pdv_supervendedor_relacion 
                                             WHERE pdv_id = ?";
                    $stmt_subdist_externo = mysqli_prepare($con, $query_subdist_externo);
                    mysqli_stmt_bind_param($stmt_subdist_externo, "i", $tienda_externa_id);
                    mysqli_stmt_execute($stmt_subdist_externo);
                    $result_subdist_externo = mysqli_stmt_get_result($stmt_subdist_externo);
                    
                    if ($row_subdist_externo = mysqli_fetch_assoc($result_subdist_externo)) {
                        $subdist_externo_id = $row_subdist_externo['supervendedor_id'];
                        
                        // Agregar el subdistribuidor
                        if (!in_array($subdist_externo_id, $lista_uids)) {
                            $lista_uids[] = $subdist_externo_id;
                            $usuarios_con_info[$subdist_externo_id] = [
                                'es_propio' => false,
                                'nombre_tienda' => "Subdistribuidor Externa ID: $tienda_externa_id",
                                'tipo_relacion' => 'subdistribuidor_externo',
                                'tag' => 'Externa'
                            ];
                        }
                        
                        // 2. REUTILIZAR LÓGICA DE SUBDISTRIBUIDOR para obtener sus vendedores
                        
                        // Obtener todas las tiendas/sucursales del subdistribuidor externo
                        $query_tiendas_subdist = "SELECT id FROM sucursales 
                                                 WHERE tienda_principal_id = ? OR id = ?";
                        $stmt_tiendas_subdist = mysqli_prepare($con, $query_tiendas_subdist);
                        mysqli_stmt_bind_param($stmt_tiendas_subdist, "ii", $tienda_externa_id, $tienda_externa_id);
                        mysqli_stmt_execute($stmt_tiendas_subdist);
                        $result_tiendas_subdist = mysqli_stmt_get_result($stmt_tiendas_subdist);
                        
                        $coleccion_tiendas_subdist = [];
                        while ($row_tienda_subdist = mysqli_fetch_assoc($result_tiendas_subdist)) {
                            $coleccion_tiendas_subdist[] = $row_tienda_subdist['id'];
                        }
                        mysqli_stmt_close($stmt_tiendas_subdist);
                        
                        // Obtener vendedores de las tiendas del subdistribuidor externo
                        if (!empty($coleccion_tiendas_subdist)) {
                            $tiendas_subdist_str = implode(',', array_map('intval', $coleccion_tiendas_subdist));
                            
                            $query_vendedores_subdist = "SELECT vendedor_id, tienda_id FROM vendedor_tienda_relacion 
                                                       WHERE tienda_id IN ($tiendas_subdist_str) 
                                                       AND asignado_por = ?";
                            $stmt_vendedores_subdist = mysqli_prepare($con, $query_vendedores_subdist);
                            mysqli_stmt_bind_param($stmt_vendedores_subdist, "s", $subdist_externo_id);
                            mysqli_stmt_execute($stmt_vendedores_subdist);
                            $result_vendedores_subdist = mysqli_stmt_get_result($stmt_vendedores_subdist);
                            
                            while ($row_vendedor_subdist = mysqli_fetch_assoc($result_vendedores_subdist)) {
                                $vendedor_subdist_id = $row_vendedor_subdist['vendedor_id'];
                                if (!in_array($vendedor_subdist_id, $lista_uids)) {
                                    $lista_uids[] = $vendedor_subdist_id;
                                    $usuarios_con_info[$vendedor_subdist_id] = [
                                        'es_propio' => false,
                                        'nombre_tienda' => "Externa - Tienda ID: " . $row_vendedor_subdist['tienda_id'],
                                        'tipo_relacion' => 'vendedor_externo',
                                        'tag' => 'Externa'
                                    ];
                                }
                            }
                            mysqli_stmt_close($stmt_vendedores_subdist);
                        }
                    }
                    mysqli_stmt_close($stmt_subdist_externo);
                }
            }
            mysqli_stmt_close($stmt_atencion);
        }
        mysqli_stmt_close($stmt_tienda_admin);
        
    } elseif ($role === 'vendedor') {
        
        // Determinar tipo de vendedor
        $query_tipo_vendedor = "SELECT tipo_relacion, tienda_id FROM vendedor_tienda_relacion 
                               WHERE vendedor_id = ?";
        $stmt_tipo_vendedor = mysqli_prepare($con, $query_tipo_vendedor);
        mysqli_stmt_bind_param($stmt_tipo_vendedor, "s", $uid);
        mysqli_stmt_execute($stmt_tipo_vendedor);
        $result_tipo_vendedor = mysqli_stmt_get_result($stmt_tipo_vendedor);
        
        $tipo_vendedor = 'externo'; // Default
        $tienda_id = null;
        
        if ($row_tipo = mysqli_fetch_assoc($result_tipo_vendedor)) {
            $tipo_vendedor = $row_tipo['tipo_relacion'] ?: 'externo';
            $tienda_id = $row_tipo['tienda_id'];
        }
        mysqli_stmt_close($stmt_tipo_vendedor);
        
        if ($tipo_vendedor === 'interno') {
            
            // ===========================================
            // VENDEDOR INTERNO
            // ===========================================
            
            if ($tienda_id) {
                // 1. Obtener todos los vendedores internos de su tienda
                $query_vendedores_tienda = "SELECT vendedor_id FROM vendedor_tienda_relacion 
                                          WHERE tienda_id = ?";
                $stmt_vendedores_tienda = mysqli_prepare($con, $query_vendedores_tienda);
                mysqli_stmt_bind_param($stmt_vendedores_tienda, "i", $tienda_id);
                mysqli_stmt_execute($stmt_vendedores_tienda);
                $result_vendedores_tienda = mysqli_stmt_get_result($stmt_vendedores_tienda);
                
                while ($row_vendedor = mysqli_fetch_assoc($result_vendedores_tienda)) {
                    $vendedor_id = $row_vendedor['vendedor_id'];
                    if (!in_array($vendedor_id, $lista_uids)) {
                        $lista_uids[] = $vendedor_id;
                        $usuarios_con_info[$vendedor_id] = [
                            'es_propio' => false,
                            'nombre_tienda' => 'Compañero Interno',
                            'tipo_relacion' => 'interno',
                            'tag' => 'Vendedor'
                        ];
                    }
                }
                mysqli_stmt_close($stmt_vendedores_tienda);
                
                // 2. BRECHAS para vendedor interno (igual que admin)
                $query_atencion_vendedor = "SELECT tienda_externa_id, observaciones FROM atencion_clientes 
                                          WHERE tienda_interna_id = ? AND estado = 1";
                $stmt_atencion_vendedor = mysqli_prepare($con, $query_atencion_vendedor);
                mysqli_stmt_bind_param($stmt_atencion_vendedor, "i", $tienda_id);
                mysqli_stmt_execute($stmt_atencion_vendedor);
                $result_atencion_vendedor = mysqli_stmt_get_result($stmt_atencion_vendedor);
                
                while ($row_atencion = mysqli_fetch_assoc($result_atencion_vendedor)) {
                    $tienda_externa_id = $row_atencion['tienda_externa_id'];
                    $observaciones = $row_atencion['observaciones'];
                    
                    if (strpos($observaciones, 'interna-interna') !== false) {
                        // BRECHA 1: Asignación interna-interna (sicatel) → tienda interna
                        
                        // 1. Obtener admin/owner de la tienda interna
                        $query_tienda_interna_v = "SELECT user_id, nombre_tienda FROM sucursales WHERE id = ?";
                        $stmt_tienda_interna_v = mysqli_prepare($con, $query_tienda_interna_v);
                        mysqli_stmt_bind_param($stmt_tienda_interna_v, "i", $tienda_externa_id);
                        mysqli_stmt_execute($stmt_tienda_interna_v);
                        $result_tienda_interna_v = mysqli_stmt_get_result($stmt_tienda_interna_v);
                        
                        if ($row_tienda_interna_v = mysqli_fetch_assoc($result_tienda_interna_v)) {
                            $admin_tienda_id_v = $row_tienda_interna_v['user_id'];
                            $nombre_tienda_interna_v = $row_tienda_interna_v['nombre_tienda'];
                            
                            // Agregar el admin de la tienda interna
                            if ($admin_tienda_id_v && !in_array($admin_tienda_id_v, $lista_uids)) {
                                $lista_uids[] = $admin_tienda_id_v;
                                $usuarios_con_info[$admin_tienda_id_v] = [
                                    'es_propio' => false,
                                    'nombre_tienda' => $nombre_tienda_interna_v,
                                    'tipo_relacion' => 'admin_interno',
                                    'tag' => 'Interna'
                                ];
                            }
                        }
                        mysqli_stmt_close($stmt_tienda_interna_v);
                        
                        // 2. Obtener vendedores internos de esa tienda
                        $query_vendedores_tienda_interna_v = "SELECT vendedor_id FROM vendedor_tienda_relacion 
                                                             WHERE tienda_id = ? AND tipo_relacion = 'interno'";
                        $stmt_vendedores_tienda_interna_v = mysqli_prepare($con, $query_vendedores_tienda_interna_v);
                        mysqli_stmt_bind_param($stmt_vendedores_tienda_interna_v, "i", $tienda_externa_id);
                        mysqli_stmt_execute($stmt_vendedores_tienda_interna_v);
                        $result_vendedores_tienda_interna_v = mysqli_stmt_get_result($stmt_vendedores_tienda_interna_v);
                        
                        while ($row_vendedor_interno_v = mysqli_fetch_assoc($result_vendedores_tienda_interna_v)) {
                            $vendedor_interno_id_v = $row_vendedor_interno_v['vendedor_id'];
                            if (!in_array($vendedor_interno_id_v, $lista_uids)) {
                                $lista_uids[] = $vendedor_interno_id_v;
                                $usuarios_con_info[$vendedor_interno_id_v] = [
                                    'es_propio' => false,
                                    'nombre_tienda' => $nombre_tienda_interna_v ?? "Tienda Interna ID: $tienda_externa_id",
                                    'tipo_relacion' => 'vendedor_interno',
                                    'tag' => 'Interna'
                                ];
                            }
                        }
                        mysqli_stmt_close($stmt_vendedores_tienda_interna_v);
                        
                    } elseif (strpos($observaciones, 'interna-externa') !== false) {
                        // BRECHA 2: Asignación interna-externa → tienda externa (subdist + vendedores)
                        
                        // 1. Obtener subdistribuidor principal
                        $query_subdist_externo_v = "SELECT supervendedor_id FROM pdv_supervendedor_relacion 
                                                   WHERE pdv_id = ?";
                        $stmt_subdist_externo_v = mysqli_prepare($con, $query_subdist_externo_v);
                        mysqli_stmt_bind_param($stmt_subdist_externo_v, "i", $tienda_externa_id);
                        mysqli_stmt_execute($stmt_subdist_externo_v);
                        $result_subdist_externo_v = mysqli_stmt_get_result($stmt_subdist_externo_v);
                        
                        if ($row_subdist_externo_v = mysqli_fetch_assoc($result_subdist_externo_v)) {
                            $subdist_externo_id_v = $row_subdist_externo_v['supervendedor_id'];
                            
                            // Agregar el subdistribuidor
                            if (!in_array($subdist_externo_id_v, $lista_uids)) {
                                $lista_uids[] = $subdist_externo_id_v;
                                $usuarios_con_info[$subdist_externo_id_v] = [
                                    'es_propio' => false,
                                    'nombre_tienda' => "Subdistribuidor Externa ID: $tienda_externa_id",
                                    'tipo_relacion' => 'subdistribuidor_externo',
                                    'tag' => 'Externa'
                                ];
                            }
                            
                            // 2. REUTILIZAR LÓGICA DE SUBDISTRIBUIDOR para obtener sus vendedores
                            
                            // Obtener todas las tiendas/sucursales del subdistribuidor externo
                            $query_tiendas_subdist_v = "SELECT id FROM sucursales 
                                                       WHERE tienda_principal_id = ? OR id = ?";
                            $stmt_tiendas_subdist_v = mysqli_prepare($con, $query_tiendas_subdist_v);
                            mysqli_stmt_bind_param($stmt_tiendas_subdist_v, "ii", $tienda_externa_id, $tienda_externa_id);
                            mysqli_stmt_execute($stmt_tiendas_subdist_v);
                            $result_tiendas_subdist_v = mysqli_stmt_get_result($stmt_tiendas_subdist_v);
                            
                            $coleccion_tiendas_subdist_v = [];
                            while ($row_tienda_subdist_v = mysqli_fetch_assoc($result_tiendas_subdist_v)) {
                                $coleccion_tiendas_subdist_v[] = $row_tienda_subdist_v['id'];
                            }
                            mysqli_stmt_close($stmt_tiendas_subdist_v);
                            
                            // Obtener vendedores de las tiendas del subdistribuidor externo
                            if (!empty($coleccion_tiendas_subdist_v)) {
                                $tiendas_subdist_str_v = implode(',', array_map('intval', $coleccion_tiendas_subdist_v));
                                
                                $query_vendedores_subdist_v = "SELECT vendedor_id, tienda_id FROM vendedor_tienda_relacion 
                                                             WHERE tienda_id IN ($tiendas_subdist_str_v) 
                                                             AND asignado_por = ?";
                                $stmt_vendedores_subdist_v = mysqli_prepare($con, $query_vendedores_subdist_v);
                                mysqli_stmt_bind_param($stmt_vendedores_subdist_v, "s", $subdist_externo_id_v);
                                mysqli_stmt_execute($stmt_vendedores_subdist_v);
                                $result_vendedores_subdist_v = mysqli_stmt_get_result($stmt_vendedores_subdist_v);
                                
                                while ($row_vendedor_subdist_v = mysqli_fetch_assoc($result_vendedores_subdist_v)) {
                                    $vendedor_subdist_id_v = $row_vendedor_subdist_v['vendedor_id'];
                                    if (!in_array($vendedor_subdist_id_v, $lista_uids)) {
                                        $lista_uids[] = $vendedor_subdist_id_v;
                                        $usuarios_con_info[$vendedor_subdist_id_v] = [
                                            'es_propio' => false,
                                            'nombre_tienda' => "Externa - Tienda ID: " . $row_vendedor_subdist_v['tienda_id'],
                                            'tipo_relacion' => 'vendedor_externo',
                                            'tag' => 'Externa'
                                        ];
                                    }
                                }
                                mysqli_stmt_close($stmt_vendedores_subdist_v);
                            }
                        }
                        mysqli_stmt_close($stmt_subdist_externo_v);
                    }
                }
                mysqli_stmt_close($stmt_atencion_vendedor);
            }
            
        } else {
            
            // ===========================================
            // VENDEDOR EXTERNO
            // ===========================================
            
            if ($tienda_id) {
                // Obtener todos los vendedores de la misma tienda
                $query_vendedores_misma_tienda = "SELECT vendedor_id FROM vendedor_tienda_relacion 
                                                WHERE tienda_id = ?";
                $stmt_vendedores_misma_tienda = mysqli_prepare($con, $query_vendedores_misma_tienda);
                mysqli_stmt_bind_param($stmt_vendedores_misma_tienda, "i", $tienda_id);
                mysqli_stmt_execute($stmt_vendedores_misma_tienda);
                $result_vendedores_misma_tienda = mysqli_stmt_get_result($stmt_vendedores_misma_tienda);
                
                while ($row_vendedor = mysqli_fetch_assoc($result_vendedores_misma_tienda)) {
                    $vendedor_id = $row_vendedor['vendedor_id'];
                    if (!in_array($vendedor_id, $lista_uids)) {
                        $lista_uids[] = $vendedor_id;
                        $usuarios_con_info[$vendedor_id] = [
                            'es_propio' => false,
                            'nombre_tienda' => 'Misma Tienda',
                            'tipo_relacion' => 'externo',
                            'tag' => 'Vendedor'
                        ];
                    }
                }
                mysqli_stmt_close($stmt_vendedores_misma_tienda);
            }
        }
    }
    
    // =============================================
    // CONSULTA FINAL DE SOLICITUDES
    // =============================================
    
    if (empty($lista_uids)) {
        throw new Exception("No se encontraron usuarios para consultar");
    }
    
    // Construir string para consulta IN
    $lista_uids_str = "'" . implode("','", array_map(function($id) use ($con) {
        return mysqli_real_escape_string($con, $id);
    }, $lista_uids)) . "'";
    
    // Consulta final
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
    
    // Procesar solicitudes
    $solicitudes = [];
    while ($row = mysqli_fetch_assoc($result_solicitudes)) {
        // Enriquecer con información del usuario
        $user_info = $usuarios_con_info[$row['user_id']] ?? [
            'es_propio' => false,
            'nombre_tienda' => 'Tienda no identificada',
            'tipo_relacion' => 'externo',
            'tag' => 'Desconocido'
        ];
        
        $row['es_propia'] = $user_info['es_propio'] ? 1 : 0;
        $row['nombre_tienda'] = $user_info['nombre_tienda'];
        $row['tipo_relacion_solicitud'] = $user_info['tipo_relacion'];
        $row['tag_visual'] = $user_info['tag'];
        
        $solicitudes[] = $row;
    }
    
    // Calcular estadísticas
    $estadisticas = [
        'total' => count($solicitudes),
        'pendientes' => 0,
        'procesando' => 0,
        'completadas' => 0,
        'rechazadas' => 0,
        'canceladas' => 0
    ];
    
    foreach ($solicitudes as $solicitud) {
        $estado = strtolower(trim($solicitud['estado_solicitud']));
        switch($estado) {
            case 'pendiente': $estadisticas['pendientes']++; break;
            case 'procesando': case 'en proceso': $estadisticas['procesando']++; break;
            case 'completada': case 'hecho': $estadisticas['completadas']++; break;
            case 'rechazada': case 'revision': $estadisticas['rechazadas']++; break;
            case 'cancelada': $estadisticas['canceladas']++; break;
        }
    }
    
    // Determinar tipo de vendedor para la respuesta
    $tipo_vendedor_respuesta = 'externo';
    if ($role === 'vendedor' && isset($tipo_vendedor)) {
        $tipo_vendedor_respuesta = $tipo_vendedor;
    } elseif ($role === 'admin' || $role === 'subdistribuidor') {
        $tipo_vendedor_respuesta = 'interno'; // Para efectos de UI
    }
    
    // Cerrar conexión
    mysqli_close($con);
    
    // Respuesta final
    echo json_encode([
        "success" => true,
        "solicitudes" => $solicitudes,
        "estadisticas" => $estadisticas,
        "tipo_vendedor" => $tipo_vendedor_respuesta,
        "es_subdistribuidor" => ($role === 'subdistribuidor'),
        "debug_info" => [
            "total_usuarios_incluidos" => count($lista_uids),
            "usuarios_incluidos" => $lista_uids,
            "role" => $role,
            "metodo" => 'NUEVA_LOGICA_SIMPLIFICADA'
        ]
    ]);
    
} catch (Exception $e) {
    // Cerrar conexión si existe
    if (isset($con)) {
        mysqli_close($con);
    }
    
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage(),
        "debug_info" => [
            "uid" => $uid ?? null,
            "role" => $role ?? null,
            "timestamp" => date('Y-m-d H:i:s')
        ]
    ]);
}
?>