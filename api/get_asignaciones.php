<?php
// api/get_asignaciones.php - SOLO MOSTRAR ESTADO = 1
header('Content-Type: application/json; charset=UTF-8');
require_once 'db_connect.php';

// Manejo de errores para asegurar respuestas JSON válidas
try {
    // Verificar que el usuario tenga permiso
    if (!isset($_GET['role']) || !isset($_GET['uid'])) {
        throw new Exception("No autorizado");
    }

    $role = $_GET['role'];
    $allowedRoles = ['root', 'admin', 'subdistribuidor'];

    if (!in_array($role, $allowedRoles)) {
        throw new Exception("No tienes permiso para acceder a esta información");
    }

    // Conectar a la base de datos
    $con = conexiondb();

    // *** MODIFICACIÓN CRÍTICA: Solo mostrar asignaciones con estado = 1 ***
    $query = "SELECT 
                ac.id,
                ac.tienda_interna_id,
                ac.tienda_externa_id,
                ac.fecha_asignacion,
                ac.usuario_asignacion,
                ac.estado,
                ac.observaciones,
                
                -- Información de la tienda que DA atención (interna en el modelo actual)
                ti.nombre_tienda as tienda_da_atencion_nombre,
                ti.canal as tienda_da_atencion_canal,
                ti.sicatel as tienda_da_atencion_sicatel,
                ti.tipoTienda as tienda_da_atencion_tipo,
                
                -- Información de la tienda que RECIBE atención (externa en el modelo actual)
                te.nombre_tienda as tienda_recibe_atencion_nombre,
                te.canal as tienda_recibe_atencion_canal,
                te.sicatel as tienda_recibe_atencion_sicatel,
                te.tipoTienda as tienda_recibe_atencion_tipo,
                
                -- Calcular tipo de asignación
                CASE 
                    WHEN ti.canal = 'internas' AND te.canal = 'internas' THEN 'interna_a_interna'
                    WHEN ti.canal = 'internas' AND te.canal = 'externas' THEN 'interna_a_externa'
                    WHEN ti.canal = 'externas' AND te.canal = 'internas' THEN 'externa_a_interna'
                    ELSE 'desconocido'
                END as tipo_asignacion
                
              FROM 
                atencion_clientes ac
              JOIN 
                sucursales ti ON ac.tienda_interna_id = ti.id
              JOIN 
                sucursales te ON ac.tienda_externa_id = te.id
              WHERE 
                ac.estado = 1
              ORDER BY 
                ac.fecha_asignacion DESC";

    $result = mysqli_query($con, $query);

    if (!$result) {
        throw new Exception("Error en la consulta: " . mysqli_error($con));
    }

    // Construir array de resultados con información enriquecida
    $asignaciones = [];
    $estadisticas = [
        'total' => 0,
        'activas' => 0,
        'inactivas' => 0, // Siempre 0 ya que solo mostramos activas
        'por_tipo' => [
            'interna_a_interna' => 0,
            'interna_a_externa' => 0,
            'externa_a_interna' => 0,
            'desconocido' => 0
        ],
        'sicatel_stats' => [
            'tiendas_da_con_sicatel' => 0,
            'tiendas_recibe_con_sicatel' => 0
        ]
    ];
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Convertir campos de sicatel a booleanos
        $row['tienda_da_atencion_sicatel'] = (bool)$row['tienda_da_atencion_sicatel'];
        $row['tienda_recibe_atencion_sicatel'] = (bool)$row['tienda_recibe_atencion_sicatel'];
        $row['estado'] = (bool)$row['estado'];
        
        // Añadir nombres descriptivos para compatibilidad con frontend actual
        $row['tienda_interna_nombre'] = $row['tienda_da_atencion_nombre'];
        $row['tienda_externa_nombre'] = $row['tienda_recibe_atencion_nombre'];
        $row['tipoTienda'] = $row['tienda_recibe_atencion_tipo'];
        
        // Generar descripción del tipo de asignación
        $tipoDescripcion = '';
        $iconoTipo = '';
        
        switch ($row['tipo_asignacion']) {
            case 'interna_a_interna':
                $tipoDescripcion = 'Interna → Interna';
                $iconoTipo = 'fas fa-exchange-alt';
                break;
            case 'interna_a_externa':
                $tipoDescripcion = 'Interna → Externa';
                $iconoTipo = 'fas fa-arrow-right';
                break;
            case 'externa_a_interna':
                $tipoDescripcion = 'Externa → Interna';
                $iconoTipo = 'fas fa-arrow-left';
                break;
            default:
                $tipoDescripcion = 'Tipo desconocido';
                $iconoTipo = 'fas fa-question';
        }
        
        $row['tipo_asignacion_descripcion'] = $tipoDescripcion;
        $row['tipo_asignacion_icono'] = $iconoTipo;
        
        // Actualizar estadísticas
        $estadisticas['total']++;
        // Como solo mostramos estado = 1, todas son activas
        $estadisticas['activas']++;
        
        $estadisticas['por_tipo'][$row['tipo_asignacion']]++;
        
        if ($row['tienda_da_atencion_sicatel']) {
            $estadisticas['sicatel_stats']['tiendas_da_con_sicatel']++;
        }
        
        if ($row['tienda_recibe_atencion_sicatel']) {
            $estadisticas['sicatel_stats']['tiendas_recibe_con_sicatel']++;
        }
        
        $asignaciones[] = $row;
    }

    // Cerrar conexión
    mysqli_close($con);

    // Log de estadísticas para debugging
    error_log("Asignaciones activas cargadas: " . json_encode($estadisticas));

    // Devolver resultados en formato JSON con estadísticas enriquecidas
    echo json_encode([
        "asignaciones" => $asignaciones,
        "estadisticas" => $estadisticas,
        "meta" => [
            "timestamp" => date('Y-m-d H:i:s'),
            "sicatel_enabled" => true,
            "solo_estado_activo" => true, // *** NUEVO: Indicador de filtro ***
            "tipos_asignacion_soportados" => [
                "interna_a_interna" => "Tienda interna (con sicatel) atiende a otra tienda interna",
                "interna_a_externa" => "Tienda interna atiende a tienda externa",
                "externa_a_interna" => "Tienda externa recibe atención de tienda interna (con sicatel)"
            ]
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "error" => $e->getMessage(),
        "debug_info" => [
            "archivo" => "get_asignaciones.php",
            "timestamp" => date('Y-m-d H:i:s')
        ]
    ]);
}
?>