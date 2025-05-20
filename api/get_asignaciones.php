<?php
// api/get_asignaciones.php
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

    // Consulta para obtener las asignaciones con nombres de tiendas
    $query = "SELECT 
                ac.id,
                ac.tienda_interna_id,
                ti.nombre_tienda as tienda_interna_nombre,
                ac.tienda_externa_id,
                te.nombre_tienda as tienda_externa_nombre,
                te.tipoTienda as tipo_tienda_externa,
                ac.fecha_asignacion,
                ac.usuario_asignacion,
                ac.estado
              FROM 
                atencion_clientes ac
              JOIN 
                sucursales ti ON ac.tienda_interna_id = ti.id
              JOIN 
                sucursales te ON ac.tienda_externa_id = te.id
              ORDER BY 
                ac.fecha_asignacion DESC";

    $result = mysqli_query($con, $query);

    if (!$result) {
        throw new Exception("Error en la consulta: " . mysqli_error($con));
    }

    // Construir array de resultados
    $asignaciones = [];
    while ($row = mysqli_fetch_assoc($result)) {
        // Convertir estado a booleano para facilitar su uso en el frontend
        $row['estado'] = (bool)$row['estado'];
        $asignaciones[] = $row;
    }

    // Cerrar conexión
    mysqli_close($con);

    // Devolver resultados en formato JSON
    echo json_encode(["asignaciones" => $asignaciones]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
?>