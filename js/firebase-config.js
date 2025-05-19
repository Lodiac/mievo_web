// Cargar la configuración desde el backend PHP
async function loadFirebaseConfig() {
    try {
        console.log("Intentando cargar configuración de Firebase desde el servidor...");
        const response = await fetch('api/firebase-config.php');
        
        if (!response.ok) {
            throw new Error(`Error HTTP: ${response.status}`);
        }
        
        const config = await response.json();
        console.log("Configuración recibida:", {
            apiKey: config.apiKey ? "***" : undefined,
            authDomain: config.authDomain,
            projectId: config.projectId
        });
        
        // Verificar que la configuración es válida
        if (!config.apiKey || !config.authDomain || !config.projectId) {
            throw new Error("Configuración de Firebase incompleta");
        }
        
        // Inicializar Firebase con la configuración
        let app;
        
        // Verificar si Firebase ya está inicializado
        if (firebase.apps.length === 0) {
            app = firebase.initializeApp(config);
            console.log("Firebase inicializado correctamente");
        } else {
            app = firebase.app();
            console.log("Firebase ya estaba inicializado");
        }
        
        // Disparar evento de inicialización completa
        const event = new Event('firebase-initialized');
        window.dispatchEvent(event);
        
        return app;
    } catch (error) {
        console.error('Error al cargar/inicializar Firebase:', error);
        
        
    }
}

// Inicializar Firebase
let firebaseApp;
loadFirebaseConfig().then(app => {
    firebaseApp = app;
});