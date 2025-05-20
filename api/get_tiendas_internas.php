<?php
// api/get_tiendas_internas.php
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

    // Obtener tiendas internas (donde canal='internas')
    $query = "SELECT id, nombre_tienda, encargado, telefono, direccion, tipoTienda, estado
              FROM sucursales 
              WHERE canal = 'internas' AND estado = 1
              ORDER BY nombre_tienda ASC";

    $result = mysqli_query($con, $query);

    if (!$result) {
        throw new Exception("Error en la consulta: " . mysqli_error($con));
    }

    // Construir array de resultados
    $tiendas = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $tiendas[] = $row;
    }

    // Cerrar conexión
    mysqli_close($con);

    // Devolver resultados en formato JSON
    echo json_encode(["tiendas" => $tiendas]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
?>