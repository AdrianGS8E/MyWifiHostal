<?php
// generate.php - Crea voucher y genera PDF (Dompdf si está disponible) o HTML fallback

declare(strict_types=1);

$baseDir = __DIR__;
$configPath = $baseDir . '/config.json';
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

// Manejador global de excepciones: responde 500 y registra en log
set_exception_handler(function($e) use ($logPath) {
    $msg = ($e instanceof \Throwable) ? $e->getMessage() : 'Error inesperado';
    log_line($logPath, 'EXCEPCION: '.$msg);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo 'Error al generar el voucher: '.$msg;
    exit;
});

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

function generateCode(int $length, string $charset): string {
    $out = '';
    $max = strlen($charset) - 1;
    for ($i=0; $i<$length; $i++) {
        $out .= $charset[random_int(0, $max)];
    }
    return $out;
}

// Generar código de voucher
if ($code === '') {
    log_line($logPath, 'Generando código aleatorio de '.$length.' caracteres');
    $code = generateCode($length, $charset);
} else {
    log_line($logPath, 'Usando código proporcionado: '.$code);
}

log_line($logPath, 'Código de voucher: '.$code);

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

// Si la provisión falló, devolver error JSON
if (!$provOk) {
    $errorMsg = 'No se pudo crear el voucher en User Manager. ';
    if ($apiResult === null) {
        $errorMsg .= 'Verifica la configuración del router o que las extensiones PHP necesarias estén instaladas (RouterOS API o ssh2).';
    } else if ($apiResult === false) {
        $errorMsg .= 'No se pudo conectar al router vía API. Verifica host, puerto, credenciales y que el router esté accesible.';
    } else {
        $errorMsg .= 'Error al conectar vía SSH. Verifica que la extensión ssh2 esté instalada y habilitada en PHP.';
    }
    
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'success' => false,
        'error' => $errorMsg,
        'code' => $code,
        'details' => 'Consulta logs/app.log para más información.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Contenido del ticket (HTML base)
$primary = $theme['primary'] ?? '#011F4A';
$accent = $theme['accent'] ?? '#EEB247';
$ticketHtml = function(string $forPdf) use($hostelName,$code,$profileLabel,$ssid,$portal,$primary,$accent,$pdfLogoPath) {
    $logoTag = '';
    if (is_file($pdfLogoPath)) {
        $src = $forPdf === 'pdf' ? $pdfLogoPath : (htmlspecialchars(basename($pdfLogoPath)));
        if ($forPdf !== 'pdf' && !file_exists(basename($pdfLogoPath))) {
            $src = $pdfLogoPath;
        }
        // Limitar tamaño del logo para evitar páginas extras
        $logoTag = '<img src="'.htmlspecialchars($src).'" style="max-width:60mm;max-height:20mm;height:auto;display:block;margin:0 auto;">';
    }

    return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
        .'<style>
        @page{ size: 80mm 150mm; margin: 4mm; }
        *{ margin: 0; padding: 0; box-sizing: border-box; }
        body{ font-family: Arial, Helvetica, sans-serif; font-size: 11px; line-height: 1.3; margin: 0; padding: 0; }
        .ticket{ width: 100%; max-width: 72mm; margin: 0 auto; padding: 3mm; }
        .logo{ text-align:center; margin-bottom: 3mm; }
        .code{ text-align:center; font-size: 22px; letter-spacing: 1px; font-weight: 700; margin: 4mm 0; padding: 3mm; background: #f5f5f5; border: 2px solid '.$primary.'; border-radius: 4px; }
        .instructions{ font-size: 10px; color: #333; line-height: 1.4; margin: 3mm 0; }
        .instructions strong{ display: block; margin-bottom: 2mm; font-size: 11px; color: '.$primary.'; }
        .instructions div{ margin-bottom: 1mm; }
        .divider{ height: 0.5mm; background: #ddd; margin: 3mm 0; }
        .footer{ text-align: center; font-size: 9px; color: #666; margin-top: 3mm; font-style: italic; }
        .wifi-name{ font-weight: bold; color: #000; }
        .duration{ text-align: center; background: '.$accent.'; color: #fff; padding: 2mm; margin: 2mm 0; border-radius: 3px; font-size: 10px; font-weight: bold; }
        </style></head><body>
        <div class="ticket">
            <div class="logo">'.$logoTag.'</div>
            <div style="font-family: "Courier New", Courier, monospace; font-weight: bold;">
                <div class="code">'.htmlspecialchars($code).'</div>
            </div>
            <div class="divider"></div>
            <div class="instructions">
              <strong>Instrucciones de Conexión:</strong>
              <div>1. Busca la red: <span class="wifi-name">'.htmlspecialchars($ssid).'</span></div>
              <div>2. Conéctate a esta red WiFi</div>
              <div>3. Abre tu navegador web</div>'.
              ($portal ? '<div>4. Ve a: '.htmlspecialchars($portal).'</div>' : '').
              '<div>'.($portal ? '5' : '4').'. Ingresa el código de arriba</div>
            </div>
            <div class="divider"></div>
            <div class="footer">¡Disfruta tu conexión WiFi!</div>
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
            'margin_left' => 4,
            'margin_right' => 4,
            'margin_top' => 4,
            'margin_bottom' => 4,
            'margin_header' => 0,
            'margin_footer' => 0,
            'orientation' => 'P',
            'autoPageBreak' => false, // Evitar saltos de página automáticos
        ]);
        
        // Deshabilitar encabezado y pie de página
        $mpdf->SetHTMLHeader('');
        $mpdf->SetHTMLFooter('');
        
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
        log_line($logPath, 'Error mPDF: '.$e->getMessage());
    }
}

if ($dompdfAvailable) {
    try {
        $html = $ticketHtml('pdf');
        $dompdf = new Dompdf\Dompdf([
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'isFontSubsettingEnabled' => true,
        ]);
        $dompdf->loadHtml($html, 'UTF-8');
        // Tamaño página: 80mm x 150mm
        $wPt = $paperWidthMm/25.4*72.0; // mm a puntos
        $hPt = 150/25.4*72.0;
        $dompdf->setPaper([0, 0, $wPt, $hPt], 'portrait');
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
        log_line($logPath, 'Error Dompdf: '.$e->getMessage());
    }
}

// Fallback: HTML imprimible en 80mm
header('Content-Type: text/html; charset=utf-8');
echo $ticketHtml('html');
