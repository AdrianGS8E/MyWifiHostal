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
  <title>WifiMaster PHP - Generador de Vouchers WiFi</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="./assets/styles.css" rel="stylesheet">
  <style>
    :root{
      --bs-primary: <?= htmlspecialchars($theme['primary']) ?>;
      --accent: <?= htmlspecialchars($theme['accent']) ?>;
    }
    body{ background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%); min-height: 100vh; }
    .bg-primary-900{ background-color: var(--bs-primary) !important; }
    .text-accent{ color: var(--accent) !important; }
    .btn-accent{ background-color: var(--accent); border-color: var(--accent); color:#011F4A; font-weight: 600; }
    .btn-accent:hover{ filter: brightness(0.95); color:#011F4A; }
    .card{ border-radius: 12px; transition: transform 0.2s, box-shadow 0.2s; }
    .card:hover{ transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.1) !important; }
    .btn{ border-radius: 8px; }
    .form-control, .form-select{ border-radius: 8px; }
    .navbar{ box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    #pdfViewerContainer{ position: relative; width: 100%; height: 600px; border-radius: 8px; overflow: hidden; background: white; }
    .pdf-controls{ display: flex; gap: 8px; align-items: center; justify-content: center; padding: 8px; background: #f8f9fa; border-bottom: 1px solid #dee2e6; }
    .pdf-controls button{ padding: 4px 12px; font-size: 14px; }
    #message{ font-size: 14px; line-height: 1.6; }
    #messageIcon{ margin-right: 4px; }
    #messageText{ display: inline-block; }
    
    /* Estilos para tabs */
    .nav-tabs .nav-link{ border-radius: 8px 8px 0 0; font-weight: 500; }
    .nav-tabs .nav-link.active{ background-color: #fff; border-bottom-color: #fff; color: var(--bs-primary); }
    
    /* Estilos para tabla de vouchers */
    .table th{ background-color: #f8f9fa; font-weight: 600; color: var(--bs-primary); border-bottom: 2px solid var(--bs-primary); }
    .table td{ vertical-align: middle; }
    .table tbody tr:hover{ background-color: #f8f9fa; }
    .btn-group-sm .btn{ padding: 0.25rem 0.5rem; font-size: 1rem; }
    
    /* Animaciones */
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .tab-pane{ animation: fadeIn 0.3s ease-in-out; }
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

  <main class="container py-5">
    <div class="text-center mb-4">
      <h2 class="fw-bold" style="color: var(--bs-primary);">Generador de Vouchers WiFi</h2>
      <p class="text-muted">Crea y gestiona códigos de acceso para tus huéspedes</p>
    </div>
    
    <!-- Tabs de navegación -->
    <ul class="nav nav-tabs mb-4" id="mainTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="generate-tab" data-bs-toggle="tab" data-bs-target="#generate" type="button" role="tab">
          ✨ Generar Voucher
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="manage-tab" data-bs-toggle="tab" data-bs-target="#manage" type="button" role="tab" onclick="loadVouchers()">
          🗂️ Gestionar Vouchers
        </button>
      </li>
    </ul>
    
    <div class="tab-content" id="mainTabContent">
      <!-- Tab: Generar Voucher -->
      <div class="tab-pane fade show active" id="generate" role="tabpanel">
        <div class="row g-4">
          <div class="col-12 col-lg-5">
            <div class="card border-0 shadow-sm">
              <div class="card-body p-4">
                <h5 class="card-title mb-4 fw-semibold" style="color: var(--bs-primary);">📋 Configuración del Voucher</h5>
                <form id="voucherForm" class="vstack gap-3" onsubmit="return false;">
                  <div class="mb-3">
                    <label class="form-label fw-semibold">⏱️ Duración</label>
                    <select class="form-select" id="duration">
                      <option value="two_days">📅 2 días</option>
                      <option value="one_week">📆 1 semana</option>
                      <option value="one_month">🗓️ 1 mes</option>
                    </select>
                  </div>
                  <div class="d-grid gap-2">
                    <button type="button" id="generateBtn" class="btn btn-danger btn-lg">
                      <span id="genText">✨ Generar Voucher</span>
                      <span id="genSpinner" class="spinner-border spinner-border-sm ms-2" role="status" aria-hidden="true" hidden></span>
                    </button>
                  </div>
                  <div id="message" class="alert d-none" role="alert">
                    <strong id="messageIcon"></strong>
                    <span id="messageText"></span>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <div class="col-12 col-lg-7">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body p-4">
                <h5 class="card-title mb-3 fw-semibold" style="color: var(--bs-primary);">👁️ Vista Previa del Voucher</h5>
                <div id="pdfViewerContainer" class="border rounded bg-white">
                  <div id="pdfContent" class="position-relative" style="height: calc(100% - 45px); overflow: auto;">
                    <embed id="pdfEmbed" type="application/pdf" style="width:100%;height:100%;display:none;">
                    <iframe id="pdfFrame" src="about:blank" title="voucher" style="width:100%;height:100%;border:none;display:none;"></iframe>
                    <div id="placeholder" class="w-100 h-100 d-flex flex-column justify-content-center align-items-center text-muted">
                      <div style="font-size: 64px; opacity: 0.3; margin-bottom: 16px;">📄</div>
                      <div class="mb-2 fw-semibold">Aún no hay voucher generado</div>
                      <small>Genera un voucher para ver la vista previa aquí</small>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Tab: Gestionar Vouchers -->
      <div class="tab-pane fade" id="manage" role="tabpanel">
        <div class="card border-0 shadow-sm">
          <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
              <h5 class="card-title mb-0 fw-semibold" style="color: var(--bs-primary);">🗂️ Vouchers Emitidos</h5>
              <div class="btn-group btn-group-sm" role="group">
                <button class="btn btn-outline-primary" onclick="loadVouchers()" title="Actualizar lista">
                  🔄 Actualizar
                </button>
                <button class="btn btn-outline-danger" onclick="deleteExpiredVouchers()" title="Eliminar vouchers vencidos">
                  🗑️ Limpiar Vencidos
                </button>
              </div>
            </div>
            
            <div id="vouchersLoading" class="text-center py-5">
              <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Cargando...</span>
              </div>
              <p class="text-muted mt-2">Cargando vouchers...</p>
            </div>
            
            <div id="vouchersContainer" class="d-none">
              <div class="table-responsive">
                <table class="table table-hover">
                  <thead class="table-light">
                    <tr>
                      <th>Código</th>
                      <th>Perfil</th>
                      <th>SSID</th>
                      <th>Fecha Emisión</th>
                      <th class="text-center">Acciones</th>
                    </tr>
                  </thead>
                  <tbody id="vouchersTable">
                    <!-- Se llenará dinámicamente -->
                  </tbody>
                </table>
              </div>
            </div>
            
            <div id="vouchersEmpty" class="text-center py-5 d-none">
              <div style="font-size: 48px; opacity: 0.3;">📭</div>
              <p class="text-muted mt-2">No hay vouchers emitidos</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script>
    const generateBtn = document.getElementById('generateBtn');
    const printBtn = document.getElementById('printBtn');
    const downloadBtn = document.getElementById('downloadBtn');
    const frame = document.getElementById('pdfFrame');
    const pdfEmbed = document.getElementById('pdfEmbed');
    const pdfControls = document.getElementById('pdfControls');
    const placeholder = document.getElementById('placeholder');
    const genSpinner = document.getElementById('genSpinner');
    const genText = document.getElementById('genText');
    const message = document.getElementById('message');
    const debugLog = document.getElementById('debugLog');
    
    let currentPdfUrl = null;
    let currentPdfBlob = null;

    function logEvent(event, data){
      const ts = new Date().toISOString();
      let line = `[${ts}] ${event}`;
      if(data!==undefined){
        try{ line += ' ' + JSON.stringify(data); }catch(e){ line += ' [unstringifiable]'; }
      }
      console.log(line);
      if(debugLog){
        debugLog.textContent += line + '\n';
        debugLog.scrollTop = debugLog.scrollHeight;
      }
    }

    document.querySelectorAll('[data-profile]').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        document.querySelectorAll('[data-profile]').forEach(b=>b.classList.remove('active'));
        btn.classList.add('active');
        generateBtn.dataset.profile = btn.dataset.profile;
        logEvent('Perfil seleccionado', { profile: btn.dataset.profile });
      });
    });

    function setLoadingState(loading){
      if(loading){
        generateBtn.disabled = true;
        if(printBtn) printBtn.disabled = true;
        if(downloadBtn) downloadBtn.disabled = true;
        genSpinner.hidden = false;
        genText.textContent = 'Generando...';
      } else {
        generateBtn.disabled = false;
        genSpinner.hidden = true;
        genText.textContent = '✨ Generar Voucher';
      }
    }

    function showError(text){
      const messageIcon = document.getElementById('messageIcon');
      const messageText = document.getElementById('messageText');
      if(messageIcon) messageIcon.textContent = '⚠️ ';
      if(messageText) messageText.innerHTML = text.replace(/\n/g, '<br>');
      message.classList.remove('d-none');
      message.classList.remove('alert-success');
      message.classList.add('alert-danger');
    }
    
    function showSuccess(text){
      const messageIcon = document.getElementById('messageIcon');
      const messageText = document.getElementById('messageText');
      if(messageIcon) messageIcon.textContent = '✓ ';
      if(messageText) messageText.innerHTML = text.replace(/\n/g, '<br>');
      message.classList.remove('d-none');
      message.classList.remove('alert-danger');
      message.classList.add('alert-success');
    }

    function clearError(){
      const messageText = document.getElementById('messageText');
      const messageIcon = document.getElementById('messageIcon');
      if(messageText) messageText.innerHTML = '';
      if(messageIcon) messageIcon.textContent = '';
      message.classList.add('d-none');
    }

    async function fetchWithTimeout(resource, options = {}){
      const { timeout = 20000 } = options; // 20s
      const controller = new AbortController();
      const id = setTimeout(() => controller.abort(), timeout);
      try{
        const resp = await fetch(resource, { ...options, signal: controller.signal });
        return resp;
      } finally {
        clearTimeout(id);
      }
    }

    generateBtn.addEventListener('click', async ()=>{
      clearError();
      setLoadingState(true);
      logEvent('Inicio de generación clic');
      try{
        const profile = document.getElementById('duration')?.value || 'two_days';
        const code = document.getElementById('code')?.value.trim() || '';
        const ssid = document.getElementById('ssid')?.value.trim() || '';
        const url = new URL('generate.php', window.location.href);
        url.searchParams.set('profile', profile);
        if(code) url.searchParams.set('code', code);
        if(ssid) url.searchParams.set('ssid', ssid);
        logEvent('Construida URL de generación', { url: url.toString(), profile, code: code || '(auto)', ssid });

        const resp = await fetchWithTimeout(url.toString(), { method: 'GET', cache: 'no-store', timeout: 25000 });
        logEvent('Respuesta recibida', { status: resp.status, contentType: resp.headers.get('content-type') || '' });
        
        const ctype = resp.headers.get('content-type') || '';
        
        // Verificar si hay un error JSON
        if(!resp.ok || ctype.includes('application/json')){
          try {
            const errorData = await resp.json();
            const errorMsg = errorData.error || errorData.message || 'Error desconocido del servidor';
            const details = errorData.details ? '\n\n' + errorData.details : '';
            const code = errorData.code ? '\n\nCódigo generado: ' + errorData.code : '';
            throw new Error(errorMsg + details + code);
          } catch(jsonErr) {
            if(jsonErr.message && !jsonErr.message.includes('Unexpected')) {
              throw jsonErr; // Re-lanzar error de parseo de JSON con mensaje claro
            }
            throw new Error('Error del servidor ('+resp.status+').');
          }
        }
        
        const blob = await resp.blob();
        
        // Limpiar URL anterior
        if(currentPdfUrl) {
          URL.revokeObjectURL(currentPdfUrl);
        }
        
        currentPdfBlob = blob;
        currentPdfUrl = URL.createObjectURL(blob);
        
        if(ctype.includes('application/pdf') || ctype.includes('octet-stream')){
          logEvent('PDF recibido, intentando visualización');
          
          // Método 1: Intentar con embed (mejor soporte en navegadores modernos)
          pdfEmbed.style.display = 'block';
          frame.style.display = 'none';
          pdfEmbed.src = currentPdfUrl;
          
          // Fallback: si el embed no funciona después de 2 segundos, usar iframe
          setTimeout(() => {
            if(!pdfEmbed.offsetHeight || pdfEmbed.offsetHeight < 100) {
              logEvent('Embed falló, usando iframe como fallback');
              pdfEmbed.style.display = 'none';
              frame.style.display = 'block';
              frame.src = currentPdfUrl;
            } else {
              logEvent('PDF cargado exitosamente en embed');
            }
          }, 2000);
          
          logEvent('PDF configurado para visualización');
        } else {
          // HTML fallback
          frame.style.display = 'block';
          pdfEmbed.style.display = 'none';
          frame.src = currentPdfUrl;
          logEvent('HTML cargado en iframe');
        }

        placeholder.hidden = true;
        if(pdfControls) pdfControls.hidden = ctype.includes('application/pdf') ? false : true;
        
        await new Promise((resolve)=>{
          setTimeout(resolve, 1000);
        });
        
        logEvent('Vista previa lista');
        if(printBtn) printBtn.disabled = false;
        if(downloadBtn) downloadBtn.disabled = false;
      } catch(err){
        frame.src = 'about:blank';
        pdfEmbed.src = '';
        frame.style.display = 'none';
        pdfEmbed.style.display = 'none';
        placeholder.hidden = false;
        if(pdfControls) pdfControls.hidden = true;
        if(printBtn) printBtn.disabled = true;
        if(downloadBtn) downloadBtn.disabled = true;
        showError('No se pudo generar el voucher. '+ (err?.message || 'Intenta nuevamente.'));
        logEvent('Error en generación', { error: String(err && err.message ? err.message : err) });
      } finally {
        setLoadingState(false);
        logEvent('Finalizó proceso de generación');
      }
    });

    if(printBtn) {
      printBtn.addEventListener('click', ()=>{
        logEvent('Imprimir clic');
        if(frame.style.display !== 'none' && frame.contentWindow) {
          frame.contentWindow.focus();
          frame.contentWindow.print();
        } else if(pdfEmbed.style.display !== 'none') {
          // Para embed, intentar abrir en nueva ventana para imprimir
          window.open(currentPdfUrl, '_blank');
        }
      });
    }
    
    if(downloadBtn) {
      downloadBtn.addEventListener('click', ()=>{
        logEvent('Descargar clic');
        if(currentPdfUrl && currentPdfBlob) {
          const a = document.createElement('a');
          a.href = currentPdfUrl;
          a.download = 'voucher-wifi-' + new Date().getTime() + '.pdf';
          document.body.appendChild(a);
          a.click();
          document.body.removeChild(a);
          logEvent('Descarga iniciada');
        }
      });
    }
    
    // Controles de zoom (básico)
    let zoomLevel = 100;
    document.getElementById('zoomIn')?.addEventListener('click', ()=>{
      zoomLevel = Math.min(200, zoomLevel + 25);
      applyZoom();
    });
    
    document.getElementById('zoomOut')?.addEventListener('click', ()=>{
      zoomLevel = Math.max(50, zoomLevel - 25);
      applyZoom();
    });
    
    document.getElementById('fitWidth')?.addEventListener('click', ()=>{
      zoomLevel = 100;
      applyZoom();
    });
    
    function applyZoom() {
      const zoomLevelEl = document.getElementById('zoomLevel');
      if(zoomLevelEl) zoomLevelEl.textContent = zoomLevel + '%';
      if(pdfEmbed.style.display !== 'none') {
        pdfEmbed.style.transform = `scale(${zoomLevel/100})`;
        pdfEmbed.style.transformOrigin = 'top left';
      }
      if(frame.style.display !== 'none') {
        frame.style.transform = `scale(${zoomLevel/100})`;
        frame.style.transformOrigin = 'top left';
      }
    }
    
    // ======== FUNCIONES DE GESTIÓN DE VOUCHERS ========
    
    const profileLabels = {
      'two_days': '📅 2 días',
      'one_week': '📆 1 semana',
      'one_month': '🗓️ 1 mes'
    };
    
    async function loadVouchers() {
      const vouchersLoading = document.getElementById('vouchersLoading');
      const vouchersContainer = document.getElementById('vouchersContainer');
      const vouchersEmpty = document.getElementById('vouchersEmpty');
      const vouchersTable = document.getElementById('vouchersTable');
      
      vouchersLoading.classList.remove('d-none');
      vouchersContainer.classList.add('d-none');
      vouchersEmpty.classList.add('d-none');
      
      try {
        const resp = await fetch('manage.php?action=list', { cache: 'no-store' });
        const data = await resp.json();
        
        if (!data.success) {
          throw new Error(data.error || 'Error al cargar vouchers');
        }
        
        const vouchers = data.vouchers || [];
        
        if (vouchers.length === 0) {
          vouchersLoading.classList.add('d-none');
          vouchersEmpty.classList.remove('d-none');
          return;
        }
        
        // Construir tabla
        vouchersTable.innerHTML = vouchers.map(v => {
          const date = new Date(v.ts);
          const dateStr = date.toLocaleDateString('es-ES', { 
            day: '2-digit', 
            month: '2-digit', 
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
          });
          
          return `
            <tr>
              <td><strong>${v.code}</strong></td>
              <td>${profileLabels[v.profile] || v.profile}</td>
              <td>${v.ssid}</td>
              <td>${dateStr}</td>
              <td class="text-center">
                <div class="btn-group btn-group-sm" role="group">
                  <button class="btn btn-outline-info" onclick="reprintVoucher('${v.code}')" title="Reimprimir">
                    🖨️
                  </button>
                  <button class="btn btn-outline-warning" onclick="showModifyModal('${v.code}', '${v.profile}')" title="Modificar perfil">
                    ⚙️
                  </button>
                  <button class="btn btn-outline-success" onclick="resetTime('${v.code}')" title="Reiniciar tiempo">
                    🔄
                  </button>
                  <button class="btn btn-outline-danger" onclick="deleteVoucher('${v.code}')" title="Eliminar">
                    🗑️
                  </button>
                </div>
              </td>
            </tr>
          `;
        }).join('');
        
        vouchersLoading.classList.add('d-none');
        vouchersContainer.classList.remove('d-none');
        
      } catch (err) {
        console.error('Error al cargar vouchers:', err);
        vouchersLoading.innerHTML = `
          <div class="alert alert-danger">
            <strong>Error:</strong> ${err.message}
          </div>
        `;
      }
    }
    
    async function reprintVoucher(code) {
      if (!confirm(`¿Reimprimir el voucher ${code}?`)) return;
      
      try {
        window.open(`manage.php?action=reprint&code=${code}`, '_blank');
        logEvent('Reimpresión de voucher', { code });
      } catch (err) {
        alert('Error al reimprimir: ' + err.message);
        console.error(err);
      }
    }
    
    function showModifyModal(code, currentProfile) {
      const newProfile = prompt(
        `Modificar perfil del voucher ${code}\n\n` +
        `Perfil actual: ${profileLabels[currentProfile] || currentProfile}\n\n` +
        `Ingresa el nuevo perfil:\n` +
        `- two_days (2 días)\n` +
        `- one_week (1 semana)\n` +
        `- one_month (1 mes)`,
        currentProfile
      );
      
      if (!newProfile || newProfile === currentProfile) return;
      
      if (!['two_days', 'one_week', 'one_month'].includes(newProfile)) {
        alert('Perfil no válido. Usa: two_days, one_week o one_month');
        return;
      }
      
      modifyProfile(code, newProfile);
    }
    
    async function modifyProfile(code, newProfile) {
      try {
        const resp = await fetch(`manage.php?action=modify_profile&code=${code}&profile=${newProfile}`);
        const data = await resp.json();
        
        if (!data.success) {
          throw new Error(data.error || 'Error al modificar perfil');
        }
        
        alert(`✓ Perfil modificado correctamente a ${profileLabels[newProfile]}`);
        logEvent('Perfil modificado', { code, newProfile });
        loadVouchers(); // Recargar lista
        
      } catch (err) {
        alert('Error al modificar perfil: ' + err.message);
        console.error(err);
      }
    }
    
    async function resetTime(code) {
      if (!confirm(`¿Reiniciar el tiempo del voucher ${code}?\n\nEsto desconectará al usuario y reiniciará su tiempo de uso.`)) return;
      
      try {
        const resp = await fetch(`manage.php?action=reset_time&code=${code}`);
        const data = await resp.json();
        
        if (!data.success) {
          throw new Error(data.error || 'Error al reiniciar tiempo');
        }
        
        alert('✓ Tiempo reiniciado correctamente');
        logEvent('Tiempo reiniciado', { code });
        loadVouchers(); // Recargar lista
        
      } catch (err) {
        alert('Error al reiniciar tiempo: ' + err.message);
        console.error(err);
      }
    }
    
    async function deleteVoucher(code) {
      if (!confirm(`⚠️ ¿ELIMINAR el voucher ${code}?\n\nEsta acción NO se puede deshacer.\nSe eliminará del sistema y del router.`)) return;
      
      // Doble confirmación para acciones destructivas
      if (!confirm(`Confirmación final: ¿Realmente deseas ELIMINAR ${code}?`)) return;
      
      try {
        const resp = await fetch(`manage.php?action=delete&code=${code}`);
        const data = await resp.json();
        
        if (!data.success) {
          throw new Error(data.error || 'Error al eliminar voucher');
        }
        
        alert('✓ Voucher eliminado correctamente');
        logEvent('Voucher eliminado', { code });
        loadVouchers(); // Recargar lista
        
      } catch (err) {
        alert('Error al eliminar voucher: ' + err.message);
        console.error(err);
      }
    }
    
    async function deleteExpiredVouchers() {
      if (!confirm(`🗑️ ¿Eliminar todos los vouchers VENCIDOS?\n\nEsto eliminará automáticamente todos los vouchers cuyo tiempo de validez haya expirado.\n\nVouchers que se eliminarán:\n- 2 días: vencidos hace más de 2 días\n- 1 semana: vencidos hace más de 7 días\n- 1 mes: vencidos hace más de 30 días\n\n¿Continuar?`)) return;
      
      try {
        // Mostrar indicador de carga
        const vouchersLoading = document.getElementById('vouchersLoading');
        const vouchersContainer = document.getElementById('vouchersContainer');
        vouchersContainer.classList.add('d-none');
        vouchersLoading.classList.remove('d-none');
        vouchersLoading.innerHTML = `
          <div class="spinner-border text-danger" role="status">
            <span class="visually-hidden">Eliminando...</span>
          </div>
          <p class="text-muted mt-2">Eliminando vouchers vencidos...</p>
        `;
        
        const resp = await fetch(`manage.php?action=delete_expired`);
        const data = await resp.json();
        
        if (!data.success) {
          throw new Error(data.error || 'Error al eliminar vouchers vencidos');
        }
        
        const deletedCount = data.deleted_count || 0;
        const errorCount = data.error_count || 0;
        const deletedCodes = data.deleted_codes || [];
        
        let message = `✓ Operación completada\n\n`;
        message += `Vouchers eliminados: ${deletedCount}\n`;
        if (errorCount > 0) {
          message += `Errores: ${errorCount}\n`;
        }
        if (deletedCodes.length > 0) {
          message += `\nCódigos eliminados:\n${deletedCodes.join(', ')}`;
        } else {
          message += `\nNo se encontraron vouchers vencidos.`;
        }
        
        alert(message);
        logEvent('Vouchers vencidos eliminados', { deletedCount, errorCount });
        
        // Recargar lista
        loadVouchers();
        
      } catch (err) {
        alert('Error al eliminar vouchers vencidos: ' + err.message);
        console.error(err);
        
        // Restaurar vista en caso de error
        const vouchersLoading = document.getElementById('vouchersLoading');
        const vouchersContainer = document.getElementById('vouchersContainer');
        vouchersLoading.classList.add('d-none');
        vouchersContainer.classList.remove('d-none');
      }
    }
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
