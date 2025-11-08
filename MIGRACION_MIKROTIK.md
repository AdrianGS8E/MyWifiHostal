# Migración a Almacenamiento Solo en MikroTik

## Cambios Realizados

### ✅ Sistema Migrado Exitosamente

El sistema ahora funciona **100% con MikroTik User Manager** sin almacenamiento local.

### Archivos Modificados

1. **generate.php**
   - ✅ Eliminadas funciones `loadIssued()` y `saveIssued()`
   - ✅ Códigos de voucher se generan sin verificar archivo local
   - ✅ Vouchers se crean solo en MikroTik

2. **manage.php**
   - ✅ Eliminadas funciones de almacenamiento local
   - ✅ Acción `list`: Obtiene todos los usuarios directamente de MikroTik
   - ✅ Acción `delete`: Elimina solo de MikroTik (y PDF local)
   - ✅ Acción `modify_profile`: Modifica solo en MikroTik
   - ✅ Acción `reset_time`: Consulta perfil desde MikroTik y reinicia
   - ✅ Acción `delete_expired`: Deshabilitada (no disponible sin registro local)
   - ✅ **Logs extensivos agregados** en todas las operaciones

3. **index.php**
   - ✅ Botón "Limpiar Vencidos" ahora muestra mensaje de función no disponible

### Logs Mejorados

Se agregaron logs detallados en `manage.php` para cada operación:

#### LIST (Listar)
```
========== INICIO LISTADO DE VOUCHERS ==========
LIST: Timestamp: ...
LIST: Customer configurado: ...
LIST: Intentando conexión a MikroTik...
LIST: Conexión a MikroTik establecida exitosamente
GET_USERS: Iniciando consulta de usuarios en User Manager
GET_USERS: Usuario procesado: ... (perfil: ..., disabled: ...)
========== FIN LISTADO DE VOUCHERS ==========
```

#### DELETE (Eliminar)
```
========== INICIO ELIMINACIÓN DE VOUCHER ==========
DELETE: Código de voucher: ...
DELETE: Iniciando conexión a MikroTik...
DELETE: Conexión a MikroTik establecida exitosamente
ELIMINAR: Iniciando eliminación de usuario ...
ELIMINAR: Usuario ... eliminado exitosamente de UM
========== FIN ELIMINACIÓN DE VOUCHER ==========
```

#### MODIFY_PROFILE (Modificar Perfil)
```
========== INICIO MODIFICAR PERFIL ==========
MODIFY_PROFILE: Código de voucher: ...
MODIFY_PROFILE: Nuevo perfil: ...
MODIFY_PROFILE: Perfil modificado exitosamente en MikroTik
========== FIN MODIFICAR PERFIL ==========
```

#### RESET_TIME (Reiniciar Tiempo)
```
========== INICIO REINICIAR TIEMPO ==========
RESET_TIME: Código de voucher: ...
RESET_TIME: Buscando usuario en User Manager...
RESET_TIME: Tiempo reiniciado exitosamente en MikroTik
========== FIN REINICIAR TIEMPO ==========
```

### Archivo `issued.json`

⚠️ **IMPORTANTE**: El archivo `issued.json` ya **NO SE USA**.

Puedes:
- **Eliminarlo**: `rm issued.json` (recomendado)
- **Respaldarlo**: Mover a carpeta de backups si necesitas los datos históricos
- **Dejarlo**: No afecta el funcionamiento (será ignorado)

### Datos que Ya No se Almacenan Localmente

- ❌ Códigos de vouchers
- ❌ Fechas de emisión
- ❌ Perfiles asignados
- ❌ SSID utilizado

**Todos estos datos ahora se consultan directamente desde MikroTik User Manager.**

### Limitaciones Conocidas

1. **Fecha de Emisión**: 
   - No se puede obtener desde MikroTik
   - En el listado se muestra fecha actual
   - Solución: MikroTik no almacena timestamp de creación de usuarios

2. **Eliminar Vencidos**:
   - Función deshabilitada sin registro local
   - Debes gestionar expiración desde MikroTik User Manager
   - Puedes configurar perfiles con expiración automática en el router

3. **SSID**: 
   - Se muestra el SSID configurado en `config.json`
   - No se almacena el SSID específico usado al crear el voucher

### Ventajas del Nuevo Sistema

✅ **Sincronización en Tiempo Real**: Los datos siempre están actualizados con el router
✅ **Sin Conflictos**: No hay problemas de sincronización entre local y MikroTik
✅ **Más Confiable**: MikroTik es la única fuente de verdad
✅ **Logs Detallados**: Fácil debugging de problemas de conexión
✅ **Menos Archivos**: No hay que mantener issued.json sincronizado

### Verificación de Logs

Todos los logs se guardan en: `logs/app.log`

Para monitorear en tiempo real:
```bash
# Windows PowerShell
Get-Content logs/app.log -Wait -Tail 50

# Linux/Mac
tail -f logs/app.log
```

### Troubleshooting

Si tienes problemas:
1. Verifica `logs/app.log` para ver errores detallados
2. Confirma que `config.json` tenga las credenciales correctas del router
3. Verifica que el router MikroTik sea accesible
4. Confirma que el User Manager esté habilitado en el router

---

**Fecha de Migración**: 7 de Noviembre, 2025
**Versión**: 2.0 - MikroTik Only
