<?php
// api/actualizar_sicatel.php - Versión Final Simplificada
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
    if (!isset($input['tienda_ids']) || !isset($input['sicatel']) || !isset($input['role'])) {
        throw new Exception("Faltan datos requeridos: tienda_ids, sicatel, role");
    }
    
    $tiendaIds = $input['tienda_ids'];
    $sicatel = (bool) $input['sicatel'];
    $role = $input['role'];
    
    // Validar que sea un array de IDs
    if (!is_array($tiendaIds) || empty($tiendaIds)) {
        throw new Exception("tienda_ids debe ser un array con al menos un ID");
    }
    
    // Validar IDs (convertir a enteros y filtrar válidos)
    $tiendaIds = array_map('intval', $tiendaIds);
    $tiendaIds = array_filter($tiendaIds, function($id) { return $id > 0; });
    
    if (empty($tiendaIds)) {
        throw new Exception("No se proporcionaron IDs de tienda válidos");
    }
    
    // Verificar permisos - solo root y admin
    if (!in_array($role, ['root', 'admin'])) {
        throw new Exception("No tienes permisos para modificar sicatel");
    }
    
    // Conectar a la base de datos
    $con = conexiondb();
    if (!$con) {
        throw new Exception("Error al conectar con la base de datos");
    }
    
    // Preparar valor y placeholders
    $sicatelValue = $sicatel ? 1 : 0;
    $placeholders = implode(',', array_fill(0, count($tiendaIds), '?'));
    
    // Query de actualización
    $query = "UPDATE sucursales 
              SET sicatel = ? 
              WHERE id IN ($placeholders) 
              AND canal = 'internas' 
              AND estado = 1";
    
    $stmt = mysqli_prepare($con, $query);
    if (!$stmt) {
        throw new Exception("Error al preparar consulta: " . mysqli_error($con));
    }
    
    // Preparar parámetros para bind_param
    $types = 'i' . str_repeat('i', count($tiendaIds));
    $params = array_merge([$sicatelValue], $tiendaIds);
    
    // Bind dinámico
    $refs = [];
    foreach($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }
    array_unshift($refs, $types);
    call_user_func_array(array($stmt, 'bind_param'), $refs);
    
    // Ejecutar
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Error al ejecutar actualización: " . mysqli_stmt_error($stmt));
    }
    
    $filasAfectadas = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    
    // Obtener estadísticas actualizadas
    $queryStats = "SELECT 
                    COUNT(*) as total,
                    SUM(sicatel) as habilitadas,
                    ROUND((SUM(sicatel) / COUNT(*)) * 100, 2) as porcentaje
                   FROM sucursales 
                   WHERE canal = 'internas' AND estado = 1";
    
    $resultStats = mysqli_query($con, $queryStats);
    $stats = ['total' => 0, 'habilitadas' => 0, 'porcentaje' => 0];
    
    if ($resultStats && $row = mysqli_fetch_assoc($resultStats)) {
        $stats = [
            'total' => (int) $row['total'],
            'habilitadas' => (int) $row['habilitadas'],
            'porcentaje' => (float) $row['porcentaje']
        ];
    }
    
    mysqli_close($con);
    
    // Respuesta exitosa
    echo json_encode([
        "success" => true,
        "message" => "Sicatel actualizado correctamente",
        "data" => [
            "tiendas_solicitadas" => count($tiendaIds),
            "tiendas_afectadas" => $filasAfectadas,
            "sicatel_habilitado" => $sicatel
        ],
        "estadisticas" => $stats
    ]);
    
} catch (Exception $e) {
    // Cerrar conexión si existe
    if (isset($con)) {
        mysqli_close($con);
    }
    
    // Respuesta de error
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>