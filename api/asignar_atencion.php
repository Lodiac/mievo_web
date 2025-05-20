<?php
// api/asignar_atencion.php
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
    if (!isset($input['tienda_interna_id']) || !isset($input['tiendas_externas']) || !isset($input['usuario_asignacion'])) {
        throw new Exception("Faltan datos requeridos");
    }
    
    if (empty($input['tiendas_externas'])) {
        throw new Exception("Debes seleccionar al menos una tienda externa");
    }
    
    // Verificar que la tienda interna exista y sea válida
    $tiendaInternaId = (int) $input['tienda_interna_id'];
    $tiendasExternas = array_map('intval', $input['tiendas_externas']);
    $usuarioAsignacion = $input['usuario_asignacion'];
    
    // Conectar a la base de datos
    $con = conexiondb();
    
    // Iniciar transacción
    mysqli_begin_transaction($con);
    
    try {
        // Verificar que la tienda interna existe y es del tipo correcto
        $stmt = mysqli_prepare($con, "SELECT id FROM sucursales WHERE id = ? AND canal = 'internas' AND estado = 1");
        mysqli_stmt_bind_param($stmt, "i", $tiendaInternaId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) === 0) {
            throw new Exception("La tienda interna seleccionada no existe o no está activa");
        }
        
        // Contar asignaciones exitosas
        $asignacionesExitosas = 0;
        
        // Preparar consulta para verificar si ya existe la asignación
        $stmtVerificar = mysqli_prepare($con, "SELECT id, estado FROM atencion_clientes WHERE tienda_interna_id = ? AND tienda_externa_id = ?");
        
        // Preparar consulta para insertar
        $stmtInsertar = mysqli_prepare($con, "INSERT INTO atencion_clientes (tienda_interna_id, tienda_externa_id, usuario_asignacion) VALUES (?, ?, ?)");
        
        // Preparar consulta para actualizar
        $stmtActualizar = mysqli_prepare($con, "UPDATE atencion_clientes SET estado = 1 WHERE id = ?");
        
        // Procesar cada tienda externa
        foreach ($tiendasExternas as $tiendaExternaId) {
            // Verificar si ya existe la asignación
            mysqli_stmt_bind_param($stmtVerificar, "ii", $tiendaInternaId, $tiendaExternaId);
            mysqli_stmt_execute($stmtVerificar);
            $resultVerificar = mysqli_stmt_get_result($stmtVerificar);
            
            if (mysqli_num_rows($resultVerificar) > 0) {
                // Ya existe, verificar si está activa
                $row = mysqli_fetch_assoc($resultVerificar);
                if ($row['estado'] == 0) {
                    // Reactivar la asignación
                    mysqli_stmt_bind_param($stmtActualizar, "i", $row['id']);
                    if (mysqli_stmt_execute($stmtActualizar)) {
                        $asignacionesExitosas++;
                    }
                }
            } else {
                // No existe, crear nueva asignación
                mysqli_stmt_bind_param($stmtInsertar, "iis", $tiendaInternaId, $tiendaExternaId, $usuarioAsignacion);
                if (mysqli_stmt_execute($stmtInsertar)) {
                    $asignacionesExitosas++;
                }
            }
        }
        
        // Cerrar statements
        mysqli_stmt_close($stmtVerificar);
        mysqli_stmt_close($stmtInsertar);
        mysqli_stmt_close($stmtActualizar);
        
        // Confirmar transacción
        mysqli_commit($con);
        
        // Cerrar conexión
        mysqli_close($con);
        
        // Devolver resultados
        echo json_encode([
            "success" => true,
            "asignadas" => $asignacionesExitosas,
            "message" => "Se asignaron $asignacionesExitosas tiendas correctamente"
        ]);
        
    } catch (Exception $e) {
        // Revertir cambios en caso de error
        mysqli_rollback($con);
        throw $e;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
?>