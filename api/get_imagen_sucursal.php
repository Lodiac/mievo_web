<?php
// api/get_imagen_sucursal.php
require_once 'db_connect.php';

// Asegurar que siempre enviamos una respuesta, incluso en caso de error
try {
    // Verificar parámetros
    if (!isset($_GET['id']) || !isset($_GET['tipo'])) {
        throw new Exception("Faltan parámetros requeridos");
    }

    $id = intval($_GET['id']);
    $tipo = $_GET['tipo'];

    // Validar tipo de imagen
    $tiposValidos = ['imagen_exterior', 'imagen_interior', 'imagen_lateral_derecha', 'imagen_lateral_izquierda'];
    if (!in_array($tipo, $tiposValidos)) {
        throw new Exception("Tipo de imagen no válido");
    }

    // Conectar a la base de datos
    $con = conexiondb();

    // Preparar la consulta
    $stmt = mysqli_prepare($con, "SELECT $tipo FROM sucursales WHERE id = ? AND canal = 'internas'");
    if (!$stmt) {
        throw new Exception("Error al preparar la consulta: " . mysqli_error($con));
    }

    // Vincular parámetros y ejecutar
    mysqli_stmt_bind_param($stmt, "i", $id);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Error al ejecutar la consulta: " . mysqli_stmt_error($stmt));
    }

    // Obtener resultado
    mysqli_stmt_bind_result($stmt, $imagen);
    if (!mysqli_stmt_fetch($stmt)) {
        throw new Exception("No se encontró la imagen");
    }
    
    mysqli_stmt_close($stmt);
    mysqli_close($con);

    // Verificar si la imagen existe
    if (!$imagen) {
        throw new Exception("La imagen no existe o está vacía");
    }

    // Establecer encabezados para la imagen
    header('Content-Type: image/jpeg'); // Ajustar según el formato real
    header('Content-Length: ' . strlen($imagen));
    header('Cache-Control: public, max-age=86400'); // Cachear por 1 día

    // Enviar la imagen
    echo $imagen;

} catch (Exception $e) {
    // En caso de error con la imagen, enviar una imagen de error
    header('Content-Type: text/plain');
    echo "Error: " . $e->getMessage();
}
?>