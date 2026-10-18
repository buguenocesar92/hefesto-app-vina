# Viña y Bodega

Aplicación de Hefesto Studio para una viña chica: el campo y la bodega
juntas, con la trazabilidad del vino como problema central.

## Qué resuelve

En una bodega, el vino no existe hasta que la vendimia entra y se vinifica. Y
un lote mal criado no se puede deshacer. De ahí salen las dos reglas que
organizan todo el sistema:

**La trazabilidad se registra, no se deduce.** La uva de una parcela de una
vendimia da un lote. Ese lote sabe de qué parcela y de qué fecha de vendimia
viene, y ese dato se escribe una vez y no se toca. La fecha de vendimia deja de
poder editarse cuando el lote ya existe, y hay un gatillo en la base que lo
impone. Es lo que permite decir, meses después, que una botella es de la
vendimia del 12 de marzo, y que ese dato no lo cambió nadie cuando dejó de
convenir.

**El proceso se agrega, no se corrige.** Cada etapa de vinificación se registra
con su fecha real: destaje, fermentación, maceración, clarificación,
estabilización, barrica, crianzas, embotellado. Las etapas registradas no se
editan ni se borran, y hay un gatillo en la base que revienta los dos intentos.
Un error de tipeo en una fecha no se corrige editando: se registra la etapa
correcta y la anterior queda como lo que ocurrió.

Y donde el camino está roto, el sistema lo dice. No completa el hueco con el
nombre de la parcela "de memoria" ni deduce el origen del código del lote: si
falta un eslabón, responde que falta. Una trazabilidad que se inventa es peor
que ninguna.

## Qué tiene

- **Campo**: parcelas con su variedad, y vendimias con fecha, kilos y análisis.
  El brix y la acidez quedan sin medir cuando no se midieron: un número
  inventado decidiría cuándo se destaja.
- **Vinificación**: las etapas de cada lote, en orden, como un registro que no
  se reescribe. Las etapas de barrica y crianzas se ofrecen, no se imponen:
  un vino joven no pasa por barrica y es un vino legítimo.
- **Trazabilidad**: de una botella hasta la parcela y la fecha de vendimia.
- **Ventas**: venta de vino embotellado, con la trazabilidad dentro de la venta,
  para responder meses después de qué parcela era el vino que se vendió.

## Documentación

| # | Documento | Qué hay |
|---|---|---|
| 00 | [Índice](00-INDICE.md) | Esta lista y por dónde empezar |
| 01 | [Arquitectura](01-ARQUITECTURA.md) | Las capas y el flujo de una petición |
| 02 | [Modelo de datos](02-MODELO-DE-DATOS.md) | Las 9 tablas y las reglas de la base |
| 03 | [Multiempresa y roles](03-MULTITENANCY-Y-ROLES.md) | Cómo una viña no ve los datos de otra |
| 04 | [Instalación](04-INSTALACION.md) | Cómo se levanta, de cero |
| 05 | [Configuración](05-CONFIGURACION.md) | Lo que la viña decide, y lo que no se inventa |
| 06 | [Primera vendimia](06-PRIMERA-VENDIMIA.md) | La primera temporada, de punta a punta |
| 07 | [Seguridad](07-SEGURIDAD.md) | Puertos, sesión, Authentik y cookies |
| 08 | [Respaldos](08-RESPALDOS.md) | Cómo se guarda y cómo se recupera |
| 09 | [API](09-API.md) | Las rutas y los códigos de error |
| 10 | [Pruebas y estado](10-PRUEBAS-Y-ESTADO.md) | Qué está probado y qué falta |

## Qué está pendiente de confirmar con la viña

Estas cosas **no se pueden decidir desde el código**: son reglas de la bodega.
Están marcadas en los lugares donde aparecen.

- **Los precios.** No hay ninguna lista de precios: cada venta los lleva. Una
  bodega chica con lista de precios necesita otra cosa.
- **El tiempo máximo de una venta abierta.** Ahora son 30 minutos
  (`VINA_MINUTOS_MAXIMOS_VENTA`).
- **La curva de brix por variedad.** La pantalla esconde el brix para las
  variedades blancas, por criterio técnico general, no por un criterio
  declarado por la bodega.
- **Si una parcela puede tener dos vendimias el mismo día.** Hoy se permite;
  con el código de parcela, el año y el correlativo, quedan separadas.
- **La unidad de medida del precio.** Se guarda en pesos, sin decimales, pero
  la columna admite más.

## Lo que este sistema no hace

- **No tiene contabilidad.** No emite facturas ni boletas. El venta registra
  que se cobró, no lo que se declaró.
- **No lleva inventario de insumos.** No hay sulfitos, ni levaduras, ni
  barriles con stock. El techo de este producto es el proceso del vino y su
  trazabilidad.
- **No manda el vino.** Las ventas no despachan ni guides de reparto.
- **No es un catálogo comercial.** No hay fichas de vino para la sala de
  catas ni listas de precios.
- **No corrige datos.** Un lote mal criado queda como se creó. Ese es el
  punto, no una limitación.