<?php
// api/procesar_recarga.php
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
    
    // Validar datos
    if (!isset($input['id_movimiento']) || !isset($input['accion']) || !isset($input['uid']) || !isset($input['role'])) {
        throw new Exception("Faltan datos requeridos");
    }
    
    $idMovimiento = intval($input['id_movimiento']);
    $accion = $input['accion']; // 'aprobar' o 'rechazar'
    $uid = $input['uid'];
    $role = $input['role'];
    
    // Verificar permisos - solo root y admin pueden aprobar/rechazar
    if (!in_array($role, ['root', 'admin'])) {
        throw new Exception("No tienes permisos para realizar esta acción");
    }
    
    // Validar acción
    if (!in_array($accion, ['aprobar', 'rechazar'])) {
        throw new Exception("Acción no válida");
    }
    
    // Conectar a la base de datos
    $con = conexiondb();
    
    // Iniciar transacción
    mysqli_begin_transaction($con);
    
    try {
        // Obtener información del movimiento
        $query = "SELECT m.*, b.saldo_actual, b.id_sucursal 
                  FROM movimientos m
                  LEFT JOIN bolsas b ON m.id_bolsa_destino = b.id
                  WHERE m.id_movimiento = ? AND m.tipo = 'RECARGA' AND m.estado = 'pendiente'";
        
        $stmt = mysqli_prepare($con, $query);
        mysqli_stmt_bind_param($stmt, "i", $idMovimiento);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) === 0) {
            throw new Exception("Movimiento no encontrado o ya procesado");
        }
        
        $movimiento = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        
        $nuevoEstado = ($accion === 'aprobar') ? 'aprobado' : 'rechazado';
        
        // Si se aprueba, actualizar el saldo de la bolsa
        if ($accion === 'aprobar' && $movimiento['id_bolsa_destino']) {
            $nuevoSaldo = floatval($movimiento['saldo_actual']) + floatval($movimiento['monto']);
            
            $queryUpdateBolsa = "UPDATE bolsas SET saldo_actual = ? WHERE id = ?";
            $stmtUpdateBolsa = mysqli_prepare($con, $queryUpdateBolsa);
            mysqli_stmt_bind_param($stmtUpdateBolsa, "di", $nuevoSaldo, $movimiento['id_bolsa_destino']);
            
            if (!mysqli_stmt_execute($stmtUpdateBolsa)) {
                throw new Exception("Error al actualizar el saldo de la bolsa");
            }
            mysqli_stmt_close($stmtUpdateBolsa);
        }
        
        // Actualizar el estado del movimiento
        $queryUpdateMov = "UPDATE movimientos 
                          SET estado = ?, 
                              fecha_aprobacion = CURRENT_TIMESTAMP, 
                              aprobado_por = ?
                          WHERE id_movimiento = ?";
        
        $stmtUpdateMov = mysqli_prepare($con, $queryUpdateMov);
        mysqli_stmt_bind_param($stmtUpdateMov, "ssi", $nuevoEstado, $uid, $idMovimiento);
        
        if (!mysqli_stmt_execute($stmtUpdateMov)) {
            throw new Exception("Error al actualizar el estado del movimiento");
        }
        mysqli_stmt_close($stmtUpdateMov);
        
        // Confirmar transacción
        mysqli_commit($con);
        
        // Respuesta exitosa
        echo json_encode([
            "success" => true,
            "message" => "Movimiento " . $nuevoEstado . " correctamente",
            "nuevo_estado" => $nuevoEstado,
            "saldo_actualizado" => ($accion === 'aprobar' && isset($nuevoSaldo)) ? $nuevoSaldo : null
        ]);
        
    } catch (Exception $e) {
        // Revertir transacción en caso de error
        mysqli_rollback($con);
        throw $e;
    }
    
    // Cerrar conexión
    mysqli_close($con);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>