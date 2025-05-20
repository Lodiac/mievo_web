<?php
// api/get_solicitudes_portabilidad.php
header('Content-Type: application/json; charset=UTF-8');
require_once 'db_connect.php';

// Manejo de errores para asegurar respuestas JSON válidas
try {
    // Verificar que existe el parámetro uid y role
    if (!isset($_GET['uid'])) {
        throw new Exception("Parámetro uid requerido");
    }
    
    // Si no hay role, asumir 'subdistribuidor' como valor por defecto
    $role = isset($_GET['role']) ? $_GET['role'] : 'subdistribuidor';
    
    // Validar que el role sea válido
    $validRoles = ['root', 'admin', 'subdistribuidor'];
    if (!in_array($role, $validRoles)) {
        throw new Exception("Rol no válido");
    }
    
    $uid = $_GET['uid'];
    
    // Validar que el uid no esté vacío
    if (empty($uid)) {
        throw new Exception("El parámetro uid no puede estar vacío");
    }
    
    // Conectar a la base de datos
    $con = conexiondb();
    
    // 1. Primero, obtenemos los IDs de vendedores usando una consulta directa
    $vendedores = [];
    $query_vendedores = "SELECT vendedor_id FROM vendedor_tienda_relacion WHERE asignado_por = ?";
    $stmt_vendedores = mysqli_prepare($con, $query_vendedores);
    
    if (!$stmt_vendedores) {
        throw new Exception("Error al preparar la consulta de vendedores: " . mysqli_error($con));
    }
    
    mysqli_stmt_bind_param($stmt_vendedores, "s", $uid);
    
    if (!mysqli_stmt_execute($stmt_vendedores)) {
        throw new Exception("Error al ejecutar la consulta de vendedores: " . mysqli_stmt_error($stmt_vendedores));
    }
    
    $result_vendedores = mysqli_stmt_get_result($stmt_vendedores);
    
    while ($row = mysqli_fetch_assoc($result_vendedores)) {
        $vendedores[] = $row['vendedor_id'];
    }
    
    mysqli_stmt_close($stmt_vendedores);
    
    // 2. Ahora construimos la lista de UIDs para la consulta IN
    $lista_uids = "'" . mysqli_real_escape_string($con, $uid) . "'"; // Incluir al subdistribuidor
    foreach ($vendedores as $vendedor_id) {
        $lista_uids .= ",'" . mysqli_real_escape_string($con, $vendedor_id) . "'";
    }
    
    // 3. Obtener solicitudes de portabilidad
    $query_solicitudes = "SELECT * FROM sol_portabilidad WHERE user_id IN ($lista_uids) ORDER BY fecha_creacion DESC";
    $result_solicitudes = mysqli_query($con, $query_solicitudes);
    
    if (!$result_solicitudes) {
        throw new Exception("Error al obtener solicitudes: " . mysqli_error($con));
    }
    
    $solicitudes = [];
    while ($row = mysqli_fetch_assoc($result_solicitudes)) {
        // Marcar solicitudes propias
        $row['es_propia'] = ($row['user_id'] == $uid) ? 1 : 0;
        
        // Marcar si tiene imágenes (sin incluir los datos binarios)
        $row['tiene_ine_frontal'] = !empty($row['ine_frontal']);
        $row['tiene_ine_trasera'] = !empty($row['ine_trasera']);
        
        // Eliminar campos binarios para reducir el tamaño de la respuesta
        unset($row['ine_frontal']);
        unset($row['ine_trasera']);
        
        $solicitudes[] = $row;
    }
    
    // 4. Obtener información de tiendas para complementar los datos
    $tiendas_info = [];
    if (!empty($vendedores)) {
        $query_tiendas = "SELECT vendedor_id, s.id AS tienda_id, s.nombre_tienda 
                          FROM vendedor_tienda_relacion vtr
                          JOIN sucursales s ON vtr.tienda_id = s.id
                          WHERE vtr.vendedor_id IN ($lista_uids)";
                      
        $result_tiendas = mysqli_query($con, $query_tiendas);
        
        if ($result_tiendas) {
            while ($row = mysqli_fetch_assoc($result_tiendas)) {
                $tiendas_info[$row['vendedor_id']] = [
                    'tienda_id' => $row['tienda_id'],
                    'nombre_tienda' => $row['nombre_tienda']
                ];
            }
        }
    }
    
    // 5. Complementar solicitudes con información de tiendas
    foreach ($solicitudes as &$solicitud) {
        if (isset($tiendas_info[$solicitud['user_id']])) {
            $solicitud['tienda_id'] = $tiendas_info[$solicitud['user_id']]['tienda_id'];
            $solicitud['nombre_tienda'] = $tiendas_info[$solicitud['user_id']]['nombre_tienda'];
        } else {
            $solicitud['tienda_id'] = null;
            $solicitud['nombre_tienda'] = null;
        }
    }
    
    // 6. Calcular estadísticas manualmente
    $estadisticas = [
        'total' => count($solicitudes),
        'pendientes' => 0,
        'procesando' => 0,
        'completadas' => 0,
        'rechazadas' => 0,
        'canceladas' => 0
    ];
    
    foreach ($solicitudes as $solicitud) {
        $estado = $solicitud['estado_solicitud'] ?? 'pendiente';
        
        // Incrementar el contador correspondiente
        if (isset($estadisticas[$estado . 's'])) {
            $estadisticas[$estado . 's']++;
        }
    }
    
    // Cerrar conexión
    mysqli_close($con);
    
    // Devolver resultados
    echo json_encode([
        "success" => true,
        "solicitudes" => $solicitudes,
        "estadisticas" => $estadisticas
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>