<?php
// api/validar_recarga.php - VERSIÓN CORREGIDA PARA EVITAR DOBLE PROCESAMIENTO
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
    if (!isset($input['id_movimiento']) || !isset($input['decision']) || 
        !isset($input['comentarios']) || !isset($input['uid']) || !isset($input['role'])) {
        throw new Exception("Faltan datos requeridos");
    }
    
    $idMovimiento = (int) $input['id_movimiento'];
    $decision = trim($input['decision']);
    $comentarios = trim($input['comentarios']);
    $uid = $input['uid'];
    $role = $input['role'];
    
    // Log para depuración con timestamp
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] 🔧 VALIDANDO RECARGA - ID: $idMovimiento, Decisión: $decision, UID: $uid, Role: $role");
    
    // Validar decisión
    if (!in_array($decision, ['aprobado', 'rechazado'])) {
        throw new Exception("Decisión no válida. Debe ser 'aprobado' o 'rechazado'");
    }
    
    // Validar comentarios
    if (strlen($comentarios) < 10) {
        throw new Exception("Los comentarios deben tener al menos 10 caracteres");
    }
    
    if (strlen($comentarios) > 500) {
        throw new Exception("Los comentarios no pueden exceder 500 caracteres");
    }
    
    // Verificar permisos - solo root puede validar recargas
    if ($role !== 'root') {
        throw new Exception("No tienes permisos para validar solicitudes de recarga");
    }
    
    // Conectar a la base de datos
    $con = conexiondb();
    
    // Iniciar transacción
    mysqli_begin_transaction($con);
    
    try {
        // Verificar que el movimiento exista y esté pendiente
        $query_verificar = "SELECT id_movimiento, tipo, monto, user_id, estado 
                           FROM movimientos 
                           WHERE id_movimiento = ? AND tipo = 'RECARGA'";
        
        $stmt_verificar = mysqli_prepare($con, $query_verificar);
        mysqli_stmt_bind_param($stmt_verificar, "i", $idMovimiento);
        
        if (!mysqli_stmt_execute($stmt_verificar)) {
            throw new Exception("Error al verificar el movimiento: " . mysqli_stmt_error($stmt_verificar));
        }
        
        $result_verificar = mysqli_stmt_get_result($stmt_verificar);
        
        if (mysqli_num_rows($result_verificar) === 0) {
            throw new Exception("No se encontró el movimiento especificado");
        }
        
        $movimiento = mysqli_fetch_assoc($result_verificar);
        mysqli_stmt_close($stmt_verificar);
        
        // Verificar que esté en estado pendiente
        if ($movimiento['estado'] !== 'pendiente') {
            throw new Exception("Solo se pueden validar movimientos en estado pendiente. Estado actual: " . $movimiento['estado']);
        }
        
        // Preparar la fecha actual
        $fechaAprobacion = date('Y-m-d H:i:s');
        
        // *** SOLUCIÓN: ACTUALIZAR CON procesado_saldo = 1 ***
        $query_actualizar = "UPDATE movimientos 
                            SET estado = ?, 
                                fecha_aprobacion = ?, 
                                aprobado_por = ?, 
                                comentario = ?,
                                procesado_saldo = 1,
                                fecha_procesado_saldo = NOW()
                            WHERE id_movimiento = ?";
        
        $stmt_actualizar = mysqli_prepare($con, $query_actualizar);
        mysqli_stmt_bind_param($stmt_actualizar, "ssssi", 
                              $decision, $fechaAprobacion, $uid, $comentarios, $idMovimiento);
        
        if (!mysqli_stmt_execute($stmt_actualizar)) {
            throw new Exception("Error al actualizar el movimiento: " . mysqli_stmt_error($stmt_actualizar));
        }
        
        $filasAfectadas = mysqli_stmt_affected_rows($stmt_actualizar);
        mysqli_stmt_close($stmt_actualizar);
        
        if ($filasAfectadas === 0) {
            throw new Exception("No se pudo actualizar el movimiento");
        }
        
        error_log("[$timestamp] ✅ Movimiento actualizado y marcado como procesado: ID=$idMovimiento, Estado=$decision");
        
        // Si la decisión es "aprobado", actualizar el saldo de la bolsa
        if ($decision === 'aprobado') {
            $monto = (float) $movimiento['monto'];
            $userId = $movimiento['user_id'];
            
            error_log("[$timestamp] 💰 PROCESANDO APROBACIÓN - Monto: $monto, User: $userId");
            
            // Buscar la tienda del usuario para actualizar su bolsa
            $tiendaId = null;
            
            // Método 1: Buscar en pdv_supervendedor_relacion
            $query_pdv = "SELECT pdv_id FROM pdv_supervendedor_relacion 
                         WHERE supervendedor_id = ? COLLATE utf8mb4_general_ci";
            $stmt_pdv = mysqli_prepare($con, $query_pdv);
            mysqli_stmt_bind_param($stmt_pdv, "s", $userId);
            mysqli_stmt_execute($stmt_pdv);
            $result_pdv = mysqli_stmt_get_result($stmt_pdv);
            
            if (mysqli_num_rows($result_pdv) > 0) {
                $row_pdv = mysqli_fetch_assoc($result_pdv);
                $tiendaId = $row_pdv['pdv_id'];
                error_log("[$timestamp] Tienda encontrada via PDV: $tiendaId");
            }
            mysqli_stmt_close($stmt_pdv);
            
            // Método 2: Si no se encontró, buscar en vendedor_tienda_relacion
            if (!$tiendaId) {
                $query_vendedor = "SELECT tienda_id FROM vendedor_tienda_relacion 
                                  WHERE vendedor_id = ? COLLATE utf8mb4_general_ci";
                $stmt_vendedor = mysqli_prepare($con, $query_vendedor);
                mysqli_stmt_bind_param($stmt_vendedor, "s", $userId);
                mysqli_stmt_execute($stmt_vendedor);
                $result_vendedor = mysqli_stmt_get_result($stmt_vendedor);
                
                if (mysqli_num_rows($result_vendedor) > 0) {
                    $row_vendedor = mysqli_fetch_assoc($result_vendedor);
                    $tiendaId = $row_vendedor['tienda_id'];
                    error_log("[$timestamp] Tienda encontrada via vendedor_tienda_relacion: $tiendaId");
                }
                mysqli_stmt_close($stmt_vendedor);
            }
            
            // Método 3: Como último recurso, verificar si el user_id es un ID de sucursal válido
            if (!$tiendaId && is_numeric($userId)) {
                $query_sucursal_directa = "SELECT id FROM sucursales WHERE id = ? AND estado = 1";
                $stmt_sucursal = mysqli_prepare($con, $query_sucursal_directa);
                $user_id_as_int = intval($userId);
                mysqli_stmt_bind_param($stmt_sucursal, "i", $user_id_as_int);
                mysqli_stmt_execute($stmt_sucursal);
                $result_sucursal = mysqli_stmt_get_result($stmt_sucursal);
                
                if (mysqli_num_rows($result_sucursal) > 0) {
                    $tiendaId = $user_id_as_int;
                    error_log("[$timestamp] Tienda encontrada via sucursal directa: $tiendaId");
                }
                mysqli_stmt_close($stmt_sucursal);
            }
            
            if ($tiendaId) {
                // Obtener saldo actual para logging
                $query_saldo_actual = "SELECT saldo_actual FROM bolsas WHERE id_sucursal = ?";
                $stmt_saldo_actual = mysqli_prepare($con, $query_saldo_actual);
                mysqli_stmt_bind_param($stmt_saldo_actual, "i", $tiendaId);
                mysqli_stmt_execute($stmt_saldo_actual);
                $result_saldo_actual = mysqli_stmt_get_result($stmt_saldo_actual);
                
                $saldoAnterior = 0;
                if ($row_saldo = mysqli_fetch_assoc($result_saldo_actual)) {
                    $saldoAnterior = $row_saldo['saldo_actual'];
                }
                mysqli_stmt_close($stmt_saldo_actual);
                
                error_log("[$timestamp] ANTES DE SUMAR - Tienda: $tiendaId, Saldo actual: $saldoAnterior, Monto a sumar: $monto");
                
                // Actualizar el saldo de la bolsa
                $query_bolsa = "UPDATE bolsas 
                               SET saldo_actual = saldo_actual + ? 
                               WHERE id_sucursal = ?";
                
                $stmt_bolsa = mysqli_prepare($con, $query_bolsa);
                mysqli_stmt_bind_param($stmt_bolsa, "di", $monto, $tiendaId);
                
                if (!mysqli_stmt_execute($stmt_bolsa)) {
                    throw new Exception("Error al actualizar el saldo de la bolsa: " . mysqli_stmt_error($stmt_bolsa));
                }
                
                $filasAfectadasBolsa = mysqli_stmt_affected_rows($stmt_bolsa);
                mysqli_stmt_close($stmt_bolsa);
                
                if ($filasAfectadasBolsa === 0) {
                    // Si no existe la bolsa, crearla
                    $query_crear_bolsa = "INSERT INTO bolsas (id_sucursal, saldo_actual) VALUES (?, ?)";
                    $stmt_crear_bolsa = mysqli_prepare($con, $query_crear_bolsa);
                    mysqli_stmt_bind_param($stmt_crear_bolsa, "id", $tiendaId, $monto);
                    
                    if (!mysqli_stmt_execute($stmt_crear_bolsa)) {
                        throw new Exception("Error al crear la bolsa de saldo: " . mysqli_stmt_error($stmt_crear_bolsa));
                    }
                    mysqli_stmt_close($stmt_crear_bolsa);
                    
                    error_log("[$timestamp] ✅ Bolsa creada para tienda $tiendaId con saldo inicial $monto");
                } else {
                    $nuevoSaldo = $saldoAnterior + $monto;
                    error_log("[$timestamp] ✅ Saldo actualizado para tienda $tiendaId: $saldoAnterior + $monto = $nuevoSaldo");
                }
            } else {
                error_log("[$timestamp] ⚠️ ADVERTENCIA: No se pudo determinar la tienda para el usuario $userId");
                // No lanzar error, solo registrar advertencia
            }
        } else {
            error_log("[$timestamp] ❌ Recarga RECHAZADA - No se actualiza saldo");
        }
        
        // Confirmar transacción
        mysqli_commit($con);
        
        // Cerrar conexión
        mysqli_close($con);
        
        // Log de éxito
        error_log("[$timestamp] ✅ RECARGA VALIDADA EXITOSAMENTE - ID: $idMovimiento, Decisión: $decision, Por: $uid, procesado_saldo: 1");
        
        // Respuesta exitosa
        echo json_encode([
            "success" => true,
            "message" => "Solicitud validada correctamente",
            "data" => [
                "id_movimiento" => $idMovimiento,
                "decision" => $decision,
                "fecha_aprobacion" => $fechaAprobacion,
                "aprobado_por" => $uid,
                "comentarios" => $comentarios,
                "saldo_actualizado" => ($decision === 'aprobado' && isset($tiendaId)),
                "procesado_saldo_marcado" => true, // *** CONFIRMACIÓN DE LA SOLUCIÓN ***
                "tienda_id" => $tiendaId ?? null
            ]
        ]);
        
    } catch (Exception $e) {
        // Revertir transacción
        mysqli_rollback($con);
        throw $e;
    }
    
} catch (Exception $e) {
    // Log del error
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] ❌ ERROR en validar_recarga.php: " . $e->getMessage());
    
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
            "id_movimiento" => $idMovimiento ?? null,
            "decision" => $decision ?? null,
            "uid" => $uid ?? null,
            "role" => $role ?? null,
            "timestamp" => date('Y-m-d H:i:s')
        ]
    ]);
}
?>