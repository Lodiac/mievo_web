<?php
// api/get_comprobante.php
header('Content-Type: image/jpeg'); // Ajustable según formato real
require_once 'db_connect.php';

try {
    if (!isset($_GET['id'])) {
        throw new Exception("ID requerido");
    }
    
    $id = intval($_GET['id']);
    $con = conexiondb();
    
    // Verificar que la conexión sea exitosa
    if (!$con) {
        throw new Exception("Error de conexión a la base de datos");
    }
    
    $query = "SELECT imagen_comprobante FROM movimientos WHERE id_movimiento = ?";
    $stmt = mysqli_prepare($con, $query);
    
    if (!$stmt) {
        throw new Exception("Error al preparar la consulta: " . mysqli_error($con));
    }
    
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    
    // Verificar si hay resultados
    if (mysqli_stmt_num_rows($stmt) == 0) {
        throw new Exception("No se encontró el comprobante");
    }
    
    mysqli_stmt_bind_result($stmt, $imagen);
    mysqli_stmt_fetch($stmt);
    
    // Si hay parámetro de descarga
    if (isset($_GET['download']) && $_GET['download'] == '1') {
        header('Content-Disposition: attachment; filename="comprobante_'.$id.'.jpg"');
    }
    
    if ($imagen) {
        // Devolver la imagen directamente
        echo $imagen;
        exit;
    } else {
        throw new Exception("Comprobante no disponible");
    }
    
} catch (Exception $e) {
    // En caso de error, mostrar una imagen predeterminada o un mensaje
    header('Content-Type: text/plain');
    echo "Error: " . $e->getMessage();
}
?>