<?php
// get_solicitudes_portabilidad.php - SOLUCIÓN PARA ERROR DE COLACIÓN + TIPO VENDEDOR
header('Content-Type: application/json; charset=UTF-8');
require_once 'db_connect.php';

try {
    // Verificaciones básicas
    if (!isset($_GET['uid'])) {
        throw new Exception("Parámetro uid requerido");
    }
    
    $uid = $_GET['uid'];
    $con = conexiondb();
    
    // 1. Obtener el tipo de relación del vendedor
    $tipo_vendedor = 'externo'; // Default
    $query_tipo = "SELECT tipo_relacion FROM vendedor_tienda_relacion 
                   WHERE vendedor_id = ? COLLATE utf8mb4_general_ci";
    
    $stmt_tipo = mysqli_prepare($con, $query_tipo);
    if ($stmt_tipo) {
        mysqli_stmt_bind_param($stmt_tipo, "s", $uid);
        if (mysqli_stmt_execute($stmt_tipo)) {
            $result_tipo = mysqli_stmt_get_result($stmt_tipo);
            if ($row_tipo = mysqli_fetch_assoc($result_tipo)) {
                $tipo_vendedor = $row_tipo['tipo_relacion'] ?: 'externo';
            }
        }
        mysqli_stmt_close($stmt_tipo);
    }
    
    // 2. Primera consulta para obtener vendedores - FORZAR COLACIÓN
    $vendedores = [];
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
    
    while ($row = mysqli_fetch_assoc($result_vendedores)) {
        $vendedores[] = $row['vendedor_id'];
    }
    
    mysqli_stmt_close($stmt_vendedores);
    
    // 3. Construir lista de UIDs para la consulta IN
    $lista_uids = "'" . mysqli_real_escape_string($con, $uid) . "'";
    foreach ($vendedores as $vendedor_id) {
        $lista_uids .= ",'" . mysqli_real_escape_string($con, $vendedor_id) . "'";
    }
    
    // 4. Consulta principal - SIN UNIR TABLAS para evitar duplicados
    $query = "SELECT 
              id, user_id, nombre_completo, numero_portar, nip_portabilidad,
              operador_destino, fecha_creacion, estado_solicitud, comentarios,
              curp, fecha_nacimiento, numero_contacto, iccid, device_id, device_model,
              estado
              FROM sol_portabilidad 
              WHERE user_id COLLATE utf8mb4_general_ci IN ($lista_uids)
              ORDER BY id DESC";
    
    $result = mysqli_query($con, $query);
    
    if (!$result) {
        throw new Exception("Error en la consulta: " . mysqli_error($con));
    }
    
    $solicitudes = [];
    while ($row = mysqli_fetch_assoc($result)) {
        // Calcular es_propia y flags de imágenes pero SIN modificar el ID
        $row['es_propia'] = ($row['user_id'] == $uid) ? 1 : 0;
        $solicitudes[] = $row;
    }
    
    // Verificación de duplicados (por seguridad)
    $solicitudes_unicas = [];
    $ids_vistos = [];
    
    foreach ($solicitudes as $solicitud) {
        if (!in_array($solicitud['id'], $ids_vistos)) {
            $ids_vistos[] = $solicitud['id'];
            $solicitudes_unicas[] = $solicitud;
        }
    }
    
    // Estadísticas básicas
    $estadisticas = [
        'total' => count($solicitudes_unicas),
        'pendientes' => count(array_filter($solicitudes_unicas, function($s) { 
            return strtolower($s['estado_solicitud']) == 'pendiente'; 
        })),
        'procesando' => 0,
        'completadas' => 0,
        'rechazadas' => 0,
        'canceladas' => 0
    ];
    
    mysqli_close($con);
    
    // Devolver respuesta CON EL TIPO DE VENDEDOR
    echo json_encode([
        "success" => true,
        "solicitudes" => $solicitudes_unicas,
        "estadisticas" => $estadisticas,
        "tipo_vendedor" => $tipo_vendedor // NUEVO CAMPO
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>