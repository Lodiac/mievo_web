<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Ajusta según tu configuración de seguridad

// Cargar variables de entorno
require_once __DIR__ . '/DotEnv.php';
(new DotEnv(__DIR__ . '/../.env'))->load();

// Datos de configuración de Firebase desde variables de entorno
$firebaseConfig = [
    'apiKey' => getenv('FIREBASE_API_KEY'),
    'authDomain' => getenv('FIREBASE_AUTH_DOMAIN'),
    'projectId' => getenv('FIREBASE_PROJECT_ID'),
    'storageBucket' => getenv('FIREBASE_STORAGE_BUCKET'),
    'messagingSenderId' => getenv('FIREBASE_MESSAGING_SENDER_ID'),
    'appId' => getenv('FIREBASE_APP_ID')
];

// Verificar que todas las variables estén definidas
foreach ($firebaseConfig as $key => $value) {
    if (empty($value)) {
        // Log del error (solo en el servidor)
        error_log("Error: Variable de entorno para Firebase no definida: $key");
    }
}

// Devolver solo lo necesario para inicialización básica
echo json_encode([
    'apiKey' => $firebaseConfig['apiKey'],
    'authDomain' => $firebaseConfig['authDomain'],
    'projectId' => $firebaseConfig['projectId']
]);
?>