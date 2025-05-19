<?php
// Añadir depuración
error_log("Solicitud de módulo: $requestedModule para rol: $requestedRole");

// Verificar que la carpeta del módulo existe
$modulesBasePath = dirname(__DIR__) . "/modules";
$roleFolderPath = "{$modulesBasePath}/modules_{$requestedRole}";

if (!is_dir($roleFolderPath)) {
    // Si la carpeta específica del rol no existe, intentar con la carpeta genérica
    $roleFolderPath = "{$modulesBasePath}/modules_root";
    error_log("Carpeta de rol no encontrada, usando carpeta root como fallback");
}

// Construir la ruta al archivo solicitado
$filePath = "{$roleFolderPath}/{$requestedModule}.html";

error_log("Intentando cargar archivo: $filePath");

// Verificar que el archivo exista
if (!file_exists($filePath)) {
    header('HTTP/1.1 404 Not Found');
    exit("Módulo '$requestedModule' no encontrado");
}

// Leer y devolver el contenido del módulo
echo file_get_contents($filePath);
?>