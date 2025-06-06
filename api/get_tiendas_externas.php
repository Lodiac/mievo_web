<?php
// api/get_tiendas_externas.php
header('Content-Type: application/json; charset=UTF-8');
require_once 'db_connect.php';

// Manejo de errores para asegurar respuestas JSON válidas
try {
    // Verificar que el usuario tenga permiso
    if (!isset($_GET['role']) || !isset($_GET['uid'])) {
        throw new Exception("No autorizado");
    }

    $role = $_GET['role'];
    $allowedRoles = ['root', 'admin', 'subdistribuidor'];

    if (!in_array($role, $allowedRoles)) {
        throw new Exception("No tienes permiso para acceder a esta información");
    }

    // Conectar a la base de datos
    $con = conexiondb();

    // Verificar si hay término de búsqueda
    $searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    // Construir consulta base
    $query = "SELECT id, nombre_tienda, tienda_principal_id, encargado, telefono, direccion, tipoTienda, estado
              FROM sucursales 
              WHERE canal = 'externas' AND estado = 1";
    
    // NUEVA LÓGICA DE BÚSQUEDA JERÁRQUICA
    if (!empty($searchTerm)) {
        $searchTermSafe = mysqli_real_escape_string($con, $searchTerm);
        
        // Subconsulta para encontrar IDs de tiendas que coinciden con la búsqueda
        $subQuery = "
            SELECT DISTINCT 
                CASE 
                    WHEN tienda_principal_id IS NULL THEN id  -- Es tienda principal
                    ELSE tienda_principal_id                  -- Es sucursal, tomar la principal
                END as grupo_id
            FROM sucursales 
            WHERE canal = 'externas' 
              AND estado = 1 
              AND (nombre_tienda LIKE '%$searchTermSafe%' 
                   OR encargado LIKE '%$searchTermSafe%' 
                   OR direccion LIKE '%$searchTermSafe%')
        ";
        
        // Consulta principal: incluir toda la familia de tiendas si algún miembro coincide
        $query = "
            SELECT id, nombre_tienda, tienda_principal_id, encargado, telefono, direccion, tipoTienda, estado
            FROM sucursales s
            WHERE canal = 'externas' 
              AND estado = 1
              AND (
                  -- Incluir si la tienda coincide directamente
                  (nombre_tienda LIKE '%$searchTermSafe%' 
                   OR encargado LIKE '%$searchTermSafe%' 
                   OR direccion LIKE '%$searchTermSafe%')
                  OR
                  -- Incluir si es parte de un grupo donde algún miembro coincide
                  (tienda_principal_id IS NULL AND id IN ($subQuery))
                  OR
                  (tienda_principal_id IN ($subQuery))
              )
        ";
    }
    
    // Ordenar resultados - principales primero, luego sucursales
    $query .= " ORDER BY IFNULL(tienda_principal_id, id), tienda_principal_id IS NULL DESC, nombre_tienda ASC";

    $result = mysqli_query($con, $query);

    if (!$result) {
        throw new Exception("Error en la consulta: " . mysqli_error($con));
    }

    // Construir array de resultados
    $tiendas = [];
    $estadisticas = [
        'total' => 0,
        'principales' => 0,
        'sucursales' => 0,
        'grupos_familias' => 0
    ];
    
    $gruposProcesados = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $tiendas[] = $row;
        $estadisticas['total']++;
        
        if ($row['tienda_principal_id'] === null) {
            $estadisticas['principales']++;
            $gruposProcesados[$row['id']] = true;
        } else {
            $estadisticas['sucursales']++;
            $gruposProcesados[$row['tienda_principal_id']] = true;
        }
    }
    
    $estadisticas['grupos_familias'] = count($gruposProcesados);

    // Cerrar conexión
    mysqli_close($con);

    // Log para debugging (solo en desarrollo)
    if (!empty($searchTerm)) {
        error_log("Búsqueda jerárquica: '$searchTerm' - Resultados: {$estadisticas['total']} tiendas, {$estadisticas['grupos_familias']} grupos");
    }

    // Devolver resultados en formato JSON
    echo json_encode([
        "tiendas" => $tiendas,
        "estadisticas" => $estadisticas,
        "busqueda" => [
            "termino" => $searchTerm,
            "busqueda_jerarquica" => !empty($searchTerm)
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
?>