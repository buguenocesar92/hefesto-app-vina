# 03. Multiempresa (multi-tenant) y roles

## Qué es "multiempresa" acá

Cada viña es una empresa distinta con sus propias parcelas, sus lotes y sus
ventas. Hefesto Studio opera el mismo sistema para varias. Que dos viñas
compartan el software **no** significa que compartan datos.

Esto no es una función: es una garantía. Si se rompe, una bodega ve la
temporada de otra, y no hay forma de recuperarlo.

## Las cuatro piezas, y por qué están las cuatro

### 1. `app_tenant_id()`: falla cerrado

```sql
CREATE FUNCTION public.app_tenant_id() RETURNS uuid ...
    IF valor IS NULL OR valor = '' THEN
        RAISE EXCEPTION 'No hay viña en el contexto...'
```

Sin contexto, la lectura **lanza excepción**. No devuelve NULL, no devuelve
todas las filas.

Devolver NULL también cerraría el filtro (porque `tenant_id = NULL` no calza
con nada), pero lo haría con un error de PostgreSQL que no explica nada. El
error tiene que **decir el por qué**.

### 2. `set_config('app.tenant_id', ..., true)`: la marca

El tercer `true` es `is_local`, y es lo que la hace segura. Sin él, la marca
vive en la **sesión de PostgreSQL**, que sobrevive al `COMMIT`. Con una
conexión reutilizable del pool, la petición siguiente hereda la viña anterior
y lee datos ajenos.

Es el error más grave posible en este sistema y el más fácil de introducir por
descuido: se ve bien en las pruebas, que abren su propia conexión, y falla en
producción, donde el pool las reutiliza.

Va como constante (`ContextoTenant::VARIABLE`) y no como texto suelto: lo usan
el servicio, las pruebas y los comandos, y una diferencia de una letra dejaría
el aislamiento abierto sin que nadie lo notara.

### 3. `USING` y `WITH CHECK`

```sql
CREATE POLICY parcels_aislamiento ON parcelas
    FOR ALL
    USING      (tenant_id = public.app_tenant_id())
    WITH CHECK (tenant_id = public.app_tenant_id());
```

`USING` filtra lo que ya está escrito. `WITH CHECK` impide que se escriba de
otra viña.

Con solo `USING`, un `INSERT` de otra viña pasaría sin ser evaluado por la
política de escritura.

### 4. `FORCE ROW LEVEL SECURITY`

Sin esto, el dueño de la tabla (el rol de la aplicación) tiene permiso de
saltársela entera.

## La tabla `tenants` es la excepción, y está compensada

`tenants` no lleva `tenant_id` ni RLS porque se consulta **en el acceso**,
cuando todavía no hay sesión y por lo tanto no hay `app.tenant_id` que poner.

La compensación es la vista `vw_vinas_publicas`:

```sql
CREATE VIEW vw_vinas_publicas AS
    SELECT slug, nombre, activo
    FROM tenants
    WHERE activo = true;
```

Tres columnas, nada más, y solo viñas activas. Ni el `id`, ni el
`razon_social`, ni los sellos de tiempo.

**Se usa de verdad**: `AuthController::vinas()` alimenta el selector de la
pantalla de entrada, y es el único punto del sistema donde se lee una viña sin
identificarse. El `slug` que devuelve viaja en la sesión hasta el callback y es
lo que resuelve a qué viña pertenece la cuenta.

## El rol de la conexión

Con cualquiera de estos dos, PostgreSQL **ignora todas las políticas**:

- ser superusuario,
- tener `BYPASSRLS`.

El `docker-compose.prod.yml` no expone PostgreSQL a ningún puerto justamente
por esto: si el puerto 5432 estuviera abierto a internet, cualquiera que lo
encontrara intentaría conectarse.

`vina:verificar-aislamiento` comprueba las dos cosas antes de dar el visto
bueno. Sale con código distinto de cero si falla, y el despliegue se detiene.

## Los roles

| Rol | Vendimia | Vinifica | Edita campo | Vende | Descarta | Anula | Administra |
|---|---|---|---|---|---|---|---|
| `auditor` | | | | | | | |
| `piso` | | | | Sí | | | |
| `bodega` | Sí | Sí | | Sí | | | |
| `encargado` | Sí | Sí | Sí | Sí | Sí | Sí | |
| `administrador` | Sí | Sí | Sí | Sí | Sí | Sí | Sí |

### La separación que importa

**Descartar un lote** es declarar una pérdida, y tiene consecuencia económica y
de registro: el lote queda marcado como perdido para siempre, y el año que
viene se mira esa columna para saber cuánto se perdió. Por eso es del
`encargado`, no del `bodega`.

**Anular una venta** es una corrección del registro de caja. Si cualquiera
pudiera anular lo que otro vendió, el cierre de caja no diría nada, porque
dependería de quién tiene el turno. Es del `encargado`.

**Editar el campo** es tocar el origen de la trazabilidad. Si el rol `piso`
pudiera cambiar una parcela o una vendimia, podría mover el origen de un vino, y
ahí la trazabilidad deja de significar nada.

**`auditor` ve todo y no cambia nada.** Sirve para que quien audita pueda mirar
sin dejar rastro.

## El menú no es la seguridad

La interfaz oculta botones según el rol. Eso es una **cortesía**, no una
protección.

La autorización real la hace la Policy, en el servidor. Si alguien entra a una
pantalla por URL y no le corresponde, la API responde **404**, y no entrega
nada.

## Por qué 404 y no 403

Un `403` confirma que el recurso **existe** y que es de otra viña. Con un
mensaje de permiso, alguien podría deducir que hay un lote con ese código en
otra bodega.

El `404` no distingue entre "no existe" y "existe pero no es tuyo". La
diferencia entre esas dos cosas no le sirve a nadie, y por eso no se la damos.