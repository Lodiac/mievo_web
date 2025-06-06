<?php
// api/actualizar_sicatel.php
header('Content-Type: application/json; charset=UTF-8');
require_once 'db_connect.php';

// Manejo de errores para asegurar respuestas JSON válidas
try {
    // Verificar método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Método no permitido");
    }
    
    // Obtener datos del cuerpo de la solicitud
    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true);
    
    // Validar datos requeridos
    if (!isset($input['tienda_ids']) || !isset($input['sicatel']) || !isset($input['user_uid']) || !isset($input['role'])) {
        throw new Exception("Faltan datos requeridos");
    }
    
    $tiendaIds = $input['tienda_ids'];
    $sicatel = (bool) $input['sicatel'];
    $userUid = $input['user_uid'];
    $role = $input['role'];
    
    // Log inicial
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] 🔧 ACTUALIZANDO SICATEL - IDs: " . implode(',', $tiendaIds) . ", Sicatel: " . ($sicatel ? 'true' : 'false') . ", Usuario: $userUid, Role: $role");
    
    // Validar que sea un array de IDs
    if (!is_array($tiendaIds) || empty($tiendaIds)) {
        throw new Exception("Debe proporcionar al menos un ID de tienda válido");
    }
    
    // Validar IDs
    $tiendaIds = array_map('intval', $tiendaIds);
    $tiendaIds = array_filter($tiendaIds, function($id) { return $id > 0; });
    
    if (empty($tiendaIds)) {
        throw new Exception("No se proporcionaron IDs de tienda válidos");
    }
    
    // Verificar permisos - solo root y admin
    $rolesPermitidos = ['root', 'admin'];
    if (!in_array($role, $rolesPermitidos)) {
        throw new Exception("No tienes permisos para modificar configuraciones de sicatel");
    }
    
    // Conectar a la base de datos
    $con = conexiondb();
    
    // Iniciar transacción
    mysqli_begin_transaction($con);
    
    try {
        // Verificar que todas las tiendas existan y sean internas
        $tiendaIdsStr = implode(',', $tiendaIds);
        $queryVerificar = "SELECT id, nombre_tienda, canal, sicatel FROM sucursales 
                           WHERE id IN ($tiendaIdsStr) AND estado = 1";
        
        $resultVerificar = mysqli_query($con, $queryVerificar);
        
        if (!$resultVerificar) {
            throw new Exception("Error al verificar tiendas: " . mysqli_error($con));
        }
        
        $tiendasEncontradas = [];
        $tiendasValidas = [];
        $errores = [];
        
        while ($row = mysqli_fetch_assoc($resultVerificar)) {
            $tiendasEncontradas[] = $row;
            
            // Verificar que sea tienda interna
            if ($row['canal'] !== 'internas') {
                $errores[] = "Tienda '{$row['nombre_tienda']}' no es una tienda interna";
                continue;
            }
            
            // Verificar si ya tiene el estado deseado
            $tieneStatusActual = (bool) $row['sicatel'];
            if ($tieneStatusActual === $sicatel) {
                error_log("[$timestamp] ℹ️ Tienda '{$row['nombre_tienda']}' ya tiene sicatel " . ($sicatel ? 'habilitado' : 'deshabilitado'));
                // No es error, pero la incluimos en válidas para el conteo
            }
            
            $tiendasValidas[] = $row['id'];
        }
        
        // Verificar que encontramos todas las tiendas solicitadas
        if (count($tiendasEncontradas) !== count($tiendaIds)) {
            $idsNoEncontrados = array_diff($tiendaIds, array_column($tiendasEncontradas, 'id'));
            $errores[] = "Tiendas no encontradas: " . implode(', ', $idsNoEncontrados);
        }
        
        if (!empty($errores)) {
            throw new Exception("Errores de validación: " . implode('; ', $errores));
        }
        
        if (empty($tiendasValidas)) {
            throw new Exception("No hay tiendas válidas para actualizar");
        }
        
        // *** ACTUALIZACIÓN MASIVA DE SICATEL ***
        $sicatelValue = $sicatel ? 1 : 0;
        $tiendasValidasStr = implode(',', $tiendasValidas);
        
        $queryUpdate = "UPDATE sucursales 
                        SET sicatel = ?, 
                            updated_at = CURRENT_TIMESTAMP 
                        WHERE id IN ($tiendasValidasStr) 
                        AND canal = 'internas' 
                        AND estado = 1";
        
        $stmtUpdate = mysqli_prepare($con, $queryUpdate);
        mysqli_stmt_bind_param($stmtUpdate, "i", $sicatelValue);
        
        if (!mysqli_stmt_execute($stmtUpdate)) {
            throw new Exception("Error al actualizar sicatel: " . mysqli_stmt_error($stmtUpdate));
        }
        
        $filasAfectadas = mysqli_stmt_affected_rows($stmtUpdate);
        mysqli_stmt_close($stmtUpdate);
        
        error_log("[$timestamp] ✅ Sicatel actualizado: $filasAfectadas tienda(s) afectada(s)");
        
        // *** LOG DE AUDITORÍA (opcional) ***
        if ($filasAfectadas > 0) {
            $accion = $sicatel ? 'HABILITAR_SICATEL' : 'DESHABILITAR_SICATEL';
            $comentario = "Actualización masiva de sicatel - Usuario: $userUid, Tiendas: " . implode(',', $tiendasValidas);
            
            // Insertar en tabla de auditoría si existe
            $queryAuditoria = "INSERT INTO auditoria_sicatel 
                              (accion, tienda_ids, usuario, comentario, fecha) 
                              VALUES (?, ?, ?, ?, NOW())";
            
            $stmtAuditoria = mysqli_prepare($con, $queryAuditoria);
            if ($stmtAuditoria) {
                $tiendaIdsJson = json_encode($tiendasValidas);
                mysqli_stmt_bind_param($stmtAuditoria, "ssss", $accion, $tiendaIdsJson, $userUid, $comentario);
                
                if (mysqli_stmt_execute($stmtAuditoria)) {
                    error_log("[$timestamp] 📝 Auditoría registrada para actualización sicatel");
                } else {
                    error_log("[$timestamp] ⚠️ No se pudo registrar auditoría: " . mysqli_stmt_error($stmtAuditoria));
                }
                
                mysqli_stmt_close($stmtAuditoria);
            } else {
                error_log("[$timestamp] ℹ️ Tabla de auditoría no disponible");
            }
        }
        
        // Confirmar transacción
        mysqli_commit($con);
        
        // Obtener estadísticas actualizadas
        $queryStats = "SELECT 
                        COUNT(*) as total,
                        SUM(sicatel) as con_sicatel,
                        (COUNT(*) - SUM(sicatel)) as sin_sicatel,
                        ROUND((SUM(sicatel) / COUNT(*)) * 100, 2) as porcentaje
                       FROM sucursales 
                       WHERE canal = 'internas' AND estado = 1";
        
        $resultStats = mysqli_query($con, $queryStats);
        $estadisticas = [];
        
        if ($resultStats && $rowStats = mysqli_fetch_assoc($resultStats)) {
            $estadisticas = [
                'total' => (int) $rowStats['total'],
                'con_sicatel' => (int) $rowStats['con_sicatel'],
                'sin_sicatel' => (int) $rowStats['sin_sicatel'],
                'porcentaje' => (float) $rowStats['porcentaje']
            ];
        }
        
        // Cerrar conexión
        mysqli_close($con);
        
        // Respuesta exitosa
        echo json_encode([
            "success" => true,
            "message" => "Sicatel actualizado correctamente",
            "data" => [
                "tiendas_procesadas" => count($tiendasValidas),
                "tiendas_afectadas" => $filasAfectadas,
                "sicatel_habilitado" => $sicatel,
                "usuario" => $userUid,
                "timestamp" => $timestamp
            ],
            "estadisticas" => $estadisticas,
            "detalles" => [
                "tiendas_solicitadas" => count($tiendaIds),
                "tiendas_encontradas" => count($tiendasEncontradas),
                "tiendas_validas" => count($tiendasValidas),
                "tiendas_actualizadas" => $filasAfectadas
            ]
        ]);
        
        error_log("[$timestamp] 🎉 SICATEL ACTUALIZADO EXITOSAMENTE - Procesadas: " . count($tiendasValidas) . ", Afectadas: $filasAfectadas");
        
    } catch (Exception $e) {
        // Revertir transacción
        mysqli_rollback($con);
        throw $e;
    }
    
} catch (Exception $e) {
    // Log del error
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] ❌ ERROR en actualizar_sicatel.php: " . $e->getMessage());
    
    // Cerrar conexión si existe
    if (isset($con)) {
        mysqli_close($con);
    }
    
    // Respuesta de error
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage(),
        "debug_info" => [
            "archivo" => "actualizar_sicatel.php",
            "linea" => $e->getLine(),
            "tienda_ids" => $tiendaIds ?? null,
            "sicatel" => $sicatel ?? null,
            "user_uid" => $userUid ?? null,
            "role" => $role ?? null,
            "timestamp" => date('Y-m-d H:i:s')
        ]
    ]);
}
?>
