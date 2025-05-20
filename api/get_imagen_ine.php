<?php
// api/get_imagen_ine.php
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
    $tiposValidos = ['frontal', 'trasera'];
    if (!in_array($tipo, $tiposValidos)) {
        throw new Exception("Tipo de imagen no válido");
    }

    // Mapear tipo a columna
    $columna = ($tipo === 'frontal') ? 'ine_frontal' : 'ine_trasera';

    // Conectar a la base de datos
    $con = conexiondb();

    // Preparar la consulta
    $query = "SELECT $columna FROM sol_portabilidad WHERE id = ?";
    $stmt = mysqli_prepare($con, $query);
    
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
    // En caso de error con la imagen, enviar texto plano
    header('Content-Type: text/plain');
    echo "Error: " . $e->getMessage();
}
?>