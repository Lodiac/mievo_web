// Cargar la configuración desde el backend PHP
async function loadFirebaseConfig() {
    try {
        const response = await fetch('api/firebase-config.php');
        const config = await response.json();
        
        // Inicializar Firebase con la configuración
        return firebase.initializeApp(config);
    } catch (error) {
        console.error('Error al cargar la configuración de Firebase:', error);
        document.getElementById('error-message').textContent = 
            'Error al conectar con el servicio. Por favor, intenta más tarde.';
    }
}

// Inicializar Firebase
let firebaseApp;
loadFirebaseConfig().then(app => {
    firebaseApp = app;
    console.log('Firebase inicializado correctamente');
});