<?php
// api/asignar_atencion.php - VERSIÓN CORREGIDA
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
    
    // Validar datos
    if (!isset($input['tienda_origen_id']) || !isset($input['tiendas_destino']) || !isset($input['usuario_asignacion'])) {
        throw new Exception("Faltan datos requeridos");
    }
    
    if (empty($input['tiendas_destino'])) {
        throw new Exception("Debes seleccionar al menos una tienda destino");
    }
    
    // Verificar que la tienda origen exista y sea válida
    $tiendaOrigenId = (int) $input['tienda_origen_id'];
    $tiendasDestino = array_map('intval', $input['tiendas_destino']);
    $usuarioAsignacion = $input['usuario_asignacion'];
    
    // Conectar a la base de datos
    $con = conexiondb();
    
    // Iniciar transacción
    mysqli_begin_transaction($con);
    
    try {
        // *** NUEVA LÓGICA: Obtener información completa de la tienda origen ***
        $stmt = mysqli_prepare($con, "SELECT id, nombre_tienda, canal, sicatel FROM sucursales WHERE id = ? AND estado = 1");
        mysqli_stmt_bind_param($stmt, "i", $tiendaOrigenId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) === 0) {
            throw new Exception("La tienda origen seleccionada no existe o no está activa");
        }
        
        $tiendaOrigen = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        
        // Log para debugging
        error_log("Tienda origen: ID={$tiendaOrigen['id']}, Canal={$tiendaOrigen['canal']}, Sicatel={$tiendaOrigen['sicatel']}");
        
        // Validar capacidades de la tienda origen
        $puedeAsignarInternas = ($tiendaOrigen['canal'] === 'internas' && $tiendaOrigen['sicatel'] == 1);
        $puedeAsignarExternas = ($tiendaOrigen['canal'] === 'internas'); // Cualquier tienda interna puede atender externas
        
        if (!$puedeAsignarInternas && !$puedeAsignarExternas) {
            throw new Exception("Esta tienda no tiene capacidades para dar atención al cliente");
        }
        
        // Contar asignaciones exitosas
        $asignacionesExitosas = 0;
        $detallesAsignaciones = [];
        $asignacionesOmitidas = [];
        
        // Preparar consultas
        $stmtVerificar = mysqli_prepare($con, "SELECT id, estado FROM atencion_clientes WHERE tienda_interna_id = ? AND tienda_externa_id = ?");
        // *** CORRECCIÓN CRÍTICA: Agregar campo estado con valor 1 ***
        $stmtInsertar = mysqli_prepare($con, "INSERT INTO atencion_clientes (tienda_interna_id, tienda_externa_id, usuario_asignacion, observaciones, estado) VALUES (?, ?, ?, ?, 1)");
        $stmtReactivar = mysqli_prepare($con, "UPDATE atencion_clientes SET estado = 1 WHERE id = ?");
        
        // Procesar cada tienda destino
        foreach ($tiendasDestino as $tiendaDestinoId) {
            
            // *** NUEVA VALIDACIÓN: Obtener información de la tienda destino ***
            $stmtDestino = mysqli_prepare($con, "SELECT id, nombre_tienda, canal, sicatel FROM sucursales WHERE id = ? AND estado = 1");
            mysqli_stmt_bind_param($stmtDestino, "i", $tiendaDestinoId);
            mysqli_stmt_execute($stmtDestino);
            $resultDestino = mysqli_stmt_get_result($stmtDestino);
            
            if (mysqli_num_rows($resultDestino) === 0) {
                $detallesAsignaciones[] = "Tienda destino ID $tiendaDestinoId no existe o no está activa";
                mysqli_stmt_close($stmtDestino);
                continue;
            }
            
            $tiendaDestino = mysqli_fetch_assoc($resultDestino);
            mysqli_stmt_close($stmtDestino);
            
            // *** APLICAR NUEVAS REGLAS DE ASIGNACIÓN ***
            $asignacionValida = false;
            $tipoAsignacion = '';
            $observaciones = '';
            
            if ($tiendaOrigen['canal'] === 'internas' && $tiendaDestino['canal'] === 'internas') {
                // REGLA: Interna → Interna (origen debe tener sicatel)
                if ($tiendaOrigen['sicatel'] == 1) {
                    $asignacionValida = true;
                    $tipoAsignacion = 'interna_a_interna';
                    $observaciones = 'Asignación interna-interna (sicatel)';
                } else {
                    $detallesAsignaciones[] = "Tienda origen '{$tiendaOrigen['nombre_tienda']}' necesita sicatel para atender tiendas internas";
                    continue;
                }
                
            } elseif ($tiendaOrigen['canal'] === 'internas' && $tiendaDestino['canal'] === 'externas') {
                // REGLA: Interna → Externa (siempre permitido)
                $asignacionValida = true;
                $tipoAsignacion = 'interna_a_externa';
                $observaciones = 'Asignación interna-externa';
                
            } elseif ($tiendaOrigen['canal'] === 'externas' && $tiendaDestino['canal'] === 'internas') {
                // REGLA: Externa → Interna (destino debe tener sicatel)
                if ($tiendaDestino['sicatel'] == 1) {
                    $asignacionValida = true;
                    $tipoAsignacion = 'externa_a_interna';
                    $observaciones = 'Asignación externa-interna (sicatel)';
                } else {
                    $detallesAsignaciones[] = "Tienda destino '{$tiendaDestino['nombre_tienda']}' necesita sicatel para recibir asignaciones de tiendas externas";
                    continue;
                }
                
            } else {
                // REGLA: Externa → Externa (NO permitido)
                $detallesAsignaciones[] = "No se puede asignar tienda externa a otra tienda externa";
                continue;
            }
            
            if (!$asignacionValida) {
                continue;
            }
            
            // Log para debugging
            error_log("Asignación válida: {$tipoAsignacion} - Origen: {$tiendaOrigen['nombre_tienda']} → Destino: {$tiendaDestino['nombre_tienda']}");
            
            // *** IMPORTANTE: Ajustar campos según el tipo de asignación ***
            // Para mantener compatibilidad con la tabla actual:
            // - tienda_interna_id: siempre la que DA atención
            // - tienda_externa_id: siempre la que RECIBE atención
            
            $tiendaQueDAatencion = null;
            $tiendaQueRECIBEatencion = null;
            
            if ($tipoAsignacion === 'interna_a_interna' || $tipoAsignacion === 'interna_a_externa') {
                $tiendaQueDAatencion = $tiendaOrigenId;
                $tiendaQueRECIBEatencion = $tiendaDestinoId;
            } elseif ($tipoAsignacion === 'externa_a_interna') {
                $tiendaQueDAatencion = $tiendaDestinoId; // La interna es la que DA atención
                $tiendaQueRECIBEatencion = $tiendaOrigenId; // La externa es la que RECIBE atención
            }
            
            // *** NUEVA LÓGICA: Verificar si ya existe asignación ACTIVA ***
            mysqli_stmt_bind_param($stmtVerificar, "ii", $tiendaQueDAatencion, $tiendaQueRECIBEatencion);
            mysqli_stmt_execute($stmtVerificar);
            $resultVerificar = mysqli_stmt_get_result($stmtVerificar);
            
            if (mysqli_num_rows($resultVerificar) > 0) {
                // Ya existe, verificar estado
                $row = mysqli_fetch_assoc($resultVerificar);
                if ($row['estado'] == 1) {
                    // *** CAMBIO: Si ya está activa, omitir (no mostrar en tiendas disponibles) ***
                    $asignacionesOmitidas[] = "Ya existe asignación activa: {$tiendaOrigen['nombre_tienda']} → {$tiendaDestino['nombre_tienda']}";
                    continue;
                } else {
                    // Está inactiva, reactivar
                    mysqli_stmt_bind_param($stmtReactivar, "i", $row['id']);
                    if (mysqli_stmt_execute($stmtReactivar)) {
                        $asignacionesExitosas++;
                        $detallesAsignaciones[] = "Reactivada: {$tiendaOrigen['nombre_tienda']} → {$tiendaDestino['nombre_tienda']} ($tipoAsignacion)";
                    }
                }
            } else {
                // No existe, crear nueva asignación
                mysqli_stmt_bind_param($stmtInsertar, "iiss", $tiendaQueDAatencion, $tiendaQueRECIBEatencion, $usuarioAsignacion, $observaciones);
                if (mysqli_stmt_execute($stmtInsertar)) {
                    $asignacionesExitosas++;
                    $detallesAsignaciones[] = "Creada: {$tiendaOrigen['nombre_tienda']} → {$tiendaDestino['nombre_tienda']} ($tipoAsignacion)";
                }
            }
        }
        
        // Cerrar statements
        mysqli_stmt_close($stmtVerificar);
        mysqli_stmt_close($stmtInsertar);
        mysqli_stmt_close($stmtReactivar);
        
        // Confirmar transacción
        mysqli_commit($con);
        
        // Cerrar conexión
        mysqli_close($con);
        
        // Devolver resultados
        echo json_encode([
            "success" => true,
            "asignadas" => $asignacionesExitosas,
            "message" => "Se procesaron las asignaciones correctamente",
            "detalles" => $detallesAsignaciones,
            "omitidas" => $asignacionesOmitidas, // *** NUEVO: Información sobre asignaciones omitidas ***
            "reglas_aplicadas" => [
                "tienda_origen" => [
                    "nombre" => $tiendaOrigen['nombre_tienda'],
                    "canal" => $tiendaOrigen['canal'],
                    "sicatel" => (bool)$tiendaOrigen['sicatel']
                ],
                "puede_asignar_internas" => $puedeAsignarInternas,
                "puede_asignar_externas" => $puedeAsignarExternas
            ]
        ]);
        
    } catch (Exception $e) {
        // Revertir cambios en caso de error
        mysqli_rollback($con);
        throw $e;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "error" => $e->getMessage(),
        "debug_info" => [
            "archivo" => "asignar_atencion.php",
            "linea" => $e->getLine(),
            "timestamp" => date('Y-m-d H:i:s')
        ]
    ]);
}
?>