<?php
// api/procesar_solicitud_portabilidad.php
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
    $accion = $input['accion']; // 'iniciar', 'completar' o 'rechazar'
    
    // Log inicial
    error_log("Procesando portabilidad: ID=$idSolicitud, UID=$uid, Role=$role, Accion=$accion");
    
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
    $query_solicitud = "SELECT * FROM sol_portabilidad WHERE id = ? AND estado = 1";
    $stmt_solicitud = mysqli_prepare($con, $query_solicitud);
    mysqli_stmt_bind_param($stmt_solicitud, "i", $idSolicitud);
    mysqli_stmt_execute($stmt_solicitud);
    $result_solicitud = mysqli_stmt_get_result($stmt_solicitud);
    
    if (mysqli_num_rows($result_solicitud) === 0) {
        throw new Exception("Solicitud no encontrada o inactiva");
    }
    
    $solicitud = mysqli_fetch_assoc($result_solicitud);
    mysqli_stmt_close($stmt_solicitud);
    
    error_log("Solicitud encontrada: user_id={$solicitud['user_id']}, estado={$solicitud['estado_solicitud']}");
    
    // Verificar que la solicitud esté en el estado correcto según la acción
    if ($accion === 'iniciar') {
        if ($solicitud['estado_solicitud'] !== 'pendiente') {
            throw new Exception("La solicitud ya ha sido procesada o no está en estado pendiente");
        }
    } elseif ($accion === 'completar' || $accion === 'rechazar') {
        if ($solicitud['estado_solicitud'] !== 'procesando') {
            throw new Exception("La solicitud debe estar en estado 'procesando' para completarla o rechazarla");
        }
        
        // Validar que se incluyan comentarios
        if (!isset($input['comentarios']) || trim($input['comentarios']) === '') {
            throw new Exception("Los comentarios son requeridos para completar o rechazar la solicitud");
        }
    } else {
        throw new Exception("Acción no válida");
    }
    
    // Procesar según la acción
    if ($accion === 'iniciar') {
        // INICIAR: Cambiar a procesando
        
        $query_update = "UPDATE sol_portabilidad 
                        SET estado_solicitud = 'procesando',
                            fecha_actualizacion = CURRENT_TIMESTAMP,
                            procesada_por = ?
                        WHERE id = ?";
        
        $stmt_update = mysqli_prepare($con, $query_update);
        mysqli_stmt_bind_param($stmt_update, "si", $uid, $idSolicitud);
        
        if (!mysqli_stmt_execute($stmt_update)) {
            throw new Exception("Error al iniciar el procesamiento: " . mysqli_stmt_error($stmt_update));
        }
        
        mysqli_stmt_close($stmt_update);
        
        error_log("Solicitud iniciada exitosamente");
        
        // Respuesta exitosa para INICIAR
        echo json_encode([
            "success" => true,
            "message" => "Procesamiento iniciado correctamente",
            "nuevo_estado" => "procesando",
            "abrir_modal" => true // Indicar al frontend que abra la modal
        ]);
        
    } elseif ($accion === 'completar') {
        // COMPLETAR: Cambiar a completada con comentarios
        
        $comentarios = trim($input['comentarios']);
        
        error_log("Completando solicitud con comentarios: " . substr($comentarios, 0, 100));
        
        $query_completar = "UPDATE sol_portabilidad 
                           SET estado_solicitud = 'completada',
                               comentarios = ?,
                               procesada_por = ?,
                               fecha_actualizacion = CURRENT_TIMESTAMP
                           WHERE id = ?";
        
        $stmt_completar = mysqli_prepare($con, $query_completar);
        mysqli_stmt_bind_param($stmt_completar, "ssi", $comentarios, $uid, $idSolicitud);
        
        if (!mysqli_stmt_execute($stmt_completar)) {
            throw new Exception("Error al completar la solicitud: " . mysqli_stmt_error($stmt_completar));
        }
        
        mysqli_stmt_close($stmt_completar);
        
        error_log("Solicitud completada exitosamente");
        
        // Respuesta exitosa para COMPLETAR
        echo json_encode([
            "success" => true,
            "message" => "Solicitud completada exitosamente",
            "nuevo_estado" => "completada"
        ]);
        
    } elseif ($accion === 'rechazar') {
        // RECHAZAR: Cambiar a rechazada con comentarios
        
        $comentarios = trim($input['comentarios']);
        
        error_log("Rechazando solicitud con comentarios: " . substr($comentarios, 0, 100));
        
        $query_rechazar = "UPDATE sol_portabilidad 
                          SET estado_solicitud = 'rechazada',
                              comentarios = ?,
                              procesada_por = ?,
                              fecha_actualizacion = CURRENT_TIMESTAMP
                          WHERE id = ?";
        
        $stmt_rechazar = mysqli_prepare($con, $query_rechazar);
        mysqli_stmt_bind_param($stmt_rechazar, "ssi", $comentarios, $uid, $idSolicitud);
        
        if (!mysqli_stmt_execute($stmt_rechazar)) {
            throw new Exception("Error al rechazar la solicitud: " . mysqli_stmt_error($stmt_rechazar));
        }
        
        mysqli_stmt_close($stmt_rechazar);
        
        error_log("Solicitud rechazada exitosamente");
        
        // Respuesta exitosa para RECHAZAR
        echo json_encode([
            "success" => true,
            "message" => "Solicitud rechazada exitosamente",
            "nuevo_estado" => "rechazada"
        ]);
    }
    
    // Cerrar conexión
    mysqli_close($con);
    
} catch (Exception $e) {
    // Log del error
    error_log("Error en procesar_solicitud_portabilidad: " . $e->getMessage());
    
    // Cerrar conexión si existe
    if (isset($con)) {
        mysqli_close($con);
    }
    
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage(),
        "debug_info" => [
            "solicitud_id" => $idSolicitud ?? null,
            "accion" => $accion ?? null,
            "procesador_role" => $role ?? null,
            "timestamp" => date('Y-m-d H:i:s')
        ]
    ]);
}
?>