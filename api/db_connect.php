<?php
/**
 * Establece una conexión segura a la base de datos
 * 
 * @return mysqli|null Retorna objeto de conexión o null en caso de error
 */
function conexiondb() {
    // Parámetros de conexión
    $host = "192.168.0.103";
    $usuario = "master";
    $password = "56^My7l7r";
    $database = "mievo";
    
    // Manejar errores sin exponer detalles sensibles en producción
    $environment = "development"; // Cambiar a "production" en entorno de producción
    
    try {
        // Crear conexión usando mysqli
        $con = mysqli_init();
        
        // Configuraciones de seguridad
        if (!$con) {
            throw new Exception("Error al inicializar mysqli");
        }
        
        // Configurar opciones para conexión segura
        mysqli_options($con, MYSQLI_OPT_CONNECT_TIMEOUT, 10);
        mysqli_options($con, MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
        
        // Establecer la conexión
        if (!mysqli_real_connect($con, $host, $usuario, $password, $database)) {
            throw new Exception("Error de conexión a la base de datos: " . mysqli_connect_error());
        }
        
        // Establecer charset UTF-8
        if (!mysqli_set_charset($con, "utf8mb4")) {
            throw new Exception("Error al configurar el juego de caracteres: " . mysqli_error($con));
        }
        
        return $con;
        
    } catch (Exception $e) {
        // Manejar error de forma segura
        if ($environment === "development") {
            // En desarrollo, mostrar mensaje detallado
            die("Error de conexión: " . $e->getMessage());
        } else {
            // En producción, no exponer detalles sensibles
            error_log("Error de conexión a DB: " . $e->getMessage());
            die("Error al conectar con la base de datos. Por favor, contacte al administrador.");
        }
    }
}

/**
 * Cierra la conexión a la base de datos de forma segura
 * 
 * @param mysqli $con Conexión a cerrar
 * @return void
 */
function cerrar_conexion($con) {
    if ($con instanceof mysqli && !mysqli_connect_errno()) {
        mysqli_close($con);
    }
}

/**
 * Ejecuta una consulta preparada de forma segura
 * Previene inyecciones SQL usando prepared statements
 * 
 * @param mysqli $con Conexión a la base de datos
 * @param string $query Consulta SQL con placeholders (?)
 * @param string $types Tipos de parámetros (i: integer, s: string, d: double, b: blob)
 * @param array $params Arreglo de parámetros que reemplazarán los placeholders
 * @return mysqli_stmt|false Retorna el statement o false en caso de error
 */
function consulta_segura($con, $query, $types = "", $params = []) {
    $stmt = mysqli_prepare($con, $query);
    
    if ($stmt && $types && $params) {
        // Asociar parámetros al statement
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    
    if ($stmt && mysqli_stmt_execute($stmt)) {
        return $stmt;
    } else {
        error_log("Error en consulta SQL: " . mysqli_error($con));
        return false;
    }
}
?>