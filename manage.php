<?php
// manage.php - Gestión de vouchers (listar, eliminar, reimprimir, modificar perfil, reiniciar tiempo)

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$baseDir = __DIR__;
$configPath = $baseDir . '/config.json';
$issuedPath = $baseDir . '/issued.json';
$ticketsDir = $baseDir . '/tickets';
$logDir = $baseDir . '/logs';
$logPath = $logDir . '/app.log';

if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }

function log_line(string $path, string $msg): void {
    @file_put_contents($path, '['.date('c')."] ".$msg."\n", FILE_APPEND);
}

function loadIssued(string $path): array {
    if (!file_exists($path)) return ['issued'=>[]];
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : ['issued'=>[]];
}

function saveIssued(string $path, array $data): void {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $bytes = @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    if ($bytes === false) {
        throw new \RuntimeException('No se pudo guardar el registro de vouchers');
    }
}

// Provisión en MikroTik User Manager vía API
function provision_mikrotik_api(array $router, string $customer, string $username, string $password, string $umProfile, string $logPath): ?bool {
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
        
        return $client; // Retornar cliente para operaciones posteriores
    } catch (\Throwable $e) {
        log_line($logPath, 'Excepción API conexión: '.$e->getMessage());
        return false;
    }
}

// Eliminar usuario de MikroTik User Manager
function delete_mikrotik_user($client, string $username, string $customer, string $logPath): bool {
    try {
        if (!$client || $client === false || $client === null) {
            return false;
        }
        
        // Buscar el usuario
        $q = (new \RouterOS\Query('/tool/user-manager/user/print'))
            ->where('username', $username)
            ->where('customer', $customer);
        $users = $client->query($q)->read();
        
        if (empty($users)) {
            log_line($logPath, 'Usuario '.$username.' no encontrado en UM');
            return true; // No existe, así que técnicamente "eliminado"
        }
        
        // Eliminar el usuario
        foreach ($users as $user) {
            $id = $user['.id'] ?? '';
            if ($id) {
                $qDel = (new \RouterOS\Query('/tool/user-manager/user/remove'))
                    ->equal('.id', $id);
                $client->query($qDel)->read();
                log_line($logPath, 'Usuario '.$username.' eliminado de UM');
            }
        }
        
        return true;
    } catch (\Throwable $e) {
        log_line($logPath, 'Error al eliminar usuario: '.$e->getMessage());
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
    
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    // Operación: Listar vouchers
    if ($action === 'list') {
        $issued = loadIssued($issuedPath);
        
        // Añadir información adicional sobre archivos PDF
        foreach ($issued['issued'] as &$voucher) {
            $pdfPath = $ticketsDir . '/voucher-' . $voucher['code'] . '.pdf';
            $voucher['has_pdf'] = file_exists($pdfPath);
        }
        
        echo json_encode([
            'success' => true,
            'vouchers' => array_reverse($issued['issued']) // Más recientes primero
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Operación: Eliminar voucher
    if ($action === 'delete') {
        $code = strtoupper(trim((string)($_GET['code'] ?? $_POST['code'] ?? '')));
        if ($code === '') {
            throw new \Exception('Código de voucher no proporcionado');
        }
        
        $issued = loadIssued($issuedPath);
        $found = false;
        $customer = $router['customer'] ?? 'admin';
        
        foreach ($issued['issued'] as $key => $voucher) {
            if ($voucher['code'] === $code) {
                $found = true;
                
                // Intentar eliminar de MikroTik
                $client = provision_mikrotik_api($router, $customer, $code, $code, '', $logPath);
                if ($client && $client !== false && $client !== null) {
                    delete_mikrotik_user($client, $code, $customer, $logPath);
                }
                
                // Eliminar del registro
                unset($issued['issued'][$key]);
                
                // Eliminar archivo PDF si existe
                $pdfPath = $ticketsDir . '/voucher-' . $code . '.pdf';
                if (file_exists($pdfPath)) {
                    @unlink($pdfPath);
                }
                
                break;
            }
        }
        
        if (!$found) {
            throw new \Exception('Voucher no encontrado');
        }
        
        // Reindexar array
        $issued['issued'] = array_values($issued['issued']);
        saveIssued($issuedPath, $issued);
        
        log_line($logPath, 'Voucher '.$code.' eliminado');
        
        echo json_encode([
            'success' => true,
            'message' => 'Voucher eliminado correctamente'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Operación: Reimprimir voucher
    if ($action === 'reprint') {
        $code = strtoupper(trim((string)($_GET['code'] ?? $_POST['code'] ?? '')));
        if ($code === '') {
            throw new \Exception('Código de voucher no proporcionado');
        }
        
        $pdfPath = $ticketsDir . '/voucher-' . $code . '.pdf';
        if (!file_exists($pdfPath)) {
            throw new \Exception('PDF del voucher no encontrado. Puede haber sido eliminado o no generado.');
        }
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="voucher-'.$code.'-reimpresion.pdf"');
        header('Cache-Control: no-store');
        readfile($pdfPath);
        exit;
    }
    
    // Operación: Modificar perfil
    if ($action === 'modify_profile') {
        $code = strtoupper(trim((string)($_GET['code'] ?? $_POST['code'] ?? '')));
        $newProfile = $_GET['profile'] ?? $_POST['profile'] ?? '';
        
        if ($code === '' || $newProfile === '') {
            throw new \Exception('Código de voucher o perfil no proporcionado');
        }
        
        $issued = loadIssued($issuedPath);
        $found = false;
        $customer = $router['customer'] ?? 'admin';
        
        foreach ($issued['issued'] as &$voucher) {
            if ($voucher['code'] === $code) {
                $found = true;
                
                // Obtener perfil UM correspondiente
                $umProfile = $profiles[$newProfile]['um_profile'] ?? $newProfile;
                
                // Modificar en MikroTik
                $client = provision_mikrotik_api($router, $customer, $code, $code, $umProfile, $logPath);
                if ($client && $client !== false && $client !== null) {
                    $success = modify_mikrotik_profile($client, $code, $customer, $umProfile, $logPath);
                    if (!$success) {
                        throw new \Exception('No se pudo modificar el perfil en el router');
                    }
                }
                
                // Actualizar en registro
                $voucher['profile'] = $newProfile;
                $voucher['modified_at'] = date('c');
                
                break;
            }
        }
        
        if (!$found) {
            throw new \Exception('Voucher no encontrado');
        }
        
        saveIssued($issuedPath, $issued);
        
        log_line($logPath, 'Perfil de voucher '.$code.' modificado a '.$newProfile);
        
        echo json_encode([
            'success' => true,
            'message' => 'Perfil modificado correctamente'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Operación: Reiniciar tiempo
    if ($action === 'reset_time') {
        $code = strtoupper(trim((string)($_GET['code'] ?? $_POST['code'] ?? '')));
        if ($code === '') {
            throw new \Exception('Código de voucher no proporcionado');
        }
        
        $issued = loadIssued($issuedPath);
        $found = false;
        $customer = $router['customer'] ?? 'admin';
        
        foreach ($issued['issued'] as &$voucher) {
            if ($voucher['code'] === $code) {
                $found = true;
                
                // Obtener perfil UM correspondiente
                $currentProfile = $voucher['profile'];
                $umProfile = $profiles[$currentProfile]['um_profile'] ?? $currentProfile;
                
                // Reiniciar en MikroTik
                $client = provision_mikrotik_api($router, $customer, $code, $code, $umProfile, $logPath);
                if ($client && $client !== false && $client !== null) {
                    $success = reset_mikrotik_time($client, $code, $customer, $umProfile, $logPath);
                    if (!$success) {
                        throw new \Exception('No se pudo reiniciar el tiempo en el router');
                    }
                }
                
                // Actualizar timestamp en registro
                $voucher['reset_at'] = date('c');
                
                break;
            }
        }
        
        if (!$found) {
            throw new \Exception('Voucher no encontrado');
        }
        
        saveIssued($issuedPath, $issued);
        
        log_line($logPath, 'Tiempo de voucher '.$code.' reiniciado');
        
        echo json_encode([
            'success' => true,
            'message' => 'Tiempo reiniciado correctamente'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    throw new \Exception('Acción no válida');
    
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    log_line($logPath, 'Error en manage.php: '.$e->getMessage());
}
