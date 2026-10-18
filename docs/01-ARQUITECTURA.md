# 01. Arquitectura

## Las capas

```
navegador
    |  (nunca habla con la API)
    v
Nuxt (web)              <- servidor intermedio
    |
    v
API Laravel (api)
    |
    v
PostgreSQL (con Row Level Security)
```

Tres capas, y la del medio existe por una razón concreta.

## El navegador no habla nunca con la API

El navegador pide a Nuxt y Nuxt reenvía a la API
(`web/server/api/[...ruta].ts`). Es un **servidor intermedio**, y sirve para
tres cosas:

1. **La cookie de sesión viaja en el mismo origen.** Sin esto habría que abrir
   la API al exterior y configurar CORS para el navegador.
2. **El token CSRF se reenvía** como cabecera `X-XSRF-TOKEN`.
3. **La URL interna de la API no queda expuesta.** El navegador nunca la ve.

Por eso en `docker-compose.prod.yml` el único servicio con `ports:` es Caddy.
Nuxt habla con la API por la red interna.

## El flujo de una petición con datos

```
1. El navegador pide GET /api/v1/trazabilidad?codigo=LT-LIT-2024-001
2. Caddy reenvía a web:3000
3. Nuxt reenvía a api:9000, con la cookie de sesión
4. ResolverTenant resuelve la persona de la sesión y su viña
5. ContextoTenant abre una transacción y hace:
       set_config('app.tenant_id', '<vina>', true)
6. El controlador lee. PostgreSQL filtra con las políticas de RLS.
7. La transacción termina y la marca desaparece.
```

El paso 5 es el que importa, y por qué lleva `true` está en
[03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md).

## La API

Laravel 13 sobre PHP 8.4. Estructura:

```
api/
├── app/
│   ├── Console/Commands/VinaVerificarAislamiento.php
│   ├── Enums/          <- el proceso, los estados, los roles
│   ├── Exceptions/     <- los errores de negocio, con código estable
│   ├── Http/
│   │   ├── Controllers/
│   │   ├── Middleware/
│   │   └── Requests/
│   ├── Models/
│   ├── Policies/
│   └── Services/
│       ├── Auth/       <- Authentik y el cruce de identidad
│       ├── Tenancy/    <- el contexto de viña
│       ├── Traza/      <- la trazabilidad
│       ├── Vinificacion/ <- el orden del proceso
│       └── Ventas/
├── config/
├── database/
│   ├── migrations/     <- las 6 migraciones
│   ├── factories/
│   └── seeders/
└── routes/
    ├── web.php
    └── console.php
```

### Dónde vive cada cosa, y por qué

| Concern | Dónde | Por qué |
|---|---|---|
| El orden del proceso | `Enums/EtapaVinificacion` | Que nadie lo cambie desde un `UPDATE` |
| Los estados | `Enums/Estado*` | Para que los `if` comparen constantes, no textos |
| Las reglas del negocio | `Services/` | Se prueban sin tocar la HTTP |
| La garantía dura | `database/migrations` | Un `UPDATE` a mano no la puede saltar |
| Quién puede qué | `Policies` | El menú y el servidor usan la misma regla |

## El flujo de una escritura que importa

Tomemos "mandar la vendimia al lagar", que es el momento en que el vino empieza
a existir:

```
1. POST /api/v1/vendimias/{id}/bodega
2. La Policy revisa: el rol tiene que poder vinificar
3. RegistradorDeEtapa::abrirLoteDesde()
4.   ¿La vendimia ya tiene lote? -> error vendimia_ya_vinificada
5.   ¿Está descartada?          -> error vendimia_descartada
6.   DB::transaction()
7.     crea el lote con vendimia_id NOT NULL
8.     la vendimia pasa a 'en_bodega'
9.     registra la etapa 'recepcion_uva'
10.  commit
```

Todo en **una** transacción. Si la etapa no se escribiera y el lote sí, la base
podría tener un lote cuyo origen no se sabe de dónde vino. Y si la vendimia
quedara en bodega sin lote, nadie sabría si se perdió o se está procesando.

## La elección de tipos: enums

Los estados y las etapas son `enum` de PHP, no cadenas en la base. Con
`EstadoLote` el compilador ayuda: si alguien escribe `$lote->estado ===
'embotellado'`, y el estado real es `EMBOTELLADO->value`, el valor no va a
calzar y la comparación falla en silencio.

Con un enum, eso no compila.

## Los errores

`App\Exceptions\ErrorDeVina` lleva un **código estable** además del mensaje:

```php
ErrorDeVina::etapaFueraDeOrden('Maceracion', 'Fermentacion');
// mensaje: No se puede registrar la etapa "Maceracion" todavia. Este lote va en "Fermentacion".
// codigo:  etapa_fuera_de_orden
```

El mensaje se lee: dice qué pasó y cuál era la etapa que sí correspondía. El
código lo usa la interfaz para reaccionar sin adivinar, y no cambia aunque se
reescriba el mensaje.

## Lo que corre junto con la API

El contenedor de la API corre tres procesos (`entrypoint.sh`):

- `php-fpm`, que atiende la API.
- `schedule:work`, que corre la tarea diaria de resumen de temporada.
- `queue:work`, que procesa la cola.

Si alguno de los tres se cae, el contenedor se cae. Un contenedor vivo sin
trabajo programado es una bodega que no revisa qué lote quedó parado, y eso
parece exactamente que todo está bien.

## Por qué no hay microservicios

Una vendimia, un lote y sus etapas están en la **misma base y la misma
transacción**. Repartirlos entre servicios obligaría a coordinar transacciones
distribuidas para algo que es, localmente, un `COMMIT`. Ver
[10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).