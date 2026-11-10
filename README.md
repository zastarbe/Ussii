# Estantería digital — PHP

Aplicación autoalojable para administrar PDF, asignarles una URL personalizada, leerlos con efecto de pasar página, consultar miniaturas, compartirlos y embeberlos en otras páginas. La biblioteca pública está en `/`; la administración requiere inicio de sesión en `/admin`. Los archivos se guardan en `pdfs/`; publicaciones, carpetas y ajustes se guardan en `data/books.json`.

## Requisitos
- PHP 8.1 o posterior
- Extensiones PHP `fileinfo` y `json`
- Apache con `mod_rewrite` y `.htaccess`, o Nginx con una regla equivalente
- Permisos de escritura de PHP en `pdfs/` y `data/`

## Prueba local
Desde esta carpeta, ejecuta:

```bash
ADMIN_PASSWORD='pon-una-clave-larga' php -S 0.0.0.0:8000 index.php
```

Abre `http://localhost:8000`. Para desplegarlo en un servidor, apunta el document root a esta carpeta y configura `ADMIN_PASSWORD` en el entorno de PHP-FPM/Apache. Si no defines esa variable, la contraseña predeterminada es `change-me`; **cámbiala antes de exponer el sitio**.

## Límites de carga
La app admite PDF de hasta 150 MiB. PHP y el servidor web también deben permitirlo. Por ejemplo, en `php.ini`:

```ini
upload_max_filesize = 155M
post_max_size = 160M
max_execution_time = 300
```

Reinicia PHP-FPM/Apache después de cambiar `php.ini`. Configura también el límite de cuerpo de petición de Nginx/tu proxy si corresponde.

## Cómo funciona
- `data/books.json`: catálogo JSON con arrays `folders` y `books`; contiene títulos, descripciones, slugs, carpetas y ajustes por publicación. La app lo actualiza automáticamente.
- `/admin`: panel protegido por contraseña y sesión privada; permite crear, renombrar y borrar carpetas, asignar publicaciones, ajustar el lector y gestionar enlaces. La raíz `/` es solo la biblioteca pública; el panel no tiene enlace público y no se indexa.
- `pdfs/`: PDFs guardados con nombres aleatorios para evitar colisiones. Se sirven mediante `/files/...` y no como archivos públicos directos.
- `index.php`: router de la portada, las URLs personalizadas y la API JSON de administración.
- `views/`: interfaz y visor. El efecto de pasar página está incluido localmente en `vendor/` (StPageFlip).
- PDF.js y su worker se cargan desde cdnjs; por eso el lector necesita conexión a Internet. Para un despliegue sin Internet, descarga PDF.js y el worker a una carpeta local y cambia sus referencias CDN en `views/reader.html`.

Las URLs se crean a partir del slug, por ejemplo `mi-catalogo` se publica en `/mi-catalogo`. En **Ajustes** de cada publicación se editan su descripción, color de fondo, avance automático e intervalo, página inicial, modo automático/simple/doble, miniaturas, flechas laterales, botón compartir, permiso de inserción, logo CEINPRO, carpeta y pase uniforme o distinto para portada/última página. El color inicial es `#00745b` y puede cambiarse.

El lector incluye miniaturas para saltar a cualquier página y una barra de zoom que va de **Ajustar a ventana** a **400 %**. En el panel, el botón **Embeber** genera un iframe para el flipbook (`/mi-catalogo?embed=1`) o para el PDF nativo del navegador (`/files/...pdf`). El wordmark de CEINPRO se incluye en `assets/ceinpro-logo.png` y se muestra cuando se activa esa opción.

La colección es pública para cualquiera que conozca el enlace; la contraseña protege las acciones de administración, no el acceso de lectura. Usa HTTPS mediante un proxy inverso y guarda copias de seguridad de `data/books.json` y `pdfs/`.
