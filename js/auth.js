document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.getElementById('login-form');
    const errorMessage = document.getElementById('error-message');

    loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const email = document.getElementById('email').value;
        const password = document.getElementById('password').value;
        
        // Esperar a que Firebase esté inicializado
        if (!firebaseApp) {
            errorMessage.textContent = 'El servicio de autenticación no está disponible. Intenta nuevamente.';
            return;
        }
        
        try {
            errorMessage.textContent = '';
            
            // Intentar iniciar sesión con email y contraseña
            const userCredential = await firebase.auth().signInWithEmailAndPassword(email, password);
            const user = userCredential.user;
            
            console.log('Usuario autenticado:', user);
            
            // Redirigir a la página principal o mostrar mensaje de éxito
            window.location.href = 'dashboard.html';
            
        } catch (error) {
            console.error('Error de autenticación:', error);
            
            // Manejar errores específicos
            switch(error.code) {
                case 'auth/user-not-found':
                    errorMessage.textContent = 'No existe una cuenta con este correo electrónico';
                    break;
                case 'auth/wrong-password':
                    errorMessage.textContent = 'Contraseña incorrecta';
                    break;
                case 'auth/invalid-email':
                    errorMessage.textContent = 'Correo electrónico inválido';
                    break;
                default:
                    errorMessage.textContent = 'Error al iniciar sesión. Por favor, intenta nuevamente.';
            }
        }
    });
});