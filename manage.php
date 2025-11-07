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
            log_line($logPath, 'DELETE: Error - código de voucher no proporcionado');
            throw new \Exception('Código de voucher no proporcionado');
        }
        
        log_line($logPath, '===== INICIO ELIMINACIÓN DE VOUCHER '.$code.' =====');
        
        $issued = loadIssued($issuedPath);
        $found = false;
        $customer = $router['customer'] ?? 'admin';
        
        log_line($logPath, 'DELETE: Customer configurado: '.$customer);
        log_line($logPath, 'DELETE: Total de vouchers en registro: '.count($issued['issued']));
        
        foreach ($issued['issued'] as $key => $voucher) {
            if ($voucher['code'] === $code) {
                $found = true;
                log_line($logPath, 'DELETE: Voucher '.$code.' encontrado en posición '.$key);
                
                // Intentar eliminar de MikroTik
                log_line($logPath, 'DELETE: Conectando a MikroTik router...');
                $client = provision_mikrotik_api($router, $customer, $code, $code, '', $logPath);
                
                if ($client && $client !== false && $client !== null) {
                    log_line($logPath, 'DELETE: Conexión exitosa, procediendo a eliminar de User Manager');
                    $deleted = delete_mikrotik_user($client, $code, $customer, $logPath);
                    if ($deleted) {
                        log_line($logPath, 'DELETE: Usuario eliminado exitosamente de User Manager');
                    } else {
                        log_line($logPath, 'DELETE: ADVERTENCIA - No se pudo eliminar usuario de User Manager');
                    }
                } else {
                    log_line($logPath, 'DELETE: No se pudo conectar a MikroTik router (conexión fallida o no configurada)');
                }
                
                // Eliminar del registro local
                log_line($logPath, 'DELETE: Eliminando del registro local (issued.json)');
                unset($issued['issued'][$key]);
                
                // Eliminar archivo PDF si existe
                $pdfPath = $ticketsDir . '/voucher-' . $code . '.pdf';
                if (file_exists($pdfPath)) {
                    $unlinkResult = @unlink($pdfPath);
                    if ($unlinkResult) {
                        log_line($logPath, 'DELETE: PDF eliminado: '.$pdfPath);
                    } else {
                        log_line($logPath, 'DELETE: No se pudo eliminar PDF: '.$pdfPath);
                    }
                } else {
                    log_line($logPath, 'DELETE: No existe PDF para este voucher');
                }
                
                break;
            }
        }
        
        if (!$found) {
            log_line($logPath, 'DELETE: ERROR - Voucher '.$code.' no encontrado en registro');
            throw new \Exception('Voucher no encontrado');
        }
        
        // Reindexar array
        $issued['issued'] = array_values($issued['issued']);
        saveIssued($issuedPath, $issued);
        log_line($logPath, 'DELETE: Registro actualizado. Vouchers restantes: '.count($issued['issued']));
        
        log_line($logPath, '===== FIN ELIMINACIÓN DE VOUCHER '.$code.' =====');
        
        echo json_encode([
            'success' => true,
            'message' => 'Voucher eliminado correctamente'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Operación: Eliminar vouchers vencidos
    if ($action === 'delete_expired') {
        log_line($logPath, '===== INICIO ELIMINACIÓN DE VOUCHERS VENCIDOS =====');
        
        $issued = loadIssued($issuedPath);
        $customer = $router['customer'] ?? 'admin';
        $profiles = $config['profiles'] ?? [];
        
        $now = time();
        $deletedCount = 0;
        $errorCount = 0;
        $deletedCodes = [];
        
        // Mapeo de perfiles a segundos
        $profileDurations = [
            'two_days' => 2 * 24 * 3600,
            'one_week' => 7 * 24 * 3600,
            'one_month' => 30 * 24 * 3600,
        ];
        
        log_line($logPath, 'EXPIRED: Total vouchers a revisar: '.count($issued['issued']));
        
        // Conectar una sola vez al router
        $client = provision_mikrotik_api($router, $customer, '', '', '', $logPath);
        if (!$client || $client === false || $client === null) {
            log_line($logPath, 'EXPIRED: ADVERTENCIA - No se pudo conectar al router, solo se eliminarán del registro local');
        }
        
        foreach ($issued['issued'] as $key => $voucher) {
            $code = $voucher['code'];
            $profile = $voucher['profile'];
            $timestamp = strtotime($voucher['ts']);
            $duration = $profileDurations[$profile] ?? (7 * 24 * 3600); // default 1 semana
            
            $expiryTime = $timestamp + $duration;
            $isExpired = $now > $expiryTime;
            
            if ($isExpired) {
                $daysExpired = floor(($now - $expiryTime) / 86400);
                log_line($logPath, 'EXPIRED: Voucher '.$code.' vencido hace '.$daysExpired.' días (perfil: '.$profile.')');
                
                // Eliminar de MikroTik si hay conexión
                if ($client && $client !== false && $client !== null) {
                    $deleted = delete_mikrotik_user($client, $code, $customer, $logPath);
                    if (!$deleted) {
                        log_line($logPath, 'EXPIRED: Error al eliminar '.$code.' de User Manager');
                        $errorCount++;
                    }
                }
                
                // Eliminar PDF
                $pdfPath = $ticketsDir . '/voucher-' . $code . '.pdf';
                if (file_exists($pdfPath)) {
                    @unlink($pdfPath);
                }
                
                // Marcar para eliminación del registro
                $deletedCodes[] = $code;
                unset($issued['issued'][$key]);
                $deletedCount++;
            }
        }
        
        // Guardar registro actualizado
        if ($deletedCount > 0) {
            $issued['issued'] = array_values($issued['issued']);
            saveIssued($issuedPath, $issued);
            log_line($logPath, 'EXPIRED: Registro actualizado. Vouchers eliminados: '.$deletedCount);
            log_line($logPath, 'EXPIRED: Códigos eliminados: '.implode(', ', $deletedCodes));
        }
        
        log_line($logPath, '===== FIN ELIMINACIÓN DE VOUCHERS VENCIDOS =====');
        
        echo json_encode([
            'success' => true,
            'message' => $deletedCount.' voucher(s) vencido(s) eliminado(s)',
            'deleted_count' => $deletedCount,
            'error_count' => $errorCount,
            'deleted_codes' => $deletedCodes
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
        
        log_line($logPath, 'MODIFY_PROFILE: Iniciando modificación de perfil para voucher '.$code.' a '.$newProfile);
        
        if ($code === '' || $newProfile === '') {
            log_line($logPath, 'MODIFY_PROFILE: ERROR - Parámetros incompletos');
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
                log_line($logPath, 'MODIFY_PROFILE: Perfil UM: '.$umProfile);
                
                // Modificar en MikroTik
                $client = provision_mikrotik_api($router, $customer, $code, $code, $umProfile, $logPath);
                if ($client && $client !== false && $client !== null) {
                    log_line($logPath, 'MODIFY_PROFILE: Conexión exitosa, aplicando cambios en router');
                    $success = modify_mikrotik_profile($client, $code, $customer, $umProfile, $logPath);
                    if (!$success) {
                        log_line($logPath, 'MODIFY_PROFILE: ERROR - No se pudo modificar en router');
                        throw new \Exception('No se pudo modificar el perfil en el router');
                    }
                } else {
                    log_line($logPath, 'MODIFY_PROFILE: ADVERTENCIA - No se pudo conectar al router');
                }
                
                // Actualizar en registro
                $voucher['profile'] = $newProfile;
                $voucher['modified_at'] = date('c');
                log_line($logPath, 'MODIFY_PROFILE: Registro actualizado');
                
                break;
            }
        }
        
        if (!$found) {
            throw new \Exception('Voucher no encontrado');
        }
        
        saveIssued($issuedPath, $issued);
        
        log_line($logPath, 'MODIFY_PROFILE: Operación completada exitosamente');
        
        echo json_encode([
            'success' => true,
            'message' => 'Perfil modificado correctamente'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Operación: Reiniciar tiempo
    if ($action === 'reset_time') {
        $code = strtoupper(trim((string)($_GET['code'] ?? $_POST['code'] ?? '')));
        
        log_line($logPath, 'RESET_TIME: Iniciando reinicio de tiempo para voucher '.$code);
        
        if ($code === '') {
            log_line($logPath, 'RESET_TIME: ERROR - Código no proporcionado');
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
                log_line($logPath, 'RESET_TIME: Perfil actual: '.$currentProfile.' (UM: '.$umProfile.')');
                
                // Reiniciar en MikroTik
                $client = provision_mikrotik_api($router, $customer, $code, $code, $umProfile, $logPath);
                if ($client && $client !== false && $client !== null) {
                    log_line($logPath, 'RESET_TIME: Conexión exitosa, aplicando reinicio');
                    $success = reset_mikrotik_time($client, $code, $customer, $umProfile, $logPath);
                    if (!$success) {
                        log_line($logPath, 'RESET_TIME: ERROR - No se pudo reiniciar en router');
                        throw new \Exception('No se pudo reiniciar el tiempo en el router');
                    }
                } else {
                    log_line($logPath, 'RESET_TIME: ADVERTENCIA - No se pudo conectar al router');
                }
                
                // Actualizar timestamp en registro
                $voucher['reset_at'] = date('c');
                log_line($logPath, 'RESET_TIME: Timestamp actualizado');
                
                break;
            }
        }
        
        if (!$found) {
            throw new \Exception('Voucher no encontrado');
        }
        
        saveIssued($issuedPath, $issued);
        
        log_line($logPath, 'RESET_TIME: Operación completada exitosamente');
        
        echo json_encode([
            'success' => true,
            'message' => 'Tiempo reiniciado correctamente'
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
