<?php
// manage.php - Gestión de vouchers (listar, eliminar, reimprimir, modificar perfil, reiniciar tiempo)

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$baseDir = __DIR__;
$configPath = $baseDir . '/config.json';
$ticketsDir = $baseDir . '/tickets';
$logDir = $baseDir . '/logs';
$logPath = $logDir . '/app.log';

if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }

function log_line(string $path, string $msg): void {
    @file_put_contents($path, '['.date('c')."] ".$msg."\n", FILE_APPEND);
}

// Funciones de almacenamiento local eliminadas - todo se maneja en MikroTik

// Obtener cliente de MikroTik User Manager vía API
function get_mikrotik_client(array $router, string $logPath) {
    $host = $router['host'] ?? '';
    $apiPort = (int)($router['api_port'] ?? 8728);
    $apiSSL = (bool)($router['api_ssl'] ?? false);
    $apiUser = $router['username'] ?? '';
    $apiPass = $router['password'] ?? '';
    if ($host === '' || $apiUser === '') {
        log_line($logPath, 'Router config incompleta, no se puede usar API.');
        return null;
    }
    $vendorAutoload = __DIR__ . '/vendor/autoload.php';
    if (is_file($vendorAutoload)) {
        require_once $vendorAutoload;
    }
    if (!class_exists('RouterOS\\Client') || !class_exists('RouterOS\\Query')) {
        log_line($logPath, 'Librería RouterOS API no disponible (composer).');
        return null;
    }
    try {
        $client = new \RouterOS\Client([
            'host' => $host,
            'user' => $apiUser,
            'pass' => $apiPass,
            'port' => $apiPort,
            'ssl'  => $apiSSL,
            'timeout' => 6,
        ]);
        
        log_line($logPath, 'Conexión exitosa a MikroTik router');
        return $client; // Retornar cliente para operaciones posteriores
    } catch (\Throwable $e) {
        log_line($logPath, 'Excepción API conexión: '.$e->getMessage());
        return null;
    }
}

// Obtener todos los usuarios de MikroTik User Manager con información detallada
function get_mikrotik_users($client, string $customer, string $logPath): array {
    try {
        if (!$client || $client === null) {
            log_line($logPath, 'GET_USERS: Cliente API no disponible');
            return [];
        }
        
        log_line($logPath, 'GET_USERS: Iniciando consulta de usuarios en User Manager');
        log_line($logPath, 'GET_USERS: Customer: '.$customer);
        
        // Consultar todos los usuarios del customer
        $q = (new \RouterOS\Query('/tool/user-manager/user/print'))
            ->where('customer', $customer);
        $users = $client->query($q)->read();
        
        log_line($logPath, 'GET_USERS: Respuesta recibida de MikroTik');
        log_line($logPath, 'GET_USERS: Total usuarios encontrados: '.count($users));
        
        $result = [];
        $validUsers = 0;
        foreach ($users as $user) {
            $username = $user['username'] ?? '';
            if ($username !== '') {
                $result[$username] = [
                    'code' => $username,
                    'username' => $username,
                    'disabled' => ($user['disabled'] ?? 'no') === 'yes',
                    'profile' => $user['actual-profile'] ?? '',
                    'customer' => $user['customer'] ?? '',
                    'password' => $user['password'] ?? '****',
                ];
                $validUsers++;
                log_line($logPath, 'GET_USERS: Usuario procesado: '.$username.' (perfil: '.($user['actual-profile'] ?? 'N/A').', disabled: '.($user['disabled'] ?? 'no').')');
            }
        }
        
        log_line($logPath, 'GET_USERS: Usuarios válidos procesados: '.$validUsers);
        log_line($logPath, 'GET_USERS: Consulta completada exitosamente');
        
        return $result;
    } catch (\Throwable $e) {
        log_line($logPath, 'GET_USERS: ERROR CRITICO al obtener usuarios: '.$e->getMessage());
        log_line($logPath, 'GET_USERS: Stack trace: '.$e->getTraceAsString());
        return [];
    }
}

// Eliminar usuario de MikroTik User Manager
function delete_mikrotik_user($client, string $username, string $customer, string $logPath): bool {
    try {
        if (!$client || $client === false || $client === null) {
            log_line($logPath, 'ELIMINAR: Cliente API no disponible para usuario '.$username);
            return false;
        }
        
        log_line($logPath, 'ELIMINAR: Iniciando eliminación de usuario '.$username.' (customer: '.$customer.')');
        
        // Primero, eliminar todas las sesiones activas del usuario
        log_line($logPath, 'ELIMINAR: Buscando sesiones activas del usuario '.$username);
        try {
            $qSessions = (new \RouterOS\Query('/tool/user-manager/session/print'))
                ->where('username', $username);
            $sessions = $client->query($qSessions)->read();
            
            if (!empty($sessions)) {
                log_line($logPath, 'ELIMINAR: Encontradas '.count($sessions).' sesiones activas');
                foreach ($sessions as $session) {
                    $sessionId = $session['.id'] ?? '';
                    if ($sessionId) {
                        $qDelSession = (new \RouterOS\Query('/tool/user-manager/session/remove'))
                            ->equal('.id', $sessionId);
                        $client->query($qDelSession)->read();
                        log_line($logPath, 'ELIMINAR: Sesión '.$sessionId.' eliminada');
                    }
                }
            } else {
                log_line($logPath, 'ELIMINAR: No hay sesiones activas');
            }
        } catch (\Throwable $e) {
            log_line($logPath, 'ELIMINAR: Error al eliminar sesiones: '.$e->getMessage());
        }
        
        // Ahora buscar y eliminar el usuario
        log_line($logPath, 'ELIMINAR: Buscando usuario en User Manager');
        $q = (new \RouterOS\Query('/tool/user-manager/user/print'))
            ->where('username', $username);
        $users = $client->query($q)->read();
        
        if (empty($users)) {
            log_line($logPath, 'ELIMINAR: Usuario '.$username.' no encontrado en UM (puede estar ya eliminado)');
            return true; // No existe, así que técnicamente "eliminado"
        }
        
        log_line($logPath, 'ELIMINAR: Encontrados '.count($users).' usuarios con nombre '.$username);
        
        // Eliminar todos los usuarios encontrados
        foreach ($users as $user) {
            $id = $user['.id'] ?? '';
            if ($id) {
                log_line($logPath, 'ELIMINAR: Eliminando usuario con ID '.$id);
                $qDel = (new \RouterOS\Query('/tool/user-manager/user/remove'))
                    ->equal('.id', $id);
                $client->query($qDel)->read();
                log_line($logPath, 'ELIMINAR: Usuario '.$username.' (ID: '.$id.') eliminado exitosamente de UM');
            }
        }
        
        log_line($logPath, 'ELIMINAR: Proceso de eliminación completado exitosamente para '.$username);
        return true;
    } catch (\Throwable $e) {
        log_line($logPath, 'ELIMINAR: ERROR CRÍTICO al eliminar usuario '.$username.': '.$e->getMessage());
        log_line($logPath, 'ELIMINAR: Stack trace: '.$e->getTraceAsString());
        return false;
    }
}

// Modificar perfil de usuario en MikroTik
function modify_mikrotik_profile($client, string $username, string $customer, string $newProfile, string $logPath): bool {
    try {
        if (!$client || $client === false || $client === null) {
            return false;
        }
        
        // Primero eliminar las sesiones activas del usuario
        $qSessions = (new \RouterOS\Query('/tool/user-manager/session/print'))
            ->where('username', $username);
        $sessions = $client->query($qSessions)->read();
        
        foreach ($sessions as $session) {
            $id = $session['.id'] ?? '';
            if ($id) {
                $qDel = (new \RouterOS\Query('/tool/user-manager/session/remove'))
                    ->equal('.id', $id);
                $client->query($qDel)->read();
            }
        }
        
        // Reactivar perfil
        $q = (new \RouterOS\Query('/tool/user-manager/user/create-and-activate-profile'))
            ->equal('customer', $customer)
            ->equal('profile', $newProfile)
            ->equal('numbers', $username);
        $client->query($q)->read();
        
        log_line($logPath, 'Perfil modificado para usuario '.$username.' a '.$newProfile);
        return true;
    } catch (\Throwable $e) {
        log_line($logPath, 'Error al modificar perfil: '.$e->getMessage());
        return false;
    }
}

// Reiniciar tiempo de usuario en MikroTik
function reset_mikrotik_time($client, string $username, string $customer, string $profile, string $logPath): bool {
    try {
        if (!$client || $client === false || $client === null) {
            return false;
        }
        
        // Eliminar sesiones activas
        $qSessions = (new \RouterOS\Query('/tool/user-manager/session/print'))
            ->where('username', $username);
        $sessions = $client->query($qSessions)->read();
        
        foreach ($sessions as $session) {
            $id = $session['.id'] ?? '';
            if ($id) {
                $qDel = (new \RouterOS\Query('/tool/user-manager/session/remove'))
                    ->equal('.id', $id);
                $client->query($qDel)->read();
            }
        }
        
        // Buscar el usuario y actualizar
        $qUser = (new \RouterOS\Query('/tool/user-manager/user/print'))
            ->where('username', $username)
            ->where('customer', $customer);
        $users = $client->query($qUser)->read();
        
        if (!empty($users)) {
            $userId = $users[0]['.id'] ?? '';
            if ($userId) {
                // Desactivar y reactivar el perfil para reiniciar tiempo
                $qDeactivate = (new \RouterOS\Query('/tool/user-manager/user/set'))
                    ->equal('.id', $userId)
                    ->equal('disabled', 'yes');
                $client->query($qDeactivate)->read();
                
                $qActivate = (new \RouterOS\Query('/tool/user-manager/user/set'))
                    ->equal('.id', $userId)
                    ->equal('disabled', 'no');
                $client->query($qActivate)->read();
                
                // Reactivar perfil
                $q = (new \RouterOS\Query('/tool/user-manager/user/create-and-activate-profile'))
                    ->equal('customer', $customer)
                    ->equal('profile', $profile)
                    ->equal('numbers', $username);
                $client->query($q)->read();
            }
        }
        
        log_line($logPath, 'Tiempo reiniciado para usuario '.$username);
        return true;
    } catch (\Throwable $e) {
        log_line($logPath, 'Error al reiniciar tiempo: '.$e->getMessage());
        return false;
    }
}

try {
    $config = json_decode(@file_get_contents($configPath), true) ?? [];
    $router = $config['router'] ?? [];
    $profiles = $config['profiles'] ?? [];
    $branding = $config['branding'] ?? [];
    
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    // Operación: Listar vouchers
    if ($action === 'list') {
        log_line($logPath, '========== INICIO LISTADO DE VOUCHERS ==========');
        log_line($logPath, 'LIST: Timestamp: '.date('Y-m-d H:i:s'));
        
        $customer = $router['customer'] ?? 'admin';
        $ssid = $branding['ssid'] ?? 'WIFI';
        
        log_line($logPath, 'LIST: Customer configurado: '.$customer);
        log_line($logPath, 'LIST: SSID por defecto: '.$ssid);
        
        // Conectar a MikroTik y obtener usuarios
        log_line($logPath, 'LIST: Intentando conexión a MikroTik...');
        $client = get_mikrotik_client($router, $logPath);
        
        if (!$client || $client === null) {
            log_line($logPath, 'LIST: ERROR - No se pudo conectar a MikroTik');
            log_line($logPath, 'LIST: Router host: '.($router['host'] ?? 'NO CONFIGURADO'));
            log_line($logPath, 'LIST: Router port: '.($router['api_port'] ?? 'NO CONFIGURADO'));
            throw new \Exception('No se pudo conectar al router MikroTik. Verifica la configuración.');
        }
        
        log_line($logPath, 'LIST: Conexión a MikroTik establecida exitosamente');
        log_line($logPath, 'LIST: Consultando usuarios de User Manager...');
        
        $mikrotikUsers = get_mikrotik_users($client, $customer, $logPath);
        
        if (empty($mikrotikUsers)) {
            log_line($logPath, 'LIST: No se encontraron usuarios en MikroTik User Manager');
        } else {
            log_line($logPath, 'LIST: Total usuarios obtenidos: '.count($mikrotikUsers));
        }
        
        // Convertir array asociativo a array indexado para JSON
        $vouchers = [];
        foreach ($mikrotikUsers as $code => $user) {
            $pdfPath = $ticketsDir . '/voucher-' . $code . '.pdf';
            $hasPdf = file_exists($pdfPath);
            
            $voucher = [
                'code' => $code,
                'profile' => $user['profile'],
                'ssid' => $ssid, // SSID por defecto de configuración
                'ts' => date('c'), // Timestamp actual (no se puede obtener fecha de creación de MikroTik)
                'has_pdf' => $hasPdf,
                'mikrotik_status' => 'active',
                'mikrotik_disabled' => $user['disabled'],
                'mikrotik_profile' => $user['profile'],
                'customer' => $user['customer'],
            ];
            
            $vouchers[] = $voucher;
            
            log_line($logPath, 'LIST: Voucher procesado: '.$code.' (PDF: '.($hasPdf ? 'SI' : 'NO').', Estado: '.($user['disabled'] ? 'DESHABILITADO' : 'ACTIVO').')');
        }
        
        log_line($logPath, 'LIST: Procesamiento completado');
        log_line($logPath, 'LIST: Total vouchers en respuesta: '.count($vouchers));
        log_line($logPath, '========== FIN LISTADO DE VOUCHERS ==========');
        
        echo json_encode([
            'success' => true,
            'vouchers' => $vouchers,
            'mikrotik_connected' => true,
            'mikrotik_users_count' => count($mikrotikUsers),
            'source' => 'mikrotik_only'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Operación: Eliminar voucher
    if ($action === 'delete') {
        $code = strtoupper(trim((string)($_GET['code'] ?? $_POST['code'] ?? '')));
        if ($code === '') {
            log_line($logPath, 'DELETE: ERROR - código de voucher no proporcionado');
            throw new \Exception('Código de voucher no proporcionado');
        }
        
        log_line($logPath, '========== INICIO ELIMINACIÓN DE VOUCHER ==========');
        log_line($logPath, 'DELETE: Timestamp: '.date('Y-m-d H:i:s'));
        log_line($logPath, 'DELETE: Código de voucher: '.$code);
        
        $customer = $router['customer'] ?? 'admin';
        log_line($logPath, 'DELETE: Customer configurado: '.$customer);
        
        // Conectar a MikroTik
        log_line($logPath, 'DELETE: Iniciando conexión a MikroTik...');
        log_line($logPath, 'DELETE: Router host: '.($router['host'] ?? 'NO CONFIGURADO'));
        log_line($logPath, 'DELETE: Router API port: '.($router['api_port'] ?? 'NO CONFIGURADO'));
        
        $client = get_mikrotik_client($router, $logPath);
        
        if (!$client || $client === null) {
            log_line($logPath, 'DELETE: ERROR CRITICO - No se pudo conectar a MikroTik');
            throw new \Exception('No se pudo conectar al router MikroTik. Verifica la configuración.');
        }
        
        log_line($logPath, 'DELETE: Conexión a MikroTik establecida exitosamente');
        log_line($logPath, 'DELETE: Procediendo a eliminar usuario del User Manager...');
        
        // Eliminar de MikroTik User Manager
        $deleted = delete_mikrotik_user($client, $code, $customer, $logPath);
        
        if (!$deleted) {
            log_line($logPath, 'DELETE: ADVERTENCIA - No se pudo eliminar el usuario de User Manager');
            log_line($logPath, 'DELETE: Es posible que el usuario no exista en el router');
            // No lanzamos excepción aquí para permitir que continúe y elimine el PDF
        } else {
            log_line($logPath, 'DELETE: Usuario eliminado exitosamente de User Manager');
        }
        
        // Eliminar archivo PDF si existe
        log_line($logPath, 'DELETE: Verificando existencia de archivo PDF...');
        $pdfPath = $ticketsDir . '/voucher-' . $code . '.pdf';
        log_line($logPath, 'DELETE: Ruta PDF: '.$pdfPath);
        
        if (file_exists($pdfPath)) {
            log_line($logPath, 'DELETE: PDF encontrado, procediendo a eliminar...');
            $unlinkResult = @unlink($pdfPath);
            if ($unlinkResult) {
                log_line($logPath, 'DELETE: PDF eliminado exitosamente: '.$pdfPath);
            } else {
                log_line($logPath, 'DELETE: ERROR - No se pudo eliminar PDF: '.$pdfPath);
            }
        } else {
            log_line($logPath, 'DELETE: No existe archivo PDF para este voucher (puede haber sido eliminado previamente)');
        }
        
        log_line($logPath, 'DELETE: Operación de eliminación completada');
        log_line($logPath, '========== FIN ELIMINACIÓN DE VOUCHER ==========');
        
        echo json_encode([
            'success' => true,
            'message' => 'Voucher eliminado correctamente del router MikroTik',
            'deleted_from_mikrotik' => $deleted,
            'code' => $code
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Operación: Eliminar vouchers vencidos
    if ($action === 'delete_expired') {
        log_line($logPath, '========== INICIO ELIMINACIÓN DE VOUCHERS VENCIDOS ==========');
        log_line($logPath, 'EXPIRED: Timestamp: '.date('Y-m-d H:i:s'));
        log_line($logPath, 'EXPIRED: NOTA - Sin registro local, esta operación eliminará TODOS los usuarios del customer');
        log_line($logPath, 'EXPIRED: Para evitar eliminar usuarios activos, se recomienda gestionar manualmente');
        
        $customer = $router['customer'] ?? 'admin';
        log_line($logPath, 'EXPIRED: Customer: '.$customer);
        
        // Esta operación ahora simplemente reporta que no está disponible sin registro local
        log_line($logPath, 'EXPIRED: Operación no disponible sin registro local de timestamps');
        log_line($logPath, '========== FIN ELIMINACIÓN DE VOUCHERS VENCIDOS ==========');
        
        echo json_encode([
            'success' => false,
            'error' => 'Operación no disponible sin registro local. Los vouchers ahora se gestionan completamente desde MikroTik User Manager. Para eliminar usuarios vencidos, debes hacerlo manualmente desde el router.',
            'deleted_count' => 0,
            'error_count' => 0,
            'deleted_codes' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Operación: Reimprimir voucher
    if ($action === 'reprint') {
        log_line($logPath, 'REPRINT: Solicitando reimpresión de voucher');
        $code = strtoupper(trim((string)($_GET['code'] ?? $_POST['code'] ?? '')));
        if ($code === '') {
            throw new \Exception('Código de voucher no proporcionado');
        }
        
        $pdfPath = $ticketsDir . '/voucher-' . $code . '.pdf';
        if (!file_exists($pdfPath)) {
            log_line($logPath, 'REPRINT: ERROR - PDF no encontrado para voucher '.$code);
            throw new \Exception('PDF del voucher no encontrado. Puede haber sido eliminado o no generado.');
        }
        
        log_line($logPath, 'REPRINT: Enviando PDF de voucher '.$code);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="voucher-'.$code.'-reimpresion.pdf"');
        header('Cache-Control: no-store');
        readfile($pdfPath);
        log_line($logPath, 'REPRINT: PDF enviado exitosamente');
        exit;
    }
    
    // Operación: Modificar perfil
    if ($action === 'modify_profile') {
        $code = strtoupper(trim((string)($_GET['code'] ?? $_POST['code'] ?? '')));
        $newProfile = $_GET['profile'] ?? $_POST['profile'] ?? '';
        
        log_line($logPath, '========== INICIO MODIFICAR PERFIL ==========');
        log_line($logPath, 'MODIFY_PROFILE: Timestamp: '.date('Y-m-d H:i:s'));
        log_line($logPath, 'MODIFY_PROFILE: Código de voucher: '.$code);
        log_line($logPath, 'MODIFY_PROFILE: Nuevo perfil: '.$newProfile);
        
        if ($code === '' || $newProfile === '') {
            log_line($logPath, 'MODIFY_PROFILE: ERROR - Parámetros incompletos');
            throw new \Exception('Código de voucher o perfil no proporcionado');
        }
        
        $customer = $router['customer'] ?? 'admin';
        log_line($logPath, 'MODIFY_PROFILE: Customer: '.$customer);
        
        // Obtener perfil UM correspondiente
        $umProfile = $profiles[$newProfile]['um_profile'] ?? $newProfile;
        log_line($logPath, 'MODIFY_PROFILE: Perfil UM: '.$umProfile);
        log_line($logPath, 'MODIFY_PROFILE: Perfiles disponibles: '.json_encode(array_keys($profiles)));
        
        // Conectar a MikroTik
        log_line($logPath, 'MODIFY_PROFILE: Conectando a MikroTik...');
        $client = get_mikrotik_client($router, $logPath);
        
        if (!$client || $client === null) {
            log_line($logPath, 'MODIFY_PROFILE: ERROR - No se pudo conectar al router');
            throw new \Exception('No se pudo conectar al router MikroTik');
        }
        
        log_line($logPath, 'MODIFY_PROFILE: Conexión exitosa');
        log_line($logPath, 'MODIFY_PROFILE: Aplicando cambios en router...');
        
        $success = modify_mikrotik_profile($client, $code, $customer, $umProfile, $logPath);
        
        if (!$success) {
            log_line($logPath, 'MODIFY_PROFILE: ERROR - No se pudo modificar en router');
            throw new \Exception('No se pudo modificar el perfil en el router');
        }
        
        log_line($logPath, 'MODIFY_PROFILE: Perfil modificado exitosamente en MikroTik');
        log_line($logPath, '========== FIN MODIFICAR PERFIL ==========');
        
        echo json_encode([
            'success' => true,
            'message' => 'Perfil modificado correctamente en MikroTik',
            'code' => $code,
            'new_profile' => $newProfile,
            'um_profile' => $umProfile
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Operación: Reiniciar tiempo
    if ($action === 'reset_time') {
        $code = strtoupper(trim((string)($_GET['code'] ?? $_POST['code'] ?? '')));
        
        log_line($logPath, '========== INICIO REINICIAR TIEMPO ==========');
        log_line($logPath, 'RESET_TIME: Timestamp: '.date('Y-m-d H:i:s'));
        log_line($logPath, 'RESET_TIME: Código de voucher: '.$code);
        
        if ($code === '') {
            log_line($logPath, 'RESET_TIME: ERROR - Código no proporcionado');
            throw new \Exception('Código de voucher no proporcionado');
        }
        
        $customer = $router['customer'] ?? 'admin';
        log_line($logPath, 'RESET_TIME: Customer: '.$customer);
        
        // Conectar a MikroTik para obtener el perfil actual del usuario
        log_line($logPath, 'RESET_TIME: Conectando a MikroTik para obtener perfil actual...');
        $client = get_mikrotik_client($router, $logPath);
        
        if (!$client || $client === null) {
            log_line($logPath, 'RESET_TIME: ERROR - No se pudo conectar al router');
            throw new \Exception('No se pudo conectar al router MikroTik');
        }
        
        log_line($logPath, 'RESET_TIME: Conexión exitosa');
        log_line($logPath, 'RESET_TIME: Buscando usuario en User Manager...');
        
        // Obtener información del usuario desde MikroTik
        $mikrotikUsers = get_mikrotik_users($client, $customer, $logPath);
        
        if (!isset($mikrotikUsers[$code])) {
            log_line($logPath, 'RESET_TIME: ERROR - Usuario no encontrado en MikroTik');
            throw new \Exception('Usuario no encontrado en MikroTik User Manager');
        }
        
        $currentProfile = $mikrotikUsers[$code]['profile'];
        log_line($logPath, 'RESET_TIME: Perfil actual del usuario: '.$currentProfile);
        
        // Si el perfil está vacío, intentar obtenerlo de la configuración
        if (empty($currentProfile)) {
            log_line($logPath, 'RESET_TIME: Perfil vacío, usando perfil por defecto');
            $currentProfile = 'two_days'; // Perfil por defecto
        }
        
        $umProfile = $profiles[$currentProfile]['um_profile'] ?? $currentProfile;
        log_line($logPath, 'RESET_TIME: Perfil UM a usar: '.$umProfile);
        
        // Reiniciar tiempo en MikroTik
        log_line($logPath, 'RESET_TIME: Aplicando reinicio de tiempo...');
        $success = reset_mikrotik_time($client, $code, $customer, $umProfile, $logPath);
        
        if (!$success) {
            log_line($logPath, 'RESET_TIME: ERROR - No se pudo reiniciar en router');
            throw new \Exception('No se pudo reiniciar el tiempo en el router');
        }
        
        log_line($logPath, 'RESET_TIME: Tiempo reiniciado exitosamente en MikroTik');
        log_line($logPath, '========== FIN REINICIAR TIEMPO ==========');
        
        echo json_encode([
            'success' => true,
            'message' => 'Tiempo reiniciado correctamente en MikroTik',
            'code' => $code,
            'profile' => $currentProfile,
            'um_profile' => $umProfile
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    log_line($logPath, 'ERROR: Acción no válida recibida: '.$action);
    throw new \Exception('Acción no válida');
    
} catch (\Throwable $e) {
    http_response_code(500);
    log_line($logPath, 'EXCEPTION: '.$e->getMessage());
    log_line($logPath, 'STACK TRACE: '.$e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
