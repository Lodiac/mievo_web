<?php
// api/get_sucursales_externas.php
header('Content-Type: application/json; charset=UTF-8');
require_once 'db_connect.php';

// Manejo de errores para asegurar que siempre devolvemos JSON válido
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

    // Obtener sucursales con canal=externas, SIN incluir los BLOBs de imágenes
    $query = "SELECT id, nombre_tienda, encargado, telefono, direccion, tipoTienda, estado,
             (imagen_exterior IS NOT NULL AND LENGTH(imagen_exterior) > 0) AS tiene_imagen_exterior,
             (imagen_interior IS NOT NULL AND LENGTH(imagen_interior) > 0) AS tiene_imagen_interior,
             (imagen_lateral_derecha IS NOT NULL AND LENGTH(imagen_lateral_derecha) > 0) AS tiene_imagen_lateral_derecha,
             (imagen_lateral_izquierda IS NOT NULL AND LENGTH(imagen_lateral_izquierda) > 0) AS tiene_imagen_lateral_izquierda
             FROM sucursales 
             WHERE canal = 'internas'";

    $result = mysqli_query($con, $query);

    if (!$result) {
        throw new Exception("Error en la consulta: " . mysqli_error($con));
    }

    // Construir array de resultados
    $sucursales = [];
    while ($row = mysqli_fetch_assoc($result)) {
        // Convertir los campos de imágenes a booleanos
        $row['tiene_imagen_exterior'] = (bool)$row['tiene_imagen_exterior'];
        $row['tiene_imagen_interior'] = (bool)$row['tiene_imagen_interior'];
        $row['tiene_imagen_lateral_derecha'] = (bool)$row['tiene_imagen_lateral_derecha'];
        $row['tiene_imagen_lateral_izquierda'] = (bool)$row['tiene_imagen_lateral_izquierda'];
        
        $sucursales[] = $row;
    }

    // Cerrar conexión
    mysqli_close($con);

    // Devolver resultados en formato JSON
    echo json_encode(["sucursales" => $sucursales]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
?>