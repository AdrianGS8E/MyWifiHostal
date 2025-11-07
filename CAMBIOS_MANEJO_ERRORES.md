# Mejoras en el Manejo de Errores - WifiMaster PHP

## Problema Identificado

Al revisar los logs (`logs/app.log`), se detectó que:

1. **Error de conexión API**: No se pudo establecer conexión con el router MikroTik vía API
   - Error: "Unable to establish socket session"
   
2. **Extensión SSH2 no disponible**: PHP no tiene habilitada la extensión ssh2
   - Mensaje: "Extensión ssh2 no disponible en PHP"

3. **Provisión falló**: El voucher NO se creó en User Manager
   - Resultado: "Provisión UM NO EJECUTADA o FALLÓ"

**Problema principal**: A pesar de que la provisión falló, el PDF se generaba correctamente y se mostraba al usuario, dando la falsa impresión de que todo funcionó bien.

## Cambios Realizados

### 1. Modificaciones en `generate.php`

**Líneas 218-239**: Se agregó validación de provisión exitosa

```php
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
```

**Resultado**: Ahora cuando la provisión falla, el script detiene la generación del PDF y devuelve un mensaje de error JSON con detalles específicos del problema.

### 2. Modificaciones en `index.php`

#### a) Detección de errores JSON (líneas 188-202)

```javascript
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
      throw jsonErr;
    }
    throw new Error('Error del servidor ('+resp.status+').');
  }
}
```

#### b) Mejoras en visualización de mensajes

**HTML (líneas 69-72)**: Estructura mejorada para mensajes
```html
<div id="message" class="alert d-none" role="alert">
  <strong id="messageIcon"></strong>
  <span id="messageText"></span>
</div>
```

**JavaScript (líneas 150-176)**: Funciones mejoradas
```javascript
function showError(text){
  const messageIcon = document.getElementById('messageIcon');
  const messageText = document.getElementById('messageText');
  if(messageIcon) messageIcon.textContent = '⚠️ ';
  if(messageText) messageText.innerHTML = text.replace(/\n/g, '<br>');
  message.classList.remove('d-none');
  message.classList.remove('alert-success');
  message.classList.add('alert-danger');
}
```

**CSS (líneas 32-34)**: Estilos para mejor legibilidad
```css
#message{ font-size: 14px; line-height: 1.6; }
#messageIcon{ margin-right: 4px; }
#messageText{ display: inline-block; }
```

## Resultado Final

### Antes:
- ✅ PDF se generaba y mostraba
- ❌ Usuario no sabía que hubo un error
- ❌ Voucher NO estaba en User Manager
- ❌ Solo viendo logs se detectaba el problema

### Ahora:
- ❌ PDF NO se genera si hay error
- ✅ Usuario ve mensaje de error claro y detallado
- ✅ Mensaje indica el problema específico (API, SSH2, configuración)
- ✅ Usuario sabe que debe revisar logs o configuración

## Mensajes de Error Mostrados

Según el problema, el usuario verá uno de estos mensajes:

1. **API no configurada o extensiones faltantes**:
   > ⚠️ No se pudo crear el voucher en User Manager. Verifica la configuración del router o que las extensiones PHP necesarias estén instaladas (RouterOS API o ssh2).
   > 
   > Consulta logs/app.log para más información.

2. **Error de conexión API**:
   > ⚠️ No se pudo crear el voucher en User Manager. No se pudo conectar al router vía API. Verifica host, puerto, credenciales y que el router esté accesible.
   > 
   > Consulta logs/app.log para más información.

3. **SSH2 no disponible**:
   > ⚠️ No se pudo crear el voucher en User Manager. Error al conectar vía SSH. Verifica que la extensión ssh2 esté instalada y habilitada en PHP.
   > 
   > Consulta logs/app.log para más información.

## Pasos Siguientes Recomendados

Para solucionar el problema detectado, debes:

1. **Instalar extensión RouterOS API** (recomendado):
   ```bash
   composer require evilfreelancer/routeros-api-php
   ```

2. **O instalar extensión SSH2** (alternativa):
   - En Windows con XAMPP: descargar DLL de PHP SSH2
   - Copiar a carpeta `ext` de PHP
   - Habilitar en `php.ini`: `extension=ssh2`
   - Reiniciar Apache

3. **Verificar configuración del router** en `config.json`:
   ```json
   "router": {
     "host": "192.168.x.x",
     "api_port": 8728,
     "api_ssl": false,
     "ssh_port": 22,
     "username": "admin",
     "password": "tu_password",
     "customer": "admin"
   }
   ```

4. **Verificar conectividad**:
   - Hacer ping al router
   - Verificar que puertos 8728 (API) o 22 (SSH) estén abiertos
   - Probar credenciales en WinBox o terminal

## Documentación

- `logs/app.log`: Contiene registros detallados de cada intento de provisión
- `config.json`: Configuración del router y perfiles
- `generate.php`: Lógica de generación y provisión
- `index.php`: Interfaz de usuario
