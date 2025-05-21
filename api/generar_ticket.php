<?php
// api/generar_ticket.php
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
    if (!isset($input['id']) || !isset($input['uid']) || !isset($input['role'])) {
        throw new Exception("Faltan datos requeridos");
    }
    
    $solicitudId = (int) $input['id'];
    $uid = $input['uid'];
    $role = $input['role'];
    
    // Verificar que el usuario tenga permiso
    if ($role !== 'subdistribuidor' && $role !== 'vendedor') {
        throw new Exception("No tienes permiso para realizar esta acción");
    }
    
    // Conectar a la base de datos
    $con = conexiondb();
    
    // 1. Verificar que la solicitud exista y pertenezca al usuario o a sus vendedores
    $query_check = "SELECT 
                      s.*, 
                      CASE WHEN s.user_id = ? THEN 1 ELSE 0 END AS es_propia 
                    FROM 
                      sol_pagoservicios s
                    WHERE 
                      s.id = ?";
    
    $stmt_check = mysqli_prepare($con, $query_check);
    mysqli_stmt_bind_param($stmt_check, "si", $uid, $solicitudId);
    
    if (!mysqli_stmt_execute($stmt_check)) {
        throw new Exception("Error al verificar la solicitud: " . mysqli_stmt_error($stmt_check));
    }
    
    $result_check = mysqli_stmt_get_result($stmt_check);
    
    if (mysqli_num_rows($result_check) === 0) {
        // Si el usuario es subdistribuidor, verificar si la solicitud pertenece a alguno de sus vendedores
        if ($role === 'subdistribuidor') {
            mysqli_stmt_close($stmt_check);
            
            // Obtener vendedores del subdistribuidor
            $query_vendedores = "SELECT vendedor_id FROM vendedor_tienda_relacion 
                               WHERE asignado_por = ? COLLATE utf8mb4_general_ci";
            
            $stmt_vendedores = mysqli_prepare($con, $query_vendedores);
            mysqli_stmt_bind_param($stmt_vendedores, "s", $uid);
            
            if (!mysqli_stmt_execute($stmt_vendedores)) {
                throw new Exception("Error al obtener vendedores: " . mysqli_stmt_error($stmt_vendedores));
            }
            
            $result_vendedores = mysqli_stmt_get_result($stmt_vendedores);
            $vendedores = [];
            
            while ($row = mysqli_fetch_assoc($result_vendedores)) {
                $vendedores[] = $row['vendedor_id'];
            }
            
            mysqli_stmt_close($stmt_vendedores);
            
            if (empty($vendedores)) {
                throw new Exception("No se encontró la solicitud especificada");
            }
            
            // Verificar si la solicitud pertenece a alguno de los vendedores
            $vendedores_str = "'" . implode("','", array_map(function($id) use ($con) {
                return mysqli_real_escape_string($con, $id);
            }, $vendedores)) . "'";
            
            $query_check_vendedor = "SELECT * FROM sol_pagoservicios WHERE id = ? AND user_id IN ($vendedores_str)";
            $stmt_check_vendedor = mysqli_prepare($con, $query_check_vendedor);
            mysqli_stmt_bind_param($stmt_check_vendedor, "i", $solicitudId);
            
            if (!mysqli_stmt_execute($stmt_check_vendedor)) {
                throw new Exception("Error al verificar la solicitud de vendedor: " . mysqli_stmt_error($stmt_check_vendedor));
            }
            
            $result_check_vendedor = mysqli_stmt_get_result($stmt_check_vendedor);
            
            if (mysqli_num_rows($result_check_vendedor) === 0) {
                throw new Exception("No se encontró la solicitud especificada");
            }
            
            $solicitud = mysqli_fetch_assoc($result_check_vendedor);
            mysqli_stmt_close($stmt_check_vendedor);
        } else {
            throw new Exception("No se encontró la solicitud especificada");
        }
    } else {
        $solicitud = mysqli_fetch_assoc($result_check);
        mysqli_stmt_close($stmt_check);
    }
    
    // 2. Verificar cuántos tickets se han generado para esta solicitud
    $query_tickets = "SELECT COUNT(*) as total FROM ticket_generados WHERE solicitud_id = ?";
    $stmt_tickets = mysqli_prepare($con, $query_tickets);
    mysqli_stmt_bind_param($stmt_tickets, "i", $solicitudId);
    
    if (!mysqli_stmt_execute($stmt_tickets)) {
        throw new Exception("Error al verificar tickets generados: " . mysqli_stmt_error($stmt_tickets));
    }
    
    $result_tickets = mysqli_stmt_get_result($stmt_tickets);
    $row_tickets = mysqli_fetch_assoc($result_tickets);
    $tickets_generados = (int) $row_tickets['total'];
    
    mysqli_stmt_close($stmt_tickets);
    
    // 3. Verificar límite (2 tickets por solicitud)
    if ($tickets_generados >= 2) {
        // No informar directamente sobre el límite, mostrar un mensaje genérico
        throw new Exception("No se puede generar el ticket en este momento");
    }
    
    // 4. Registrar el nuevo ticket generado
    $query_insertar = "INSERT INTO ticket_generados (solicitud_id, usuario_id) VALUES (?, ?)";
    $stmt_insertar = mysqli_prepare($con, $query_insertar);
    mysqli_stmt_bind_param($stmt_insertar, "is", $solicitudId, $uid);
    
    if (!mysqli_stmt_execute($stmt_insertar)) {
        throw new Exception("Error al registrar el ticket: " . mysqli_stmt_error($stmt_insertar));
    }
    
    mysqli_stmt_close($stmt_insertar);
    
    // 5. Generar el contenido del ticket
    $timestamp = time();
    $folio = "SP-{$solicitudId}-{$timestamp}";
    $fecha_actual = date('d/m/Y H:i');
    
    $contenido_ticket = "TICKET DE PAGO DE SERVICIO\n";
    $contenido_ticket .= "---------------------------------------\n";
    $contenido_ticket .= "FOLIO: {$folio}\n";
    $contenido_ticket .= "FECHA: {$fecha_actual}\n";
    $contenido_ticket .= "---------------------------------------\n";
    $contenido_ticket .= "DETALLE DEL SERVICIO\n";
    $contenido_ticket .= "---------------------------------------\n";
    $contenido_ticket .= "PROVEEDOR: {$solicitud['proveedor']}\n";
    $contenido_ticket .= "SERVICIO: {$solicitud['tipo_servicio']}\n";
    $contenido_ticket .= "CUENTA/NÚMERO: {$solicitud['cuenta']}\n";
    $contenido_ticket .= "MONTO: $" . number_format($solicitud['monto'], 2) . "\n";
    $contenido_ticket .= "---------------------------------------\n";
    $contenido_ticket .= "ESTADO: {$solicitud['estado_solicitud']}\n";
    $contenido_ticket .= "FECHA REGISTRO: " . date('d/m/Y H:i', strtotime($solicitud['fecha_creacion'])) . "\n";
    $contenido_ticket .= "=======================================\n";
    $contenido_ticket .= "**COMPROBANTE NO FISCAL**\n";
    $contenido_ticket .= "GRACIAS POR SU PREFERENCIA\n";
    $contenido_ticket .= "=======================================";
    
    // Cerrar conexión
    mysqli_close($con);
    
    // Devolver el ticket
    echo json_encode([
        "success" => true,
        "ticket" => $contenido_ticket,
        "folio" => $folio
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
?>