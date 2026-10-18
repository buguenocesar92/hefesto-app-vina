# 07. Seguridad

## La idea

La seguridad de este sistema tiene tres capas, y cada una cubre algo que las
otras no cubren:

1. **La red**: qué está expuesto y qué no.
2. **La identidad**: quién entra.
3. **La base**: qué filas ve una sesión, aunque la aplicación falle.

La tercera es la que importa aquí, y por eso se explica al final.

## Los puertos: solo Caddy

En `docker-compose.prod.yml`, el **único** servicio con `ports:` es Caddy.

PostgreSQL, Redis, la API y la interfaz no exponen ningún puerto. Quedan
alcanzables solo desde los otros contenedores de la red interna.

La razón: un puerto publicado es un puerto abierto a internet si el servidor
está expuesto. Con la base de datos a la vista, cualquiera que encuentre el
puerto puede intentar conectarse. Y aunque el rol de la aplicación no sea
superusuario, exponer la base abre la puerta a herramientas que sí lo son.

### El superusuario y BYPASSRLS

Con cualquiera de los dos, PostgreSQL **ignora todas las políticas** de Row
Level Security. El aislamiento entre viñas no sería "débil": no existiría.

`vina:verificar-aislamiento` comprueba las dos cosas antes de dar el visto
bueno, y sale con código distinto de cero si falla.

## La identidad: Authentik

### La viña no guarda contraseñas

La contraseña y el segundo factor los resuelve Authentik. En la base hay un
correo con el que se cruza y un rol con el que se autoriza.

Si esta aplicación guardara contraseñas, habría que protegerlas, rotarlas,
restablecerlas y auditar los intentos. Nada de eso es problema de una viña.

### El flujo

```
1. La pantalla de entrada pide la viña
2. Se genera un `state` aleatorio y se guarda en la sesión
3. Se manda a Authentik con el `slug` como `login_hint`
4. Authentik autentica y vuelve con un código
5. Se compara el `state` con el guardado
6. Se canjea el código por tokens
7. Se verifica la firma del token de identidad contra el `jwks`
8. Se cruza el correo con la viña elegida
```

### Qué pasa en cada paso, y por qué importa

**El `state`** se compara con `hash_equals` contra lo guardado en la sesión. Sin
eso, alguien podría enganchar el navegador de otra persona a una sesión ajena.

**El issuer se compara a mano** después de verificar la firma. `JWT::decode`
valida `exp`, pero no el emisor: un token válido de **otra** aplicación de
Authentik pasaría.

**Las claves se verifican contra el `jwks`** del proveedor. Sin esa
verificación, cualquiera podría inventarse un token con el correo del
administrador.

**El correo decide el acceso, no la viña.** La viña sale del `slug` que la
persona eligió antes de entrar. Con dominio compartido (`@gmail.com`) el
correo no sirve, y además sería adivinar.

**Si el correo tiene cuentas en varias viñas**, se pide elegir. Nadie entra
"a la primera que se parezca".

## La cookie de sesión

| | Desarrollo | Producción |
|---|---|---|
| Cookie | `vina_sesion` | `vina_sesion` |
| `Secure` | no | **sí** |
| Mismo sitio | `lax` | `lax` |
| Almacenamiento | base de datos | base de datos |

**En producción la cookie es `Secure`**: sin eso, viaja en claro por cualquier
red WiFi de una bodega, que es un lugar donde el portátil queda abierto.

El almacenamiento en base de datos, y no en archivo, permite **revocar una
sesión** desde la base si alguien pierde el equipo.

La sesión se **regenera al entrar** (`regenerate()`). Sin eso, un identificador
de sesión robado antes del acceso sirve después.

## Por qué la API no está abierta al navegador

La interfaz (Nuxt) hace de **servidor intermedio**: el navegador pide a Nuxt y
Nuxt reenvía a la API.

Eso significa que:

- la cookie de sesión viaja en el mismo origen,
- no hay que configurar CORS para el navegador,
- la URL interna de la API nunca queda expuesta.

El CSRF se resuelve con el mismo mecanismo: el token viaja como cabecera
`X-XSRF-TOKEN`, y hay dos rutas exentas, que son las dos que se usan **antes**
de tener sesión:

- `/api/v1/salud`
- `/api/v1/vinas`

## El aislamiento en la base

Esta es la capa que no depende de que la aplicación se porte bien.

### Falla cerrado

`app_tenant_id()` **lanza excepción** si no hay viña en el contexto. No
devuelve NULL ni devuelve todo.

### La marca es de la transacción

```php
DB::statement('SELECT set_config(?, ?, true)', ['app.tenant_id', $vinaId]);
```

El tercer `true` (`is_local`) acota la marca a la transacción. Sin él, la marca
vive en la sesión de PostgreSQL y sobrevive al `COMMIT`: en una conexión
reutilizable, la petición siguiente hereda la viña anterior.

### Lectura y escritura

`USING` filtra lo que ya está escrito. `WITH CHECK` impide escribir de otra
viña. Con solo `USING`, un `INSERT` de otra viña pasaría.

### Forzada

`FORCE ROW LEVEL SECURITY`, para que el dueño de la tabla no se la salte.

Todo esto está en [03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md), y
en `deploy/respaldos/` está el detalle de por qué la restauración necesita
`--clean`.

## El 404 en vez del 403

Cuando la API rechaza algo por autorización, responde **404**, no 403.

Un 403 confirma que el recurso existe y que pertenece a otra viña. Con un
mensaje de permiso, alguien podría deducir que hay un lote con ese código en
otra bodega.

## Los secretos

- Ninguno está en el repositorio. Solo hay plantillas (`.env.example`).
- `deploy/.env`, `api/.env` y `web/.env` están en `.gitignore`.
- La clave de la aplicación se genera con `key:generate --show` y se pega en
  el `.env` del despliegue.
- `deploy/respaldos/*` está ignorado: un respaldo puede contener datos de
  clientes.

Hay una comprobación automática en `tools/validar_apps.py` que barre el árbol
buscando secretos reales.

## Lo que falta antes de producción

Honestidad sobre los pendientes:

- **Certificado TLS.** El `Caddyfile` viene con `auto_https off` y un bloque de
  ejemplo comentado para un dominio real. Hay que descomentarlo y poner el
  dominio de la viña.
- **Fijar las imágenes por digest SHA256.** Ahora están fijadas por tag
  exacto, que es mejor que "latest" pero no es inmutable.
- **Rotación de secretos.** No hay procedimiento automatizado.
- **Alertas.** No hay monitoreo. Si la base se cae, nadie se entera hasta que
  alguien intenta vender.
- **Copia del respaldo fuera del servidor.** Ver
  [08-RESPALDOS.md](08-RESPALDOS.md).