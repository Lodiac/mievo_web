<?php
// api/procesar_solicitud_pago.php - VERSIÓN FINAL MEJORADA
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
    
    // Validar datos básicos
    if (!isset($input['id_solicitud']) || !isset($input['uid']) || !isset($input['role']) || !isset($input['accion'])) {
        throw new Exception("Faltan datos requeridos");
    }
    
    $idSolicitud = (int) $input['id_solicitud'];
    $uid = $input['uid'];
    $role = $input['role'];
    $accion = $input['accion']; // 'iniciar' o 'completar'
    
    // Log inicial
    error_log("Procesando solicitud: ID=$idSolicitud, UID=$uid, Role=$role, Accion=$accion");
    
    // Verificar permisos - solo admin y vendedores internos
    if ($role !== 'admin') {
        // Para vendedores, verificar que sean internos
        $con = conexiondb();
        $query_tipo = "SELECT tipo_relacion FROM vendedor_tienda_relacion 
                       WHERE vendedor_id = ? COLLATE utf8mb4_general_ci";
        $stmt_tipo = mysqli_prepare($con, $query_tipo);
        mysqli_stmt_bind_param($stmt_tipo, "s", $uid);
        mysqli_stmt_execute($stmt_tipo);
        $result_tipo = mysqli_stmt_get_result($stmt_tipo);
        
        if (!$result_tipo || mysqli_num_rows($result_tipo) === 0) {
            throw new Exception("Usuario no autorizado para procesar solicitudes");
        }
        
        $row_tipo = mysqli_fetch_assoc($result_tipo);
        if ($row_tipo['tipo_relacion'] !== 'interno') {
            throw new Exception("Solo vendedores internos pueden procesar solicitudes");
        }
        mysqli_stmt_close($stmt_tipo);
        mysqli_close($con);
    }
    
    // Conectar a la base de datos
    $con = conexiondb();
    
    // Obtener información de la solicitud
    $query_solicitud = "SELECT * FROM sol_pagoservicios WHERE id = ? AND estado = 1";
    $stmt_solicitud = mysqli_prepare($con, $query_solicitud);
    mysqli_stmt_bind_param($stmt_solicitud, "i", $idSolicitud);
    mysqli_stmt_execute($stmt_solicitud);
    $result_solicitud = mysqli_stmt_get_result($stmt_solicitud);
    
    if (mysqli_num_rows($result_solicitud) === 0) {
        throw new Exception("Solicitud no encontrada o inactiva");
    }
    
    $solicitud = mysqli_fetch_assoc($result_solicitud);
    mysqli_stmt_close($stmt_solicitud);
    
    error_log("Solicitud encontrada: user_id={$solicitud['user_id']}, estado={$solicitud['estado_solicitud']}, monto={$solicitud['monto']}");
    
    // Verificar que la solicitud esté en el estado correcto según la acción
    if ($accion === 'iniciar') {
        if ($solicitud['estado_solicitud'] !== 'pendiente') {
            throw new Exception("La solicitud ya ha sido procesada o no está en estado pendiente");
        }
    } elseif ($accion === 'completar') {
        if ($solicitud['estado_solicitud'] !== 'procesando') {
            throw new Exception("La solicitud debe estar en estado 'procesando' para completarla");
        }
        
        // Validar que se incluyan comentarios
        if (!isset($input['comentarios']) || trim($input['comentarios']) === '') {
            throw new Exception("Los comentarios son requeridos para completar la solicitud");
        }
    } else {
        throw new Exception("Acción no válida");
    }
    
    // Procesar según la acción
    if ($accion === 'iniciar') {
        // Verificar si es solicitud propia
        $esSolicitudPropia = ($solicitud['user_id'] === $uid);
        
        // Variables para tracking
        $tiendaId = null;
        $metodo_tienda = null;
        $saldoActual = null;
        $nuevoSaldo = null;
        $montoSolicitud = (float) $solicitud['monto'];
        
        error_log("Solicitud propia: " . ($esSolicitudPropia ? 'SI' : 'NO'));
        
        if (!$esSolicitudPropia) {
            // BÚSQUEDA OPTIMIZADA DE TIENDA usando múltiples métodos
            
            // Método 1: Buscar en pdv_supervendedor_relacion (subdistribuidores)
            $query_pdv = "SELECT pdv_id FROM pdv_supervendedor_relacion 
                         WHERE supervendedor_id = ? COLLATE utf8mb4_general_ci";
            $stmt_pdv = mysqli_prepare($con, $query_pdv);
            mysqli_stmt_bind_param($stmt_pdv, "s", $solicitud['user_id']);
            mysqli_stmt_execute($stmt_pdv);
            $result_pdv = mysqli_stmt_get_result($stmt_pdv);
            
            if (mysqli_num_rows($result_pdv) > 0) {
                $row_pdv = mysqli_fetch_assoc($result_pdv);
                $tiendaId = $row_pdv['pdv_id'];
                $metodo_tienda = 'pdv_supervendedor_relacion';
                error_log("Tienda encontrada via PDV: $tiendaId");
            }
            mysqli_stmt_close($stmt_pdv);
            
            // Método 2: Si no se encontró, buscar en vendedor_tienda_relacion (vendedores normales)
            if (!$tiendaId) {
                $query_vendedor = "SELECT tienda_id FROM vendedor_tienda_relacion 
                                  WHERE vendedor_id = ? COLLATE utf8mb4_general_ci";
                $stmt_vendedor = mysqli_prepare($con, $query_vendedor);
                mysqli_stmt_bind_param($stmt_vendedor, "s", $solicitud['user_id']);
                mysqli_stmt_execute($stmt_vendedor);
                $result_vendedor = mysqli_stmt_get_result($stmt_vendedor);
                
                if (mysqli_num_rows($result_vendedor) > 0) {
                    $row_vendedor = mysqli_fetch_assoc($result_vendedor);
                    $tiendaId = $row_vendedor['tienda_id'];
                    $metodo_tienda = 'vendedor_tienda_relacion';
                    error_log("Tienda encontrada via vendedor_tienda_relacion: $tiendaId");
                }
                mysqli_stmt_close($stmt_vendedor);
            }
            
            // Método 3: Como último recurso, verificar si el user_id es un ID de sucursal válido
            if (!$tiendaId && is_numeric($solicitud['user_id'])) {
                $query_sucursal_directa = "SELECT id FROM sucursales WHERE id = ? AND estado = 1";
                $stmt_sucursal = mysqli_prepare($con, $query_sucursal_directa);
                $user_id_as_int = intval($solicitud['user_id']);
                mysqli_stmt_bind_param($stmt_sucursal, "i", $user_id_as_int);
                mysqli_stmt_execute($stmt_sucursal);
                $result_sucursal = mysqli_stmt_get_result($stmt_sucursal);
                
                if (mysqli_num_rows($result_sucursal) > 0) {
                    $tiendaId = $user_id_as_int;
                    $metodo_tienda = 'sucursal_directa';
                    error_log("Tienda encontrada via sucursal directa: $tiendaId");
                }
                mysqli_stmt_close($stmt_sucursal);
            }
            
            if (!$tiendaId) {
                throw new Exception("No se pudo determinar la tienda de la solicitud. Usuario: " . $solicitud['user_id']);
            }
            
            // Verificar saldo en la tabla bolsas
            $query_saldo = "SELECT saldo_actual FROM bolsas WHERE id_sucursal = ?";
            $stmt_saldo = mysqli_prepare($con, $query_saldo);
            mysqli_stmt_bind_param($stmt_saldo, "i", $tiendaId);
            mysqli_stmt_execute($stmt_saldo);
            $result_saldo = mysqli_stmt_get_result($stmt_saldo);
            
            if (mysqli_num_rows($result_saldo) === 0) {
                throw new Exception("No se encontró información de saldo para la tienda ID: " . $tiendaId);
            }
            
            $row_saldo = mysqli_fetch_assoc($result_saldo);
            $saldoActual = (float) $row_saldo['saldo_actual'];
            mysqli_stmt_close($stmt_saldo);
            
            error_log("Saldo actual: $saldoActual, Monto requerido: $montoSolicitud");
            
            // Validar que el saldo sea suficiente
            if ($saldoActual < $montoSolicitud) {
                throw new Exception("Saldo insuficiente. Saldo disponible: $" . number_format($saldoActual, 2) . 
                                  ", Monto requerido: $" . number_format($montoSolicitud, 2));
            }
            
            // Descontar el saldo
            $nuevoSaldo = $saldoActual - $montoSolicitud;
            $query_update_saldo = "UPDATE bolsas SET saldo_actual = ? WHERE id_sucursal = ?";
            $stmt_update_saldo = mysqli_prepare($con, $query_update_saldo);
            mysqli_stmt_bind_param($stmt_update_saldo, "di", $nuevoSaldo, $tiendaId);
            
            if (!mysqli_stmt_execute($stmt_update_saldo)) {
                throw new Exception("Error al actualizar el saldo: " . mysqli_stmt_error($stmt_update_saldo));
            }
            mysqli_stmt_close($stmt_update_saldo);
            
            error_log("Saldo actualizado: $saldoActual -> $nuevoSaldo");
        } else {
            $metodo_tienda = 'solicitud_propia';
            error_log("Solicitud propia, no se requiere descuento de saldo");
        }
        
        // Actualizar solicitud a "procesando"
        $query_update_solicitud = "UPDATE sol_pagoservicios 
                                  SET estado_solicitud = 'procesando' 
                                  WHERE id = ?";
        $stmt_update_solicitud = mysqli_prepare($con, $query_update_solicitud);
        mysqli_stmt_bind_param($stmt_update_solicitud, "i", $idSolicitud);
        
        if (!mysqli_stmt_execute($stmt_update_solicitud)) {
            throw new Exception("Error al actualizar el estado de la solicitud: " . mysqli_stmt_error($stmt_update_solicitud));
        }
        mysqli_stmt_close($stmt_update_solicitud);
        
        // Respuesta exitosa
        echo json_encode([
            "success" => true,
            "message" => $esSolicitudPropia ? 
                "Solicitud propia iniciada correctamente" : 
                "Solicitud iniciada y saldo descontado correctamente",
            "nuevo_estado" => "procesando",
            "saldo_descontado" => !$esSolicitudPropia,
            "detalles" => [
                "tienda_id" => $tiendaId,
                "metodo_tienda" => $metodo_tienda,
                "saldo_anterior" => $saldoActual,
                "saldo_actual" => $nuevoSaldo,
                "monto_descontado" => $esSolicitudPropia ? 0 : $montoSolicitud,
                "es_solicitud_propia" => $esSolicitudPropia
            ]
        ]);
        
    } elseif ($accion === 'completar') {
        $comentarios = trim($input['comentarios']);
        
        error_log("Completando solicitud con comentarios: " . substr($comentarios, 0, 100));
        
        // Actualizar solicitud a "completada" con comentarios y procesada_por
        $query_completar = "UPDATE sol_pagoservicios 
                           SET estado_solicitud = 'completada',
                               comentarios = ?,
                               procesada_por = ?
                           WHERE id = ?";
        $stmt_completar = mysqli_prepare($con, $query_completar);
        mysqli_stmt_bind_param($stmt_completar, "ssi", $comentarios, $uid, $idSolicitud);
        
        if (!mysqli_stmt_execute($stmt_completar)) {
            throw new Exception("Error al completar la solicitud: " . mysqli_stmt_error($stmt_completar));
        }
        mysqli_stmt_close($stmt_completar);
        
        error_log("Solicitud completada exitosamente");
        
        // Respuesta exitosa
        echo json_encode([
            "success" => true,
            "message" => "Solicitud completada exitosamente",
            "nuevo_estado" => "completada",
            "detalles" => [
                "comentarios_agregados" => strlen($comentarios) . " caracteres",
                "procesada_por" => $uid
            ]
        ]);
    }
    
    // Cerrar conexión
    mysqli_close($con);
    
} catch (Exception $e) {
    // Log del error
    error_log("Error en procesar_solicitud_pago: " . $e->getMessage());
    
    // En caso de error, revertir cambios si es necesario
    if (isset($con)) {
        mysqli_rollback($con);
        mysqli_close($con);
    }
    
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage(),
        "debug_info" => [
            "solicitud_id" => $idSolicitud ?? null,
            "user_id_solicitud" => isset($solicitud) ? $solicitud['user_id'] : null,
            "accion" => $accion ?? null,
            "es_solicitud_propia" => isset($esSolicitudPropia) ? $esSolicitudPropia : null,
            "tienda_encontrada" => isset($tiendaId) ? $tiendaId : null,
            "metodo_tienda" => isset($metodo_tienda) ? $metodo_tienda : null,
            "timestamp" => date('Y-m-d H:i:s')
        ]
    ]);
}
?>