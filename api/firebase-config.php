<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Ajusta según tu configuración de seguridad

// Datos de configuración de Firebase (mantén esto en el servidor)
$firebaseConfig = [
    'apiKey' => 'AIzaSyD5x_KNGvw2VviPY_04Scw7xMIwHZlTygo',
    'authDomain' => 'mievo-dec57.firebaseapp.com',
    'projectId' => 'mievo-dec57',
    'storageBucket' => 'mievo-dec57.firebasestorage.app',
    'messagingSenderId' => '870501304667',
    'appId' => '1:870501304667:web:2e4f2eb43d0aba370ff27e'
];

// Devolver solo el apiKey y authDomain para inicialización básica
// Puedes ajustar esto según lo que necesites exponer
echo json_encode([
    'apiKey' => $firebaseConfig['apiKey'],
    'authDomain' => $firebaseConfig['authDomain'],
    'projectId' => $firebaseConfig['projectId']
]);
?>