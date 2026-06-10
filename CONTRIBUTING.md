# Contribuir

¡Gracias por querer aportar! Este es un archivo colaborativo: tanto los
documentos (vía [el formulario del sitio](https://pensamientonacionalypopular.com.ar/cargar))
como el código se construyen entre todos.

## Cómo proponer un cambio de código

1. Hacé un **fork** del repositorio y creá una rama descriptiva.
2. Levantá el entorno local (abajo) y hacé tus cambios.
3. Corré la suite: `php tests/integration.php` — tiene que salir todo verde.
4. Abrí un **Pull Request contra `main`** explicando qué cambia y por qué.
5. Un mantenedor lo revisa, lo prueba localmente y lo mergea.

**Importante**: el merge a `main` se deploya **automáticamente a producción**.
Por eso todo cambio pasa por revisión y los PRs deben llegar con los tests
en verde.

## Entorno local

Requisitos: PHP 8+ (sin dependencias externas, sin composer).

```bash
cp .env.example .env       # completar al menos ADMIN_PASSWORD_HASH
php -S localhost:8123 router.php
```

La base SQLite se crea y siembra sola en el primer request.
Los detalles de configuración y emails están en el [README](README.md).

## Convenciones

- **CSS**: metodología [CUBE CSS](https://cube.fyi) con tokens fluidos de
  Utopia. Nada de valores hardcodeados: siempre `var(--token)`.
- **JS**: vanilla, sin frameworks ni build step. Los assets se versionan
  con `?v=N` en el HTML — subí el número si tocás un CSS/JS.
- **PHP**: sin dependencias; el estilo del código existente manda.
- **Commits**: convencionales en inglés (`feat:`, `fix:`, `style:`...).
- **Textos de la interfaz**: en castellano rioplatense, como el resto del sitio.

## Reportar problemas

Abrí un [issue](https://github.com/tbagencia/pensamiento-nacional-popular/issues)
describiendo el problema, los pasos para reproducirlo y qué esperabas que pasara.
