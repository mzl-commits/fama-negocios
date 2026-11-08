# FAMA Negocios

Web institucional en Astro y TypeScript. El contenido de partida se recuperó del respaldo de julio de 2026 y se organizó en las tres divisiones de la propuesta aceptada.

## Desarrollo

Requiere Node 22 o posterior.

```sh
npm install
npm run dev
npm run check
npm run build
```

`dist` contiene la versión estática. Las páginas se generan con contenido HTML y el navegador carga `content/site.json` para mostrar las últimas ediciones del panel.

## Publicación en el hosting de FAMA

1. Configurar `PUBLIC_SITE_URL` con el dominio definitivo antes de compilar. Opcionalmente, configurar `PUBLIC_CLARITY_ID` con el proyecto real de FAMA.
2. Ejecutar `npm run build:hosting`. Añade el panel y el endpoint de correo PHP a `dist`.
3. Publicar `dist` en un alojamiento con PHP 8.1 o posterior, Fileinfo, sesiones y HTTPS. En Apache se requiere `mod_headers` para los encabezados opcionales. El código del sitio anterior no se reutiliza.
4. Permitir al usuario de PHP escribir en `content` y `uploads`, con permisos limitados a dicho usuario. El proveedor debe impedir la ejecución de scripts en `uploads`. La configuración Apache se incluye.
5. Configurar `FAMA_ADMIN_PASSWORD_HASH` como variable privada de servidor. Generar el hash con `password_hash` usando la contraseña elegida por el propietario. No guardar contraseñas en el repositorio. El acceso al panel es `/admin/`.
6. Configurar `FAMA_MAIL_TO` y `FAMA_MAIL_FROM` como variables de servidor. La dirección remitente debe pertenecer al dominio autorizado por el proveedor. Comprobar que la función `mail` entrega correctamente con el correo y DNS del alojamiento. Tras probar, compilar con `PUBLIC_EMAIL_FORM=true` para activar el formulario de correo. En la vista previa se utiliza WhatsApp.
7. Validar enlaces, datos comerciales, catálogo, formulario, HTTPS y acceso al panel antes de cambiar el dominio en producción. Realizar un respaldo de los contenidos editados antes de cada nueva publicación.

El panel permite modificar los textos principales, contacto, divisiones y servicios, sustituir sus imágenes y añadir o retirar catálogos PDF de hasta 12 MB. El límite de archivos y `post_max_size` del alojamiento también debe permitir la carga. Tiene sesiones, protección CSRF, bloqueo de intentos de acceso y control de revisiones concurrentes. La estructura de páginas y la selección de la galería se editan desde el proyecto.

Las ediciones del panel son visibles con JavaScript al recargar. Para incorporarlas al HTML inicial y a los metadatos de buscadores, recuperar el JSON actualizado a `public/content/site.json` y recompilar. Al actualizar el despliegue, conservar `content/site.json` y `uploads` del servidor para no sobreescribir las ediciones.

## Pendientes de validación del cliente

- Logo maestro y datos comerciales vigentes. Para la cabecera se utiliza el emblema del logo anterior acompañado del nombre en tipografía.
- Clasificación final de productos y servicios por división, y autorización de imágenes. La galería muestra aplicaciones del archivo, sin atribuirlas a clientes específicos.
- Identidad, razón social y presentación aprobada de Star Home. Su sección está preparada con aviso de información pendiente.
- Asesores comerciales por división. Por ahora las consultas se dirigen al número recuperado del respaldo.
- Cuenta de Microsoft Clarity, correo del formulario y configuración del hosting. El panel PHP y el correo no se ejecutan en la publicación estática de revisión.
