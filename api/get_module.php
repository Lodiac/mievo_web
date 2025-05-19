<?php
// Habilitar reporting de errores para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Configuración inicial
header('Content-Type: text/html; charset=UTF-8');

// Obtener parámetros GET de forma segura
$requestedModule = isset($_GET['name']) ? trim($_GET['name']) : '';
$requestedRole = isset($_GET['role']) ? trim($_GET['role']) : '';
$userUid = isset($_GET['uid']) ? trim($_GET['uid']) : '';

// Registrar solicitud para depuración
error_log("Solicitud de módulo - Módulo: '$requestedModule', Rol: '$requestedRole', UID: '$userUid'");

// Validar parámetros básicos
if (empty($requestedModule)) {
    header('HTTP/1.1 400 Bad Request');
    exit('Se requiere el nombre del módulo');
}

if (empty($requestedRole)) {
    header('HTTP/1.1 400 Bad Request');
    exit('Se requiere el rol del usuario');
}

// Mapeo de roles a módulos permitidos
$allowedModules = [
    'root' => ['bienvenida', 'dashboard', 'tiendas', 'solicitudes', 'vendedores'],
    'admin' => ['bienvenida', 'dashboard', 'tiendas', 'solicitudes'],
    'subdistribuidor' => ['bienvenida', 'dashboard', 'tiendas'],
    'vendedor' => ['bienvenida', 'dashboard'],
    'guest' => ['bienvenida']
];

// Verificar si el rol existe en nuestra configuración
if (!isset($allowedModules[$requestedRole])) {
    header('HTTP/1.1 403 Forbidden');
    exit("Rol '$requestedRole' no reconocido");
}

// Verificar si el módulo está permitido para este rol
if (!in_array($requestedModule, $allowedModules[$requestedRole])) {
    header('HTTP/1.1 403 Forbidden');
    exit("Acceso denegado: el módulo '$requestedModule' no está disponible para el rol '$requestedRole'");
}

// Construir rutas posibles para el módulo
$basePath = dirname(__DIR__);

// CORRECCIÓN: Verificamos múltiples ubicaciones posibles para los módulos
$possiblePaths = [
    // Opción 1: Estructura original esperada
    "{$basePath}/modules/modules_{$requestedRole}/{$requestedModule}.html",
    
    // Opción 2: Sin el directorio modules intermedio
    "{$basePath}/modules_{$requestedRole}/{$requestedModule}.html",
    
    // Opción 3: Directamente en la carpeta raíz del usuario
    "{$basePath}/dashboard/modules/modules_{$requestedRole}/{$requestedModule}.html",
    
    // Opción 4: Usar directamente la ruta real donde sabemos que están los archivos
    "{$basePath}/dashboard/modules/modules_root/{$requestedModule}.html"
];

$filePath = null;
foreach ($possiblePaths as $path) {
    error_log("Verificando ruta: $path");
    if (file_exists($path)) {
        $filePath = $path;
        error_log("¡Archivo encontrado en: $filePath!");
        break;
    }
}

// Verificar si encontramos el archivo
if (!$filePath) {
    header('HTTP/1.1 404 Not Found');
    
    // Imprime detalles sobre las rutas revisadas para depuración
    $errorMessage = "No se encontró el módulo en ninguna de las rutas probadas: ";
    foreach ($possiblePaths as $index => $path) {
        $errorMessage .= "\n[$index] $path";
    }
    
    error_log($errorMessage);
    exit("No se encontró el módulo '$requestedModule' para el rol '$requestedRole'. Verifica la estructura de directorios.");
}

// Leer y devolver el contenido del módulo
echo file_get_contents($filePath);
?>