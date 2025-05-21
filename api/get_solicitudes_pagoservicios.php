<?php
// api/get_solicitudes_pagoservicios.php
header('Content-Type: application/json; charset=UTF-8');
require_once 'db_connect.php';

try {
    // Verificar que el usuario tenga permiso
    if (!isset($_GET['uid']) || !isset($_GET['role'])) {
        throw new Exception("No autorizado");
    }

    $uid = $_GET['uid'];
    $role = $_GET['role'];
    
    // Permitir tanto a subdistribuidores como vendedores usar esta API
    if ($role !== 'subdistribuidor' && $role !== 'vendedor') {
        throw new Exception("Esta API solo está disponible para subdistribuidores y vendedores");
    }
    
    $con = conexiondb();
    
    // Preparar lista de UIDs según el rol
    $lista_uids = [];
    
    if ($role === 'subdistribuidor') {
        // Para subdistribuidor: obtener sus vendedores
        $query_vendedores = "SELECT vendedor_id FROM vendedor_tienda_relacion 
                            WHERE asignado_por = ? COLLATE utf8mb4_general_ci";
        
        $stmt_vendedores = mysqli_prepare($con, $query_vendedores);
        
        if (!$stmt_vendedores) {
            throw new Exception("Error al preparar la consulta de vendedores: " . mysqli_error($con));
        }
        
        mysqli_stmt_bind_param($stmt_vendedores, "s", $uid);
        
        if (!mysqli_stmt_execute($stmt_vendedores)) {
            throw new Exception("Error al ejecutar la consulta de vendedores: " . mysqli_stmt_error($stmt_vendedores));
        }
        
        $result_vendedores = mysqli_stmt_get_result($stmt_vendedores);
        
        // Añadir el UID del subdistribuidor
        $lista_uids[] = $uid;
        
        // Añadir los UIDs de sus vendedores
        while ($row = mysqli_fetch_assoc($result_vendedores)) {
            $lista_uids[] = $row['vendedor_id'];
        }
        
        mysqli_stmt_close($stmt_vendedores);
    } else {
        // Para vendedor: solo ver sus propias solicitudes
        $lista_uids[] = $uid;
    }
    
    // Construir string para consulta IN
    $ids_str = "'" . implode("','", array_map(function($id) use ($con) {
        return mysqli_real_escape_string($con, $id);
    }, $lista_uids)) . "'";
    
    // La consulta SQL
    $query = "SELECT 
                id, user_id, user_name, proveedor, tipo_servicio, cuenta, monto, numero_contacto, 
                estado_solicitud, fecha_creacion, comentarios, 
                CASE WHEN user_id = ? THEN 1 ELSE 0 END AS es_propia 
              FROM sol_pagoservicios 
              WHERE user_id IN ($ids_str) 
              AND estado = 1 
              ORDER BY fecha_creacion DESC";
    
    $stmt = mysqli_prepare($con, $query);
    mysqli_stmt_bind_param($stmt, "s", $uid);
    
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Error al ejecutar la consulta: " . mysqli_stmt_error($stmt));
    }
    
    $result = mysqli_stmt_get_result($stmt);
    
    $solicitudes = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $solicitudes[] = $row;
    }
    
    // Estadísticas
    $estadisticas = [
        'total' => count($solicitudes),
        'pendientes' => count(array_filter($solicitudes, function($s) { 
            return strtolower($s['estado_solicitud']) == 'pendiente'; 
        })),
        'procesando' => count(array_filter($solicitudes, function($s) { 
            return strtolower($s['estado_solicitud']) == 'procesando'; 
        })),
        'completadas' => count(array_filter($solicitudes, function($s) { 
            return strtolower($s['estado_solicitud']) == 'completada'; 
        })),
        'rechazadas' => count(array_filter($solicitudes, function($s) { 
            return strtolower($s['estado_solicitud']) == 'rechazada'; 
        })),
        'canceladas' => count(array_filter($solicitudes, function($s) { 
            return strtolower($s['estado_solicitud']) == 'cancelada'; 
        }))
    ];
    
    mysqli_stmt_close($stmt);
    mysqli_close($con);
    
    // Devolver respuesta
    echo json_encode([
        "success" => true,
        "solicitudes" => $solicitudes,
        "estadisticas" => $estadisticas,
        "role" => $role  // Incluir el rol para referencia
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
?>