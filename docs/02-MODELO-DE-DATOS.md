# 02. Modelo de datos

## Las 9 tablas

| Tabla | Qué guarda | Notas |
|---|---|---|
| `tenants` | Las viñas usuarias | La **única** sin `tenant_id` ni RLS |
| `usuarios` | Las personas y su rol | La contraseña la resuelve Authentik |
| `parcelas` | Los bloques del viñedo | El origen de toda la trazabilidad |
| `vendimias` | La uva de una parcela, en una fecha | La fecha es inmutable si hay lote |
| `lotes` | El vino que sale de una vendimia | `vendimia_id` es NOT NULL |
| `etapas_vinificacion` | Las etapas por las que pasó el lote | Inmutable: ni `UPDATE` ni `DELETE` |
| `embotellados` | El momento en que sale a botella | El último eslabón |
| `ventas`, `venta_items`, `pagos` | La venta de vino embotellado | `venta_items` apunta al embotellado |

Son nueve tablas de negocio y una de identidad: `tenants`. Cada una con
`tenant_id` y Row Level Security, salvo `tenants`. Ver
[03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md).

## El camino de una botella

Este es el corazón del modelo, y todo lo demás existe para sostenerlo:

```
parcela  ->  vendimia  ->  lote  ->  embotellado  ->  botella vendida
```

Cada eslabón guarda a quién apunta y cuándo. Una venta apunta al
**embotellado**, no al lote, porque el embotellado es la unidad real que sale de
la bodega y porque así el camino hacia la parcela no necesita ningún dato
duplicado.

Por eso desde cualquier venta se puede volver hasta la parcela sin que nadie
mantenga una columna de procedencia aparte.

## La trazabilidad se registra, no se deduce

`lotes.vendimia_id` es **NOT NULL**. Un lote sin vendimia de origen es un lote
que no se sabe de dónde viene, y eso es justo lo que un sistema de trazabilidad
no puede permitir.

El sistema **nunca** deduce el origen. No lo saca del código del lote, no lo
reconstruye del año, no completa los huecos. El servicio
`TrazadorDeVino` recorre las relaciones reales y las lee. Si un eslabón falta,
devuelve `null` y la interfaz lo dice.

Hay una prueba que rompe el camino a mano y comprueba que la respuesta sea
"no hay trazabilidad", y no un origen inventado:
`TrazabilidadTest::test_si_la_cadena_esta_rota_el_sistema_no_inventa_el_origen`.

## Las tres garantías de la base

Las tres están **en la base**, no en la aplicación. Si dependieran de que la
aplicación se porte bien, bastaría una consulta a mano para romperlas.

### 1. La fecha de vendimia es inmutable cuando ya hay un lote

```sql
CREATE TRIGGER trg_fecha_vendimia_inmutable
BEFORE UPDATE OF fecha_vendimia ON public.vendimias
```

Si la vendimia ya tiene un lote, cambiar la fecha lanza excepción.

**Por qué:** la fecha de vendimia es el origen de la trazabilidad. Si se
pudiera corregir cuando deja de convenir, la garantía "este vino es de la
vendimia del 12 de marzo" valdría lo que valga la última edición.

**Antes de que haya lote sí se puede corregir**, y es legítimo: uno puede
teclear de más el mismo día de la cosecha. Ver
`FechaDeVendimiaTest::test_la_fecha_se_puede_corregir_si_todavia_no_hay_lote`.

### 2. Las etapas no se editan ni se borran

```sql
CREATE TRIGGER trg_etapas_inmutables
BEFORE UPDATE OR DELETE ON public.etapas_vinificacion
```

Cualquier `UPDATE` o `DELETE` sobre una etapa registrada lanza excepción.

**Por qué:** "un lote mal criado no se puede deshacer" no es una frase de este
sistema: es la razón por la que el historial de un proceso de vinificación es un
registro y no una hoja de papel. Para corregir un error de tipeo en una fecha,
se registra la etapa correcta y la anterior queda como lo que ocurrió.

### 3. Una etapa no puede tener fecha de futuro

```sql
CHECK (fecha <= CURRENT_DATE)
```

Sin esto, se podría "registrar" una clarificación con fecha de mañana, y el
historial temporal del lote dejaría de ser real.

## El orden del proceso

El orden **no lo decide la aplicación**: lo decide el orden de las constantes
de `App\Enums\EtapaVinificacion`. `siguienteEtapaDe()` es lo único que decide
si una etapa se puede registrar, y sale de ahí.

Si el avance dependiera de un número guardado en la base, bastaría un `UPDATE`
para saltar de `fermentacion` a `embotellado` y tener un vino que en la realidad
jamás existió.

### Las obligatorias y las opcionales

| Etapa | Obligatoria |
|---|---|
| `recepcion_uva` | Sí |
| `destaje` | Sí |
| `fermentacion` | Sí |
| `maceracion` | Sí |
| `clarificacion` | Sí |
| `estabilizacion` | Sí |
| `barriqueado` | **No** |
| `crianzas` | **No** |
| `embotellado` | Sí |

Barrica y crianzas **se ofrecen, no se imponen**: un vino joven no pasa por
barrica y es un vino legítimo. La secuencia de avance la marcan las
obligatorias, así que un lote estabilizado no queda esperando una barrica que no
va a tener. Hay una prueba que lo comprueba:
`EtapasDeVinificacionTest::test_un_vino_sin_barrica_se_puede_embotellar_igual`.

## Lo que no se inventa

`vendimias.brix` y `vendimias.acidez` son **NULL cuando no se midieron**, y
cero es una lectura distinta de "no se midió".

Un brix de relleno decidiría cuándo se destaja: es un dato de producción, no de
estimación. Los valores por omisión del ambiente (`VINA_BRIX_POR_DEFECTO`,
`VINA_ACIDEZ_POR_DEFECTO`) están **vacíos a propósito**. Ver
[05-CONFIGURACION.md](05-CONFIGURACION.md).

La interfaz distingue los dos casos: `brix: null` se muestra "sin medir", en
cursiva. Un cero se vería como un análisis que alguien midió y dio cero.

## La pérdida queda a la vista

Un lote **descartado** y una vendimia **descartada** no se borran: cambian de
estado y quedan, con su motivo.

Borrarlos sería dejar la pérdida invisible, y es justo lo que una bodega
honesta no puede hacer: una pérdida que no se registra es una pérdida que se
repite el año siguiente.

En la API, `descartar` sin `motivo` responde `422 descarte_sin_motivo`.

## Una vendimia, un lote

`vendimias.lote` es `hasOne`, no `hasMany`. La relación está garantizada además
por el servicio `RegistradorDeEtapa::abrirLoteDesde()`, que rechaza con
`vendimia_ya_vinificada` si la vendimia ya tiene lote.

Vinificar dos veces la misma uva sería una pérdida de trazabilidad disfrazada
de prioridad.

## El número y el código

Van con el código de la parcela y el año, porque es lo que permite reconstruir
el origen leyendo la etiqueta:

- `VEN-LIT-2024-001` - vendimia, parcela `LIT`, año 2024
- `LT-LIT-2024-001` - lote, misma parcela y año

El lote hereda el código de la parcela de su vendimia, así que no hay dos
formas de nombrar el mismo vino.

## Un lote, un embotellado

La etapa `embotellado` es única por lote (índice único en
`etapas_vinificacion`), así que un lote sale a botella **una sola vez**. Para
sacar más botellas se abre otro lote desde otra vendimia.

Para una bodega que embotella en varios formatos, esto es una limitación. Está
documentada en [10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md) como algo a
confirmar con la viña.