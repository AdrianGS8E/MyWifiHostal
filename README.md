# WifiMaster - Sistema de Gestión de Vouchers WiFi para MikroTik

Sistema completo de generación y gestión de vouchers WiFi para hoteles/hostales que utilizan routers MikroTik con User Manager (RouterOS 6.x). Desarrollado para **Hostal Bolívar**.

## 📋 Descripción General

El proyecto WifiMaster consta de dos aplicaciones principales con interfaz gráfica (Tkinter) que permiten:

1. **Generar vouchers WiFi** con códigos únicos y configuraciones de tiempo predefinidas
2. **Gestionar vouchers existentes** con visualización, reimpresión y control de usuarios

## 🗂️ Estructura del Proyecto

```
WifiMaster/
├── voucher_gui.py          # Aplicación principal de generación de vouchers
├── lista_voucher_gui.py    # Aplicación de gestión y listado de vouchers
├── config.json             # Configuración centralizada del sistema
├── issued.json             # Registro local de vouchers emitidos
├── tickets/                # Carpeta donde se guardan los PDFs generados
└── logo-full-print.png     # Logo del hostal para los tickets
```

## 🎯 Componentes Principales

### 1. voucher_gui.py - Generador de Vouchers

**Propósito**: Interfaz gráfica para crear y emitir vouchers WiFi de forma rápida y sencilla.

#### Funcionalidades Principales:

- **Generación de Códigos Únicos**
  - Genera códigos alfanuméricos de 6 caracteres (configurable)
  - Utiliza charset sin caracteres confusos: `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`
  - Evita colisiones verificando contra MikroTik y registro local

- **Tres Duraciones Predefinidas**
  - **2 días**: Voucher de corta duración
  - **1 semana**: Voucher de duración media
  - **1 mes**: Voucher de larga duración

- **Vista Previa Integrada**
  - Muestra cómo se verá el ticket antes de imprimir
  - Incluye logo, código, duración e instrucciones
  - Diseño profesional y claro para huéspedes

- **Impresión Automática**
  - Genera PDF en formato 80mm (tickets térmicos)
  - Imprime automáticamente en impresora por defecto
  - Guarda copia del PDF en carpeta `tickets/`

- **Integración con MikroTik User Manager**
  - Crea usuarios automáticamente en el router
  - Asigna perfiles según duración seleccionada
  - Configura limitaciones de tiempo (uptime)
  - Soporta API y fallback SSH

#### Flujo de Trabajo:

```
1. Usuario hace clic en botón (ej: "Voucher 2 días")
   ↓
2. Sistema genera código único (ej: "AB3K9Q")
   ↓
3. Verifica que no exista colisión en:
   - MikroTik User Manager
   - Registro local (issued.json)
   ↓
4. Crea usuario en MikroTik:
   - Username: AB3K9Q
   - Password: AB3K9Q (mismo código)
   - Profile: 2-dias
   ↓
5. Genera PDF del voucher (80mm):
   - Logo del hostal
   - Código grande y legible
   - Duración del voucher
   - Instrucciones de conexión paso a paso
   ↓
6. Guarda en issued.json con timestamp
   ↓
7. Imprime automáticamente el ticket
   ↓
8. Actualiza vista previa en la GUI
   ↓
9. Permite reimprimir último voucher generado
```

#### Clases y Componentes:

**`MikroTikClient`**: Cliente para interactuar con el router MikroTik
- `connect_api()`: Conecta vía API (puerto 8728)
- `um_list_usernames()`: Lista usuarios existentes
- `um_ensure_limitation()`: Crea/verifica limitaciones de tiempo
- `um_ensure_profile()`: Crea/verifica perfiles de usuario
- `um_create_user_and_assign()`: Crea usuario y asigna perfil
- `_ssh_exec()`: Fallback SSH cuando API falla

**`VoucherApp`**: Interfaz gráfica principal
- `__init__()`: Inicializa ventana, carga configuración
- `draw_preview()`: Dibuja vista previa del voucher
- `handle_create()`: Maneja clic en botones de duración
- `print_last()`: Reimprime último voucher generado

**Funciones Auxiliares**:
- `generate_code()`: Genera código aleatorio
- `build_voucher_pdf()`: Crea PDF con ReportLab
- `print_pdf()`: Envía PDF a impresora
- `load_issued()` / `append_issued()`: Gestiona registro local

---

### 2. lista_voucher_gui.py - Gestor de Vouchers

**Propósito**: Interfaz para visualizar, administrar y controlar todos los vouchers emitidos.

#### Funcionalidades Principales:

- **Listado Completo de Vouchers**
  - Muestra todos los usuarios del User Manager
  - Columnas: Username, Profile, Uptime Usado, Límite, Estado Online
  - Formato legible de tiempos (ej: "1d 5h 30m")

- **Limpieza Automática**
  - Al iniciar, detecta y elimina vouchers consumidos/caducados
  - Criterio: `uptime_usado >= límite_de_tiempo`
  - Notifica cantidad de vouchers limpiados

- **Acciones por Voucher**:
  
  **a) Reimprimir**: Genera nuevo PDF del voucher seleccionado
  - Útil si el huésped pierde su ticket
  - Usa mismas plantillas y formato
  
  **b) Expulsar**: Desconecta usuario activo
  - Cierra sesión actual en User Manager
  - Limpia sesión activa en Hotspot (si existe)
  - Útil para liberar conexiones
  
  **c) Eliminar**: Borra completamente el usuario
  - Elimina de User Manager
  - Acción irreversible (solicita confirmación)
  - Libera el código para posible reutilización

- **Refrescar**: Actualiza lista con estado actual del router

#### Flujo de Trabajo:

```
1. Al iniciar la aplicación:
   ↓
2. Conecta con MikroTik User Manager (API/SSH)
   ↓
3. Lista todos los usuarios y sus datos:
   - Nombres de usuario (códigos)
   - Perfiles asignados
   - Tiempo de uso consumido (via print stats)
   - Límites de tiempo (via limitations)
   - Estado online (via sessions)
   ↓
4. Identifica vouchers consumidos:
   - Compara uptime_usado vs límite
   - Elimina automáticamente si están caducados
   ↓
5. Muestra tabla con información:
   USERNAME | PROFILE  | UPTIME_USED | LIMIT    | ONLINE
   AB3K9Q   | 2-dias   | 1d 12h 5m   | 2d 0h 0m | No
   XY7P4M   | 1-semana | 5h 22m      | 7d 0h 0m | Sí
   ↓
6. Usuario puede:
   - Seleccionar voucher de la tabla
   - Reimprimir → genera PDF e imprime
   - Expulsar → desconecta sesión activa
   - Eliminar → borra usuario del sistema
   - Refrescar → actualiza datos desde router
```

#### Clases y Componentes:

**`MikroTikUM`**: Cliente especializado para User Manager
- `connect_api()`: Conexión al router
- `list_users()`: Obtiene lista completa con estadísticas
  - Consulta `/tool/user-manager/user` (usuarios)
  - Consulta `/tool/user-manager/user-profile` (perfiles asignados)
  - Consulta `/tool/user-manager/limitation` (límites de tiempo)
  - Consulta `/tool/user-manager/session` (sesiones activas)
  - Ejecuta `print stats` via SSH (tiempo usado)
- `delete_user()`: Elimina usuario completamente
- `disconnect_user()`: Cierra sesión activa
- `_ssh_exec()`: Ejecuta comandos via SSH (fallback)

**`ListApp`**: Interfaz gráfica de gestión
- `__init__()`: Inicializa, conecta y limpia vouchers caducados
- `_build_ui()`: Construye interfaz con toolbar y tabla
- `refresh_table()`: Actualiza datos desde router
- `reprint_selected()`: Reimprime voucher seleccionado
- `kick_selected()`: Expulsa usuario seleccionado
- `delete_selected()`: Elimina usuario (con confirmación)

**Funciones Auxiliares**:
- `parse_time_to_seconds()`: Convierte "2d", "7d", "30d" a segundos
- `format_secs()`: Formatea segundos a formato legible
- `should_delete_row()`: Determina si voucher está caducado
- `cleanup_consumed()`: Limpia vouchers consumidos automáticamente

---

## ⚙️ Configuración - config.json

El archivo `config.json` centraliza toda la configuración del sistema:

### Sección `router`:
```json
{
  "host": "192.168.1.100",        // IP del router MikroTik
  "username": "admin",            // Usuario administrador
  "password": "admin",            // Contraseña
  "api_port": 8728,              // Puerto API (sin SSL: 8728, con SSL: 8729)
  "api_ssl": false,              // Usar SSL en API
  "ssh_port": 22,                // Puerto SSH (fallback)
  "backend": "user-manager",     // Backend: "user-manager" o "hotspot"
  "customer": "admin",           // Customer en User Manager
  "um_autoprovision": false      // Auto-crear perfiles (false = usar existentes)
}
```

### Sección `printing`:
```json
{
  "use_default_printer": true,   // Usar impresora por defecto
  "printer_name": "",            // Nombre específico (si use_default_printer=false)
  "paper_width_mm": 80,          // Ancho del papel (80mm estándar térmico)
  "font_name": "Courier New",    // Fuente monoespaciada
  "logo_path": "C:/xampp/htdocs/WifiMaster/logo-full-print.png",
  "output_dir": "./tickets"      // Carpeta para PDFs
}
```

### Sección `branding`:
```json
{
  "hostel_name": "HOSTAL BOLÍVAR",  // Nombre que aparece en tickets
  "ssid": "HOSTAL BOLIVAR",         // Nombre de red WiFi
  "portal_url": "hostalbolivar.net" // URL del portal cautivo
}
```

### Sección `vouchers`:
```json
{
  "length": 6,                   // Longitud del código
  "charset": "ABCDEFGHJKLMNPQRSTUVWXYZ23456789",  // Caracteres permitidos
  "prevent_collisions": true,    // Verificar códigos existentes
  "local_registry_path": "./issued.json",         // Registro local
  "same_user_and_pass": true     // Usuario y contraseña iguales
}
```

### Sección `profiles`:
```json
{
  "two_days":  { "um_profile": "2-dias"   },
  "one_week":  { "um_profile": "1-semana" },
  "one_month": { "um_profile": "1-mes"    }
}
```

**IMPORTANTE**: Los perfiles (`2-dias`, `1-semana`, `1-mes`) deben existir previamente en el User Manager del MikroTik si `um_autoprovision` está en `false`.

---

## 📦 Requisitos del Sistema

### Dependencias Python:

```bash
# GUI
tkinter              # Interfaz gráfica (incluido en Python estándar)
Pillow              # Manejo de imágenes (PIL)

# MikroTik
routeros-api        # API de RouterOS
paramiko            # SSH (fallback)

# Generación PDF
reportlab           # Creación de PDFs

# Windows (solo en Windows)
pywin32             # Impresión en Windows
```

### Instalación de Dependencias:

```bash
pip install Pillow routeros-api paramiko reportlab pywin32
```

### Requisitos del Router MikroTik:

- RouterOS 6.x con **User Manager** configurado
- API habilitada (puerto 8728)
- SSH habilitado (puerto 22)
- Perfiles creados en User Manager:
  - `2-dias` con limitation de 48h uptime
  - `1-semana` con limitation de 168h uptime (7 días)
  - `1-mes` con limitation de 720h uptime (30 días)

---

## 🚀 Uso del Sistema

### 1. Generar Vouchers (voucher_gui.py)

```bash
python voucher_gui.py
```

**Pasos**:
1. Hacer clic en el botón de duración deseada (2 días, 1 semana, 1 mes)
2. El sistema genera el código automáticamente
3. Crea el usuario en MikroTik
4. Genera e imprime el PDF
5. Muestra vista previa en pantalla
6. Para reimprimir el último, usar botón "Imprimir último voucher"

### 2. Gestionar Vouchers (lista_voucher_gui.py)

```bash
python lista_voucher_gui.py
```

**Pasos**:
1. Al abrir, se limpian automáticamente vouchers caducados
2. Se muestra tabla con todos los vouchers activos
3. Seleccionar un voucher de la tabla
4. Usar botones de acción:
   - **Refrescar**: Actualizar datos
   - **Reimprimir**: Generar nuevo ticket del voucher
   - **Expulsar**: Desconectar usuario si está online
   - **Eliminar**: Borrar usuario del sistema (irreversible)

---

## 📊 Registro Local - issued.json

Archivo JSON que mantiene historial de vouchers emitidos para prevenir colisiones:

```json
{
  "issued": [
    {
      "code": "AB3K9Q",
      "ts": "2025-01-15T10:30:45.123456"
    },
    {
      "code": "XY7P4M",
      "ts": "2025-01-15T11:15:22.654321"
    }
  ]
}
```

Este archivo se consulta antes de generar nuevos códigos para evitar duplicados.

---

## 🎨 Diseño de Tickets (80mm)

Los tickets generados incluyen:

```
┌─────────────────────────────────┐
│   [LOGO DEL HOSTAL]            │
│                                 │
│     HOSTAL BOLÍVAR             │
│  ─────────────────────────     │
│                                 │
│        AB3K9Q                  │
│    Duración: 2 días            │
│                                 │
│  ┌─────────────────────┐       │
│  │   PIN: AB3K9Q       │       │
│  └─────────────────────┘       │
│                                 │
│  CÓMO CONECTARSE:              │
│  1) Busca la red WiFi:         │
│     HOSTAL BOLIVAR             │
│  2) Conéctate a esta red       │
│  3) Abre tu navegador web      │
│  4) e ingresa a:               │
│     hostalbolivar.net          │
│  5) Ingresa el PIN de arriba   │
│  6) ¡Listo! Ya puedes navegar  │
│                                 │
│  ─────────────────────────     │
│  Gracias por elegirnos •       │
│  Hostal Bolívar                │
└─────────────────────────────────┘
```

---

## 🔧 Solución de Problemas

### Error: "No se encontró config.json"
- Asegúrate de que `config.json` existe en la misma carpeta
- Verifica que el archivo esté bien formateado (JSON válido)

### Error: "No se pudo conectar al MikroTik"
- Verifica IP, usuario y contraseña en `config.json`
- Confirma que API está habilitada en el router
- Prueba conexión SSH como alternativa

### Error: "El profile 'X' no existe"
- Crea los perfiles manualmente en User Manager
- O cambia `um_autoprovision` a `true` en config.json

### Vouchers no se imprimen
- Verifica que la impresora está conectada y es la predeterminada
- En Windows, comprueba que Adobe Reader está instalado
- Revisa los PDFs generados en carpeta `tickets/`

### Tabla vacía en lista_voucher_gui.py
- Verifica que hay usuarios creados en User Manager
- Confirma que `backend` en config.json es "user-manager"
- Revisa logs de conexión con el router

---

## 📝 Notas Técnicas

### Prevención de Colisiones:
El sistema usa doble verificación:
1. Consulta usuarios existentes en MikroTik
2. Consulta registro local `issued.json`
3. Hasta 10 intentos de regenerar código si hay colisión

### Charset Optimizado:
Excluye caracteres confusos para evitar errores de transcripción:
- Sin: I, O, 0, 1, L (confundibles)
- Solo: A-Z (sin I, O, L), 2-9 (sin 0, 1)

### Fallback SSH:
Cuando API falla o no soporta ciertas operaciones:
- User Manager `print stats` (tiempo usado)
- Activación de perfiles (`create-and-activate-profile`)
- Lectura de limitations y sessions

### Limpieza Automática:
`lista_voucher_gui.py` elimina vouchers cuando:
```
uptime_usado >= límite_de_tiempo
```
Ejemplo: Usuario con límite 48h que ya usó 48h o más.

---

## 👨‍💻 Autor

Desarrollado por **ChatGPT** para **Aura - Hostal Bolívar**

---

## 📄 Licencia

Uso interno para Hostal Bolívar. Modificar según necesidades del negocio.
