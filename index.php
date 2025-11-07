<?php
$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
$theme = $config['theme'] ?? ['primary'=>'#011F4A','accent'=>'#EEB247','logo_ui'=>'./logo_hostal1.png'];
$branding = $config['branding'] ?? ['hostel_name'=>'HOSTAL','ssid'=>'WIFI','portal_url'=>''];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>WifiMaster PHP</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="./assets/styles.css" rel="stylesheet">
  <style>
    :root{
      --bs-primary: <?= htmlspecialchars($theme['primary']) ?>;
      --accent: <?= htmlspecialchars($theme['accent']) ?>;
    }
    .bg-primary-900{ background-color: var(--bs-primary) !important; }
    .text-accent{ color: var(--accent) !important; }
    .btn-accent{ background-color: var(--accent); border-color: var(--accent); color:#011F4A; }
    .btn-accent:hover{ filter: brightness(0.95); }
  </style>
</head>
<body class="bg-light">
  <nav class="navbar navbar-expand-lg bg-primary-900 navbar-dark shadow-sm">
    <div class="container">
      <a class="navbar-brand d-flex align-items-center" href="#">
        <img src="<?= htmlspecialchars($theme['logo_ui']) ?>" alt="logo" height="36" class="me-2">
        <span class="fw-semibold"><?= htmlspecialchars($branding['hostel_name']) ?></span>
      </a>
    </div>
  </nav>

  <main class="container py-4">
    <div class="row g-4">
      <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm">
          <div class="card-body">
            <h5 class="card-title mb-3">Generar Voucher WiFi</h5>
            <form id="voucherForm" class="vstack gap-3" onsubmit="return false;">
              <div>
                <label class="form-label">Duración</label>
                <div class="d-flex gap-2 flex-wrap">
                  <button class="btn btn-outline-primary" data-profile="two_days" type="button">2 días</button>
                  <button class="btn btn-outline-primary" data-profile="one_week" type="button">1 semana</button>
                  <button class="btn btn-outline-primary" data-profile="one_month" type="button">1 mes</button>
                </div>
              </div>
              <div class="row g-2">
                <div class="col-6">
                  <label class="form-label">Código (opcional)</label>
                  <input type="text" class="form-control" id="code" maxlength="12" placeholder="auto">
                </div>
                <div class="col-6">
                  <label class="form-label">SSID</label>
                  <input type="text" class="form-control" id="ssid" value="<?= htmlspecialchars($branding['ssid']) ?>">
                </div>
              </div>
              <div class="d-flex gap-2">
                <button type="button" id="generateBtn" class="btn btn-accent">Generar PDF</button>
                <button type="button" id="printBtn" class="btn btn-outline-secondary" disabled>Imprimir</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <div class="col-12 col-lg-7">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body">
            <h5 class="card-title mb-3">Vista previa</h5>
            <div id="previewHolder" class="ratio ratio-3x4 border rounded bg-white">
              <iframe id="pdfFrame" src="about:blank" title="voucher" style="width:100%;height:100%;" hidden></iframe>
              <div id="placeholder" class="w-100 h-100 d-flex flex-column justify-content-center align-items-center text-muted">
                <div class="mb-2">Aún no hay voucher generado</div>
                <small>Genera un voucher para ver el PDF aquí</small>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script>
    const generateBtn = document.getElementById('generateBtn');
    const printBtn = document.getElementById('printBtn');
    const frame = document.getElementById('pdfFrame');
    const placeholder = document.getElementById('placeholder');

    document.querySelectorAll('[data-profile]').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        document.querySelectorAll('[data-profile]').forEach(b=>b.classList.remove('active'));
        btn.classList.add('active');
        generateBtn.dataset.profile = btn.dataset.profile;
      });
    });

    generateBtn.addEventListener('click', async ()=>{
      const profile = generateBtn.dataset.profile || 'two_days';
      const code = document.getElementById('code').value.trim();
      const ssid = document.getElementById('ssid').value.trim();
      const url = new URL('generate.php', window.location.href);
      url.searchParams.set('profile', profile);
      if(code) url.searchParams.set('code', code);
      if(ssid) url.searchParams.set('ssid', ssid);
      frame.src = url.toString();
      frame.hidden = false;
      placeholder.hidden = true;
      printBtn.disabled = false;
    });

    printBtn.addEventListener('click', ()=>{
      frame.contentWindow.focus();
      frame.contentWindow.print();
    });
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
