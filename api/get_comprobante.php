<?php
// api/get_comprobante.php
require_once 'db_connect.php';

// Asegurar que siempre enviamos una respuesta, incluso en caso de error
try {
    // Verificar parámetros
    if (!isset($_GET['id']) || !isset($_GET['tipo'])) {
        throw new Exception("Faltan parámetros requeridos");
    }

    $id = intval($_GET['id']);
    $tipo = $_GET['tipo'];

    // Validar tipo
    $tiposValidos = ['movimiento'];
    if (!in_array($tipo, $tiposValidos)) {
        throw new Exception("Tipo de comprobante no válido");
    }

    // Conectar a la base de datos
    $con = conexiondb();

    if ($tipo === 'movimiento') {
        // Preparar la consulta para obtener el comprobante del movimiento
        $query = "SELECT imagen_comprobante FROM movimientos WHERE id_movimiento = ? AND tipo = 'RECARGA'";
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
        $resultado = mysqli_stmt_fetch($stmt);
        
        if (!$resultado) {
            mysqli_stmt_close($stmt);
            mysqli_close($con);
            
            // Mostrar imagen de "no disponible"
            header('Content-Type: image/png');
            // Crear una imagen simple con texto "No disponible"
            $im = imagecreate(400, 200);
            $bg = imagecolorallocate($im, 240, 240, 240);
            $textcolor = imagecolorallocate($im, 100, 100, 100);
            imagestring($im, 5, 120, 90, "Comprobante no disponible", $textcolor);
            imagepng($im);
            imagedestroy($im);
            exit;
        }
        
        mysqli_stmt_close($stmt);
        mysqli_close($con);

        // Verificar si la imagen existe
        if (!$imagen || strlen($imagen) === 0) {
            // Mostrar imagen de "no disponible"
            header('Content-Type: image/png');
            $im = imagecreate(400, 200);
            $bg = imagecolorallocate($im, 240, 240, 240);
            $textcolor = imagecolorallocate($im, 100, 100, 100);
            imagestring($im, 5, 120, 90, "Comprobante no disponible", $textcolor);
            imagepng($im);
            imagedestroy($im);
            exit;
        }

        // Detectar el tipo de imagen
        $info = getimagesizefromstring($imagen);
        if ($info !== false) {
            header('Content-Type: ' . $info['mime']);
        } else {
            // Asumir JPEG si no se puede detectar
            header('Content-Type: image/jpeg');
        }
        
        header('Content-Length: ' . strlen($imagen));
        header('Cache-Control: no-cache, must-revalidate');

        // Enviar la imagen
        echo $imagen;
    }

} catch (Exception $e) {
    // En caso de error con la imagen, enviar una imagen de error
    error_log("Error en get_comprobante.php: " . $e->getMessage());
    
    header('Content-Type: image/png');
    $im = imagecreate(400, 200);
    $bg = imagecolorallocate($im, 255, 220, 220);
    $textcolor = imagecolorallocate($im, 200, 0, 0);
    imagestring($im, 5, 150, 90, "Error al cargar", $textcolor);
    imagepng($im);
    imagedestroy($im);
}
?>