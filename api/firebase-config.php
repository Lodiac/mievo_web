<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Ajusta según tu configuración de seguridad

// Configuración de Firebase (respaldo si falla .env)
$defaultConfig = [
    'apiKey' => 'AIzaSyD5x_KNGvw2VviPY_04Scw7xMIwHZlTygo',
    'authDomain' => 'mievo-dec57.firebaseapp.com',
    'projectId' => 'mievo-dec57'
];

// Función para cargar variables de entorno sin depender de clase externa
function loadEnvFile($path) {
    $result = [];
    
    // Verificar si el archivo existe
    if (!file_exists($path)) {
        error_log("Archivo .env no encontrado en: $path");
        return $result;
    }
    
    // Leer el archivo
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        error_log("No se pudo leer el archivo .env");
        return $result;
    }
    
    foreach ($lines as $line) {
        // Ignorar comentarios
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Procesar líneas válidas
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            
            // Quitar comillas si existen
            if (strpos($value, '"') === 0) {
                $value = trim($value, '"');
            } elseif (strpos($value, "'") === 0) {
                $value = trim($value, "'");
            }
            
            if (!empty($name)) {
                $result[$name] = $value;
            }
        }
    }
    
    return $result;
}

try {
    // Intentar cargar desde .env (en la raíz del proyecto)
    $envPath = __DIR__ . '/../.env';
    $envVars = loadEnvFile($envPath);
    
    // Configuración de Firebase desde variables de entorno
    if (!empty($envVars['FIREBASE_API_KEY']) && 
        !empty($envVars['FIREBASE_AUTH_DOMAIN']) && 
        !empty($envVars['FIREBASE_PROJECT_ID'])) {
        
        $firebaseConfig = [
            'apiKey' => $envVars['FIREBASE_API_KEY'],
            'authDomain' => $envVars['FIREBASE_AUTH_DOMAIN'],
            'projectId' => $envVars['FIREBASE_PROJECT_ID']
        ];
        
        // Log de éxito (solo en el servidor)
        error_log("Firebase config cargado desde .env");
    } else {
        // Si no hay variables de entorno, usar la configuración por defecto
        $firebaseConfig = $defaultConfig;
        error_log("Variables de entorno incompletas, usando configuración por defecto");
    }
} catch (Exception $e) {
    // En caso de error, usar la configuración por defecto
    $firebaseConfig = $defaultConfig;
    error_log("Error al cargar .env: " . $e->getMessage());
}

// Devolver la configuración
echo json_encode($firebaseConfig);
?>