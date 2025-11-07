# Sistema de Gestión de Vouchers WiFi

## Funcionalidades Implementadas

Se ha añadido un sistema completo de gestión de vouchers con las siguientes funcionalidades:

### 1. **Interfaz con Tabs**
- **Tab "Generar Voucher"**: Interfaz original para crear nuevos vouchers
- **Tab "Gestionar Vouchers"**: Nueva sección para administrar vouchers existentes

### 2. **Listar Vouchers**
- Muestra todos los vouchers emitidos en una tabla ordenada (más recientes primero)
- Información mostrada:
  - Código del voucher
  - Perfil (2 días, 1 semana, 1 mes)
  - SSID
  - Fecha y hora de emisión
  - Botones de acción

### 3. **Reimprimir Voucher** 🖨️
- Permite volver a imprimir un voucher previamente generado
- Abre el PDF en una nueva pestaña para impresión
- Útil cuando se pierde el voucher físico o se necesita una copia

### 4. **Modificar Perfil** ⚙️
- Cambia el perfil de duración de un voucher existente
- Opciones disponibles:
  - `two_days` (2 días)
  - `one_week` (1 semana)
  - `one_month` (1 mes)
- Actualiza el perfil en el MikroTik User Manager
- Actualiza el registro local en `issued.json`

### 5. **Reiniciar Tiempo** 🔄
- Reinicia el tiempo de uso de un voucher
- Desconecta al usuario actualmente conectado
- Reactiva el perfil con tiempo completo desde cero
- Útil para extender el servicio a un huésped

### 6. **Eliminar Voucher** 🗑️
- Elimina completamente un voucher del sistema
- Requiere doble confirmación por seguridad
- Elimina:
  - Usuario del MikroTik User Manager
  - Registro en `issued.json`
  - Archivo PDF del voucher (si existe)
- **ACCIÓN IRREVERSIBLE**

## Archivos Modificados/Creados

### 1. `manage.php` (NUEVO)
Backend que maneja todas las operaciones de gestión:
- `action=list`: Lista todos los vouchers
- `action=reprint`: Reimprime un voucher (devuelve PDF)
- `action=modify_profile`: Modifica el perfil de un voucher
- `action=reset_time`: Reinicia el tiempo de un voucher
- `action=delete`: Elimina un voucher

Integración con MikroTik:
- Usa RouterOS API para operaciones en User Manager
- Manejo de sesiones activas
- Logging de todas las operaciones

### 2. `index.php` (MODIFICADO)
- Añadida interfaz con tabs Bootstrap
- Nueva sección de gestión de vouchers
- Tabla responsive con todos los vouchers
- Botones de acción para cada voucher
- Funciones JavaScript para:
  - Carga asíncrona de vouchers
  - Operaciones AJAX (modificar, eliminar, reiniciar)
  - Confirmaciones y validaciones
  - Manejo de errores

## Uso del Sistema

### Acceso a la Gestión
1. Abre la aplicación en tu navegador (ej: `http://localhost/MyWifiHostal/`)
2. Haz clic en la pestaña **"🗂️ Gestionar Vouchers"**
3. La tabla se cargará automáticamente con todos los vouchers

### Operaciones Disponibles

#### Reimprimir
1. Haz clic en el botón **🖨️**
2. Confirma la acción
3. Se abrirá el PDF en una nueva pestaña

#### Modificar Perfil
1. Haz clic en el botón **⚙️**
2. Aparecerá un prompt con las opciones
3. Ingresa el nuevo perfil: `two_days`, `one_week` o `one_month`
4. Confirma y el perfil se actualizará

#### Reiniciar Tiempo
1. Haz clic en el botón **🔄**
2. Confirma que deseas reiniciar el tiempo
3. El voucher se reiniciará con tiempo completo

#### Eliminar
1. Haz clic en el botón **🗑️**
2. Confirma la eliminación (2 veces por seguridad)
3. El voucher se eliminará completamente

## Consideraciones de Seguridad

- **Doble confirmación** para eliminaciones
- **Validación** de perfiles antes de modificar
- **Logging** de todas las operaciones en `logs/app.log`
- **Manejo de errores** con mensajes descriptivos
- **Rollback automático** si falla la operación en el router

## Requisitos Técnicos

### Backend
- PHP 7.4+
- RouterOS API library (instalada vía Composer)
- Extensión `ssh2` de PHP (opcional, como fallback)
- Acceso al router MikroTik configurado

### Frontend
- Navegador moderno con soporte para:
  - ES6+ JavaScript
  - Fetch API
  - Bootstrap 5.3+

## Troubleshooting

### Los vouchers no se cargan
- Verifica que `issued.json` existe y tiene permisos de lectura
- Revisa `logs/app.log` para errores

### Error al modificar/eliminar en router
- Verifica la configuración del router en `config.json`
- Asegúrate que las credenciales sean correctas
- Revisa que el router sea accesible desde el servidor

### PDF no disponible para reimpresión
- El voucher puede haber sido generado antes de implementar esta funcionalidad
- Genera el voucher nuevamente desde la pestaña "Generar Voucher"

## Próximas Mejoras Sugeridas

1. **Filtros y búsqueda** en la tabla de vouchers
2. **Paginación** para listas largas
3. **Estadísticas** de uso de vouchers
4. **Exportar** lista de vouchers a CSV/Excel
5. **Ver sesiones activas** de cada voucher
6. **Notificaciones** por email al crear/modificar vouchers
7. **Bulk operations** (modificar/eliminar múltiples vouchers)

## Logs y Auditoría

Todas las operaciones se registran en `logs/app.log` con:
- Timestamp
- Acción realizada
- Código del voucher
- Resultado (éxito/error)
- Detalles adicionales
