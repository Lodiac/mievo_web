<?php
// api/actualizar_asignacion.php - TERMINOLOGÍA LIGAR/DESLIGAR
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
    if (!isset($input['id']) || !isset($input['estado']) || !isset($input['role']) || !isset($input['user_uid'])) {
        throw new Exception("Faltan datos requeridos");
    }
    
    // Verificar permisos
    $role = $input['role'];
    $allowedRoles = ['root', 'admin', 'subdistribuidor'];

    if (!in_array($role, $allowedRoles)) {
        throw new Exception("No tienes permiso para realizar esta acción");
    }
    
    $id = (int) $input['id'];
    $estado = (int) $input['estado'];
    $userUid = $input['user_uid'];
    
    // Conectar a la base de datos
    $con = conexiondb();
    
    // Actualizar estado de la asignación
    $stmt = mysqli_prepare($con, "UPDATE atencion_clientes SET estado = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $estado, $id);
    
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Error al actualizar la asignación: " . mysqli_stmt_error($stmt));
    }
    
    // Verificar si se actualizó algún registro
    if (mysqli_stmt_affected_rows($stmt) === 0) {
        throw new Exception("No se encontró la asignación especificada");
    }
    
    // Cerrar statement y conexión
    mysqli_stmt_close($stmt);
    mysqli_close($con);
    
    // *** CAMBIO: Nueva terminología LIGAR/DESLIGAR ***
    $accion = $estado ? "ligada" : "desligada";
    $mensaje = "Asignación {$accion} correctamente";
    
    // Devolver resultado exitoso
    echo json_encode([
        "success" => true,
        "message" => $mensaje,
        "estado_nuevo" => $estado,
        "accion_realizada" => $accion,
        "nota" => $estado ? "La asignación está ahora activa" : "La asignación ha sido desligada y no aparecerá en futuras consultas"
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
?>