# Viña y Bodega

Aplicación de Hefesto Studio para una viña chica: el campo y la bodega juntas,
con la trazabilidad del vino como problema central.

## El problema que resuelve

En una bodega, el vino no existe hasta que la vendimia entra y se vinifica. Y
un lote mal criado no se puede deshacer. De ahí salen las dos reglas que
organizan todo el sistema:

**La trazabilidad se registra, no se deduce.** La uva de una parcela de una
vendimia da un lote. Ese lote sabe de qué parcela y de qué fecha de vendimia
viene, y el dato se escribe una vez y no se toca. Hay un gatillo en la base que
impide cambiar la fecha de vendimia cuando el lote ya existe. Es lo que permite
decir, meses después, que una botella es de la vendimia del 12 de marzo, y que
ese dato no lo cambió nadie cuando dejó de convenir.

**El proceso se agrega, no se corrige.** Cada etapa de vinificación se registra
con su fecha real: destaje, fermentación, maceración, clarificación,
estabilización, barrica, crianzas, embotellado. Las etapas registradas no se
editan ni se borran, y hay un gatillo en la base que revienta los dos intentos.
Un error de tipeo en una fecha no se corrige editando: se registra la etapa
correcta y la anterior queda como lo que ocurrió.

Y donde el camino está roto, el sistema lo dice. No completa el hueco con el
nombre de la parcela "de memoria" ni deduce el origen del código del lote. Si
falta un eslabón, responde que falta. Una trazabilidad que se inventa es peor
que ninguna.

## Qué tiene

- **Campo**: parcelas con su variedad, y vendimias con fecha, kilos y análisis.
  El brix y la acidez quedan sin medir cuando no se midieron: un número
  inventado decidiría cuándo se destaja.
- **Vinificación**: las etapas de cada lote, en orden, como un registro que no
  se reescribe. Las etapas de barrica y crianzas se ofrecen, no se imponen.
- **Trazabilidad**: de una botella hasta la parcela y la fecha de vendimia.
- **Ventas**: venta de vino embotellado, con la trazabilidad dentro de la venta.

## Cómo se levanta

```bash
cd apps/vina/deploy
docker compose -f docker-compose.dev.yml up -d
docker compose -f docker-compose.dev.yml exec api php artisan db:seed
```

La interfaz queda en `http://localhost:3000`.

Detalle completo en [docs/04-INSTALACION.md](docs/04-INSTALACION.md).

## La documentación

| # | Documento |
|---|---|
| 00 | [Índice](docs/00-INDICE.md) |
| 01 | [Arquitectura](docs/01-ARQUITECTURA.md) |
| 02 | [Modelo de datos](docs/02-MODELO-DE-DATOS.md) |
| 03 | [Multiempresa y roles](docs/03-MULTITENANCY-Y-ROLES.md) |
| 04 | [Instalación](docs/04-INSTALACION.md) |
| 05 | [Configuración](docs/05-CONFIGURACION.md) |
| 06 | [Primera vendimia](docs/06-PRIMERA-VENDIMIA.md) |
| 07 | [Seguridad](docs/07-SEGURIDAD.md) |
| 08 | [Respaldos](docs/08-RESPALDOS.md) |
| 09 | [API](docs/09-API.md) |
| 10 | [Pruebas y estado](docs/10-PRUEBAS-Y-ESTADO.md) |

## Cómo está armado

```
apps/vina/
├── api/           Laravel 13, PHP 8.4
├── web/           Nuxt 4, modo servidor
├── deploy/        Docker, Caddy, respaldos
└── docs/          La documentación de arriba
```

## Estado

El árbol está completo, pero **las pruebas no se han ejecutado** en esta
sesión: no había Docker ni una base PostgreSQL disponible. Qué falta correr y
qué no está probado está en
[docs/10-PRUEBAS-Y-ESTADO.md](docs/10-PRUEBAS-Y-ESTADO.md).

Hay reglas de negocio que **necesitan confirmación de la viña** antes de dar por
cerrado el sistema: el tiempo máximo de una venta abierta, la curva de brix por
variedad, si una parcela puede tener dos vendimias el mismo día, y si un lote
puede embotellarse en más de un formato. Están listadas en
[docs/05-CONFIGURACION.md](docs/05-CONFIGURACION.md).