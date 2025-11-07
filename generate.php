<?php
// generate.php - Crea voucher y genera PDF (Dompdf si está disponible) o HTML fallback

declare(strict_types=1);

$baseDir = __DIR__;
$configPath = $baseDir . '/config.json';
$issuedPath = $baseDir . '/issued.json';
$ticketsDir = $baseDir . '/tickets';

$config = json_decode(@file_get_contents($configPath), true) ?? [];
$branding = $config['branding'] ?? [];
$theme = $config['theme'] ?? [];
$vconf = $config['vouchers'] ?? [];
$printing = $config['printing'] ?? [];

$hostelName = $branding['hostel_name'] ?? 'HOSTAL';
$ssid = isset($_GET['ssid']) && $_GET['ssid'] !== '' ? trim((string)$_GET['ssid']) : ($branding['ssid'] ?? 'WIFI');
$portal = $branding['portal_url'] ?? '';
$profile = $_GET['profile'] ?? 'two_days';
$code = strtoupper(trim((string)($_GET['code'] ?? '')));

$length = max(4, (int)($vconf['length'] ?? 6));
$charset = $vconf['charset'] ?? 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$registryPath = $vconf['local_registry_path'] ?? './issued.json';
$registryPath = str_starts_with($registryPath, '.') ? ($baseDir . '/' . ltrim($registryPath, './')) : $registryPath;

$paperWidthMm = (float)($printing['paper_width_mm'] ?? 80);
$pdfLogoPath = $printing['logo_path'] ?? ($baseDir . '/logo-full-print.png');
if (!preg_match('~^[A-Za-z]:~', $pdfLogoPath) && !str_starts_with($pdfLogoPath, '/')) {
    $pdfLogoPath = $baseDir . '/' . ltrim($pdfLogoPath, './');
}

// Provisión en MikroTik User Manager vía API (RouterOS), devuelve true/false si intentó, o null si no disponible
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

        log_line($logPath, 'Ejecutando provisión UM vía API.');
        $q1 = (new \RouterOS\Query('/tool/user-manager/user/add'))
            ->equal('customer', $customer)
            ->equal('username', $username)
            ->equal('password', $password)
            ->equal('disabled', 'no');
        $client->query($q1)->read();

        $q2 = (new \RouterOS\Query('/tool/user-manager/user/create-and-activate-profile'))
            ->equal('customer', $customer)
            ->equal('profile', $umProfile)
            ->equal('numbers', $username);
        $client->query($q2)->read();

        log_line($logPath, 'Provisión UM completada vía API para usuario '.$username.' con perfil '.$umProfile);
        return true;
    } catch (\Throwable $e) {
        log_line($logPath, 'Excepción API: '.$e->getMessage());
        return false;
    }
}

$profileLabel = [
    'two_days' => '2 días',
    'one_week' => '1 semana',
    'one_month' => '1 mes',
][$profile] ?? $profile;

// Config perfiles y router
$profiles = $config['profiles'] ?? [];
$umProfile = $profiles[$profile]['um_profile'] ?? $profile;
$router = $config['router'] ?? [];

// Logging básico a logs/app.log
$logDir = $baseDir . '/logs';
$logPath = $logDir . '/app.log';
if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }
function log_line(string $path, string $msg): void {
    @file_put_contents($path, '['.date('c')."] ".$msg."\n", FILE_APPEND);
}

// Provisión en MikroTik User Manager vía SSH (si ssh2 está disponible)
function provision_mikrotik(array $router, string $customer, string $username, string $password, string $umProfile, string $logPath): bool {
    $host = $router['host'] ?? '';
    $sshPort = (int)($router['ssh_port'] ?? 22);
    $sshUser = $router['username'] ?? '';
    $sshPass = $router['password'] ?? '';
    if ($host === '' || $sshUser === '') {
        log_line($logPath, 'Router config incompleta, saltando provisión.');
        return false;
    }
    $cmd1 = "/tool user-manager user add customer=".escapeshellarg($customer)." username=".escapeshellarg($username)." password=".escapeshellarg($password)." disabled=no";
    $cmd2 = "/tool user-manager user create-and-activate-profile customer=".escapeshellarg($customer)." profile=".escapeshellarg($umProfile)." numbers=".escapeshellarg($username)."";
    log_line($logPath, 'Comandos UM a ejecutar:');
    log_line($logPath, $cmd1);
    log_line($logPath, $cmd2);

    if (!function_exists('ssh2_connect')) {
        log_line($logPath, 'Extensión ssh2 no disponible en PHP; no se puede ejecutar en router.');
        return false;
    }
    try {
        $conn = @ssh2_connect($host, $sshPort);
        if (!$conn) { log_line($logPath, 'ssh2_connect falló.'); return false; }
        if (!@ssh2_auth_password($conn, $sshUser, $sshPass)) { log_line($logPath, 'ssh2_auth_password falló.'); return false; }
        foreach ([$cmd1, $cmd2] as $cmd) {
            $stream = @ssh2_exec($conn, $cmd);
            if (!$stream) { log_line($logPath, 'ssh2_exec falló para: '.$cmd); return false; }
            stream_set_blocking($stream, true);
            $out = stream_get_contents($stream);
            fclose($stream);
            if ($out !== false && trim($out) !== '') {
                foreach (explode("\n", $out) as $line) { log_line($logPath, 'SSH OUT: '.$line); }
            }
        }
        log_line($logPath, 'Provisión UM completada para usuario '.$username.' con perfil '.$umProfile);
        return true;
    } catch (Throwable $e) {
        log_line($logPath, 'Excepción SSH: '.$e->getMessage());
        return false;
    }
}

function loadIssued(string $path): array {
    if (!file_exists($path)) return ['issued'=>[]];
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : ['issued'=>[]];
}

function saveIssued(string $path, array $data): void {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}

function generateCode(int $length, string $charset): string {
    $out = '';
    $max = strlen($charset) - 1;
    for ($i=0; $i<$length; $i++) {
        $out .= $charset[random_int(0, $max)];
    }
    return $out;
}

$issued = loadIssued($issuedPath);
$existing = array_column($issued['issued'], 'code');

if ($code === '') {
    // Generar evitando colisión
    $tries = 0;
    do {
        $code = generateCode($length, $charset);
        $tries++;
    } while (in_array($code, $existing, true) && $tries < 20);
} else {
    if (in_array($code, $existing, true)) {
        // ya existe; generar uno nuevo con sufijo para evitar conflicto
        $base = rtrim($code);
        $suffix = generateCode(2, $charset);
        $code = substr($base, 0, max(1, $length-2)) . $suffix;
    }
}

$issued['issued'][] = [ 'code'=>$code, 'ts'=>date('c'), 'profile'=>$profile, 'ssid'=>$ssid ];
saveIssued($issuedPath, $issued);

// Provisión en MikroTik (crear usuario y activar perfil)
$customer = $router['customer'] ?? 'admin';
$sameUserPass = (bool)($vconf['same_user_and_pass'] ?? true);
$password = $sameUserPass ? $code : $code; // actualmente mismo código
log_line($logPath, "Iniciando provisión UM para código $code, perfil $umProfile, customer $customer");
$apiResult = provision_mikrotik_api($router, $customer, $code, $password, $umProfile, $logPath);
if ($apiResult === null || $apiResult === false) {
    $provOk = provision_mikrotik($router, $customer, $code, $password, $umProfile, $logPath);
} else {
    $provOk = true;
}
log_line($logPath, $provOk ? 'Provisión UM OK' : 'Provisión UM NO EJECUTADA o FALLÓ (ver líneas anteriores)');

// Contenido del ticket (HTML base)
$primary = $theme['primary'] ?? '#011F4A';
$accent = $theme['accent'] ?? '#EEB247';
$ticketHtml = function(string $forPdf) use($hostelName,$code,$profileLabel,$ssid,$portal,$primary,$accent,$pdfLogoPath) {
    $logoTag = '';
    if (is_file($pdfLogoPath)) {
        $src = $forPdf === 'pdf' ? $pdfLogoPath : (htmlspecialchars(basename($pdfLogoPath)));
        if ($forPdf !== 'pdf' && !file_exists(basename($pdfLogoPath))) {
            // intentar servir desde ruta absoluta en HTML, si existe
            $src = $pdfLogoPath;
        }
        $logoTag = '<img src="'.htmlspecialchars($src).'" style="max-width:100%;height:auto;">';
    }

    return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
        .'<style>
        @page{ size: 80mm auto; margin: 6mm; }
        body{ font-family: Arial, Helvetica, sans-serif; }
        .ticket{ width: 80mm; }
        .brand{ text-align:center; color: '.$primary.'; }
        .code{ text-align:center; font-size: 28px; letter-spacing:2px; font-weight:700; margin: 8px 0; }
        .box{ border:1px dashed '.$accent.'; padding:8px; text-align:center; margin:6px 0; }
        .muted{ color:#555; font-size:12px; }
        .divider{ height:1px; background: #e5e5e5; margin: 10px 0; }
        .logo{ text-align:center; margin-bottom:8px; }
        .title{ font-weight:700; text-transform:uppercase; letter-spacing:1px; }
        </style></head><body>
        <div class="ticket">
            <div class="logo">'.$logoTag.'</div>
            <div class="brand">
              <div class="title">'.htmlspecialchars($hostelName).'</div>
            </div>
            <div class="divider"></div>
            <div class="code">'.htmlspecialchars($code).'</div>
            <div class="box">Duración: '.htmlspecialchars($profileLabel).'</div>
            <div class="box">PIN: <strong>'.htmlspecialchars($code).'</strong></div>
            <div class="divider"></div>
            <div class="muted">
              <div><strong>Cómo conectarse</strong></div>
              <div>1) Busca la red WiFi: '.htmlspecialchars($ssid).'</div>
              <div>2) Conéctate a esta red</div>
              <div>3) Abre tu navegador web</div>
              <div>4) Ingresa a: '.htmlspecialchars($portal).'</div>
              <div>5) Escribe el PIN y confirma</div>
            </div>
            <div class="divider"></div>
            <div class="muted" style="text-align:center;">Gracias por elegirnos</div>
        </div>
        </body></html>';
};

// Intentar Dompdf si está disponible
$dompdfAvailable = false;
$mpdfAvailable = false;
$vendorAutoload = $baseDir . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
    if (class_exists('Mpdf\\Mpdf')) {
        $mpdfAvailable = true;
    }
    if (class_exists('Dompdf\\Dompdf')) {
        $dompdfAvailable = true;
    }
}

if ($mpdfAvailable) {
    try {
        $html = $ticketHtml('pdf');
        $wMm = $paperWidthMm;
        $hMm = 150.0;
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => [$wMm, $hMm],
            'margin_left' => 6,
            'margin_right' => 6,
            'margin_top' => 6,
            'margin_bottom' => 6,
        ]);
        $mpdf->WriteHTML($html);

        if (!is_dir($ticketsDir)) @mkdir($ticketsDir, 0777, true);
        $outPath = $ticketsDir . '/voucher-' . $code . '.pdf';
        $mpdf->Output($outPath, \Mpdf\Output\Destination::FILE);

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="voucher-'.$code.'.pdf"');
        header('Cache-Control: no-store');
        readfile($outPath);
        exit;
    } catch (Throwable $e) {
        
    }
}

if ($dompdfAvailable) {
    try {
        $html = $ticketHtml('pdf');
        $dompdf = new Dompdf\Dompdf([ 'isRemoteEnabled' => true ]);
        $dompdf->loadHtml($html, 'UTF-8');
        // Tamaño página: 80mm x 150mm aprox
        $wPt = $paperWidthMm/25.4*72.0; // mm a puntos
        $hPt = 150/25.4*72.0;
        $dompdf->setPaper([0,0,$wPt,$hPt], 'portrait');
        $dompdf->render();

        if (!is_dir($ticketsDir)) @mkdir($ticketsDir, 0777, true);
        $outPath = $ticketsDir . '/voucher-' . $code . '.pdf';
        file_put_contents($outPath, $dompdf->output());

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="voucher-'.$code.'.pdf"');
        header('Cache-Control: no-store');
        readfile($outPath);
        exit;
    } catch (Throwable $e) {
        // Fallback a HTML si Dompdf falla
    }
}

// Fallback: HTML imprimible en 80mm
header('Content-Type: text/html; charset=utf-8');
echo $ticketHtml('html');
