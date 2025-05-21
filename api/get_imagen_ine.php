<?php
// api/get_imagen_ine.php - Versión mejorada
require_once 'db_connect.php';

// Asegurar que siempre enviamos una respuesta, incluso en caso de error
try {
    // Verificar parámetros
    if (!isset($_GET['id']) || !isset($_GET['tipo'])) {
        throw new Exception("Faltan parámetros requeridos");
    }

    $id = $_GET['id']; // No convertir a int para evitar problemas de tipo
    
    // Validación del ID
    if (empty($id)) {
        throw new Exception("ID de solicitud inválido");
    }
    
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

    // Preparar la consulta para obtener la imagen directamente
    $query = "SELECT $columna FROM sol_portabilidad WHERE id = ?";
    $stmt = mysqli_prepare($con, $query);
    
    if (!$stmt) {
        throw new Exception("Error al preparar la consulta: " . mysqli_error($con));
    }

    // Vincular parámetros y ejecutar
    mysqli_stmt_bind_param($stmt, "s", $id); // Usar 's' para string en lugar de 'i'
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Error al ejecutar la consulta: " . mysqli_stmt_error($stmt));
    }

    // Obtener resultado
    mysqli_stmt_bind_result($stmt, $imagen);
    $resultado = mysqli_stmt_fetch($stmt);
    
    // No lanzar error si no hay resultado - simplemente mostrar imagen vacía
    if (!$resultado) {
        // Cerrar recursos y mostrar imagen vacía
        mysqli_stmt_close($stmt);
        mysqli_close($con);
        
        header('Content-Type: image/png');
        readfile(__DIR__ . '/img/no-image.png'); // Imagen de placeholder
        exit;
    }
    
    mysqli_stmt_close($stmt);
    mysqli_close($con);

    // Verificar si la imagen existe
    if (!$imagen || strlen($imagen) === 0) {
        // Mostrar imagen vacía
        header('Content-Type: image/png');
        readfile(__DIR__ . '/img/no-image.png'); // Imagen de placeholder
        exit;
    }

    // Establecer encabezados para la imagen
    header('Content-Type: image/jpeg'); // Ajustar según el formato real
    header('Content-Length: ' . strlen($imagen));
    header('Cache-Control: no-cache, must-revalidate');

    // Enviar la imagen
    echo $imagen;

} catch (Exception $e) {
    // En caso de error con la imagen, enviar una imagen de placeholder
    error_log("Error en get_imagen_ine.php: " . $e->getMessage());
    
    // Intentar enviar imagen de placeholder
    if (file_exists(__DIR__ . '/img/no-image.png')) {
        header('Content-Type: image/png');
        readfile(__DIR__ . '/img/no-image.png');
    } else {
        // Si no hay imagen de placeholder, enviar mensaje de error
        header('Content-Type: text/plain');
        echo "Error: " . $e->getMessage();
    }
}
?>