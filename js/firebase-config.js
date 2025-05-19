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
        
        // Intentar inicializar con configuración de respaldo si es un error de carga
        if (error.message.includes('fetch') || error.message.includes('HTTP')) {
            console.warn("Intentando inicializar con configuración de respaldo...");
            
            const backupConfig = {
                apiKey: "AIzaSyD5x_KNGvw2VviPY_04Scw7xMIwHZlTygo",
                authDomain: "mievo-dec57.firebaseapp.com",
                projectId: "mievo-dec57"
            };
            
            try {
                const app = firebase.apps.length === 0 ? 
                    firebase.initializeApp(backupConfig) :
                    firebase.app();
                    
                console.log("Firebase inicializado con configuración de respaldo");
                
                // Disparar evento de inicialización completa
                const event = new Event('firebase-initialized');
                window.dispatchEvent(event);
                
                return app;
            } catch (backupError) {
                console.error("Error incluso con configuración de respaldo:", backupError);
                const errorMsg = document.getElementById('error-message');
                if (errorMsg) {
                    errorMsg.textContent = 'Error al conectar con el servicio. Por favor, intenta más tarde.';
                }
            }
        } else {
            const errorMsg = document.getElementById('error-message');
            if (errorMsg) {
                errorMsg.textContent = 'Error al conectar con el servicio. Por favor, intenta más tarde.';
            }
        }
    }
}

// Inicializar Firebase
let firebaseApp;
loadFirebaseConfig().then(app => {
    firebaseApp = app;
});