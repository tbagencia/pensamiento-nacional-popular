# Línea de Tiempo del Pensamiento Nacional y Popular Argentino

Sitio mobile-first para navegar por año discursos, textos, cartas y manifiestos
del pensamiento nacional y popular argentino. Cualquier persona puede cargar un
documento sin registrarse: solo necesita un email, que valida haciendo clic en el
botón **VALIDAR** del correo que recibe. Un administrador modera todo el
contenido antes de que se publique.

## Stack

- **Frontend**: HTML + CSS + JavaScript vanilla (mobile-first, sin frameworks).
- **Backend**: PHP 8+ con SQLite (PDO). No requiere configurar base de datos:
  el archivo `data/timeline.sqlite` se crea solo en la primera visita, con
  contenido histórico inicial.
- Pensado para hosting compartido: sin Composer, sin base de datos externa,
  sin build step.

## Estructura

```
├── index.html            # Línea de tiempo navegable por año
├── cargar.html           # Formulario público de carga
├── admin/index.php       # Panel de moderación (login con contraseña)
├── api/
│   ├── config.php        # Configuración (contraseña admin, email, etc.)
│   ├── db.php            # Conexión SQLite + esquema + seed inicial
│   ├── documento.php     # Página por documento (/documento/{id}, SSR)
│   ├── feedback.php      # POST comentarios/reportes → email a moderadores
│   ├── mailer.php        # Cliente SMTP en PHP puro
│   ├── resources.php     # GET documentos aprobados
│   ├── session.php       # Estado de sesión (email ya validado)
│   ├── sitemap.php       # Sitemap XML dinámico
│   ├── submit.php        # POST nueva carga + envío del email VALIDAR
│   └── verify.php        # Validación del email (destino del botón)
├── assets/css|js/        # Estilos y scripts
├── data/                 # Base SQLite (protegida por .htaccess)
├── router.php            # Rewrites para el server de desarrollo
└── tests/integration.php # Suite de integración sin dependencias
```

## Flujo de una carga

1. El usuario completa el formulario en `cargar.html` (sin registro, solo email).
2. El documento se guarda con estado `pending_email` y recibe un correo con el botón **VALIDAR**.
3. Al hacer clic, pasa a `pending_review`.
4. Al validarse, los moderadores configurados en `MODERATOR_EMAILS` (lista
   separada por comas en `.env`) reciben un email con el documento y un enlace
   al panel.
5. El administrador lo aprueba (`approved`), lo rechaza (`rejected`), lo
   **edita** (corregir un título, el año, el extracto) o lo elimina desde el
   panel de moderación — sin recargar la página, con notificaciones en
   pantalla y confirmación en dos pasos para eliminar y rechazar.
   Quien cargó el documento recibe el resultado por email: al aprobarse, el
   enlace a la página publicada; al rechazarse, el motivo si el moderador
   lo indicó (opcional). Los aportes de moderadores no se autonotifican.
   El panel vive en `/admin/` por defecto; si `ADMIN_PATH` está definido en
   `.env`, pasa a responder solo en `/panel/<ADMIN_PATH>` y `/admin/`
   devuelve 404 (el secreto nunca se commitea).
6. Solo los documentos `approved` aparecen en la línea de tiempo.

Atajos al flujo:

- **Sesión ya validada**: si el mismo email ya validó en esa sesión, las
  cargas siguientes saltan el email y van directo a `pending_review`.
- **Moderadores**: si el email pertenece a `MODERATOR_EMAILS`, el aporte se
  publica directo (`approved`) al validar el email — no tiene sentido que
  un moderador se modere a sí mismo. Con la sesión ya validada, publica al
  instante.

## Configuración

Toda la configuración vive en un archivo `.env` en la raíz (nunca se
commitea). Para empezar:

```bash
cp .env.example .env
# editar .env: hash del admin, remitente y credenciales SMTP
```

## URLs amigables

- `/` — línea de tiempo
- `/linea/1945` — línea de tiempo posicionada en ese año (navegar con los
  chips actualiza la URL, así cada año es compartible)
- `/tipo/discurso` — línea de tiempo filtrada por tipo de documento
- `/documento/12` — página propia de cada documento, renderizada en el
  servidor (ver SEO, abajo)
- `/cargar` y `/cargar/1945` — formulario de carga, con el año precargado
- `/validar/<token>` — validación de email (destino del botón VALIDAR)
- `/sitemap.xml` — sitemap dinámico

En producción las resuelve el `.htaccess` (mod_rewrite); en local, `router.php`.
El sitio asume deploy en la raíz del dominio; para una subcarpeta habría que
ajustar las rutas absolutas y las reglas de rewrite.

## SEO

- Cada documento tiene su página indexable en `/documento/{id}`: texto
  completo renderizado en el servidor, canonical, Open Graph y JSON-LD
  (`Article` con `articleBody`), más enlaces al documento anterior y
  siguiente para que los crawlers recorran todo el archivo.
- `sitemap.xml` se genera dinámicamente: home + documentos aprobados, cada
  uno con `lastmod` (fecha de carga).
- El `.htaccess` fuerza el origen canónico con 301: `www` y `http`
  convergen en `https://` + dominio sin `www`.

## Probar en local

```bash
php -S localhost:8123 router.php
```

Abrir <http://localhost:8123>. Para probar desde otro dispositivo de la red
(un celular, por ejemplo), levantar el server en todas las interfaces y entrar
con la IP local de la máquina (`ipconfig getifaddr en0` en macOS):

```bash
php -S 0.0.0.0:8123 router.php
```

Con `DEV_MODE=true` en `.env`, el enlace de
validación se muestra en pantalla tras la carga, así el flujo se puede probar
aunque no haya servidor de correo.

### Emails en desarrollo con Mailtrap

En desarrollo el sitio usa [Mailtrap](https://mailtrap.io) (sandbox): atrapa
todos los correos en una bandeja de prueba sin entregarlos de verdad. Para
activarlo:

1. En Mailtrap: Email Testing → Inboxes → SMTP Settings → copiar credenciales.
2. En `.env`: completar `SMTP_USER` y `SMTP_PASS`, y dejar `MAIL_DRIVER=smtp`.
3. Cada carga envía el email con el botón VALIDAR a la bandeja de Mailtrap.

El cliente SMTP (`api/mailer.php`) es PHP puro, sin Composer.

## Tests

Suite de integración sin dependencias que levanta un servidor de prueba con
una base descartable y recorre el circuito completo por HTTP (carga,
validación de email, salto de validación por sesión, límite diario,
moderación con CSRF, página de documento y sitemap):

```bash
php tests/integration.php
```

Sale con código 0 si todo pasa. Correrla antes de cada deploy.

## Deploy

Requisitos mínimos del servidor:

- PHP 8+ con `pdo_sqlite` (incluido en casi cualquier hosting).
- Apache o LiteSpeed con `mod_rewrite` y soporte de `.htaccess`.
- Una casilla de correo del propio dominio para usar como remitente.
- Deploy en la raíz del dominio (las URLs amigables y las rutas absolutas
  lo asumen).

Pasos:

1. **Subir archivos**: copiar todo el contenido de esta carpeta a la raíz
   pública del servidor (`public_html/` o equivalente) vía FTP o el
   administrador de archivos del panel.
2. **Crear el `.env` de producción** (no subir el de desarrollo):
   - `ADMIN_PASSWORD_HASH`: hash de una contraseña propia, generado con
     `php -r "echo password_hash('TU_CLAVE', PASSWORD_DEFAULT);"`.
   - `MAIL_FROM`: una casilla del propio dominio. Si el remitente no es del
     dominio, los emails caen en spam.
   - `MAIL_DRIVER=mail` y `DEV_MODE=false` — obligatorio en producción: con
     `DEV_MODE=true` el enlace de validación se muestra en pantalla y
     cualquiera puede saltearse la verificación de email.
3. **Permisos**: la carpeta `data/` debe ser escribible por PHP (suele
   bastar con 755; si falla la escritura, probar 775).
4. Listo: la base de datos se crea sola en la primera visita. El `.htaccess`
   de la raíz bloquea el acceso web al `.env`.

## Notas

- El `.htaccess` dentro de `data/` bloquea el acceso web directo a la base
  (lo respetan Apache y LiteSpeed).
- Si el plan no tuviera `pdo_sqlite` (muy raro), el código está aislado en
  `api/db.php` y migrar a MySQL es directo: cambiar el DSN de PDO y el esquema.
- Antispam incluido: campo honeypot en el formulario y límite de 5 cargas por
  email por día.
- Feedback de visitantes: el diálogo «Enviar un comentario» (footer, panel
  Acerca de y formulario de carga) manda el mensaje por email a
  `MODERATOR_EMAILS` vía `api/feedback.php`. No guarda nada en la base;
  honeypot y límite de 5 envíos por sesión.
