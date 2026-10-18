# 06. Primera vendimia

Este es el camino completo, desde cero hasta el vino embotellado. Está escrito
como se haría la primera vez, con lo que hay que hacer y lo que hay que mirar.

## Antes de la vendimia

### Cargar las parcelas

En **Campo**, la persona con rol `encargado` o `administrador` carga los
bloques del viñedo. Lo importante:

- **El código** (`LIT`, `CER`) es corto y es el que va en el código del lote y
  de la vendimia. Después de la primera vendimia, cambiarlo rompe la
  lectura de las etiquetas anteriores.
- **El nombre** es el que va a aparecer en la trazabilidad. Si se escribe mal,
  sale mal en todas las botellas.
- **La variedad** la declara la bodega. El sistema no la deduce.

Una parcela se **desactiva**, no se borra. Una parcela con vendimias no se
borra: sus vendimias son el origen de lotes que talvez ya se vendieron.

### Cargar las personas

Cada persona necesita un rol. Ver [03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md).

El rol importa más de lo que parece: el `bodega` registra etapas pero **no**
puede descartar un lote, y el `piso` vende pero **no** puede tocar el campo.

## El día de la vendimia

### Registrar la vendimia

En **Campo**, la persona con rol `bodega` o superior.

Lo que se escribe:

| Campo | ¿Obligatorio? | Nota |
|---|---|---|
| Parcela | Sí | La que se está cosechando |
| Fecha de vendimia | Sí | La fecha real, no la de después |
| Kilos cosechados | Sí | Los que dice la báscula |
| Brix | No | Si se midió |
| Acidez | No | Si se midió |
| Notas | No | |

**El brix y la acidez quedan vacíos si no se midieron.** No hay un valor por
omisión, y eso es a propósito: un brix inventado decide cuándo se destaja.

Lo que se escribe acá es el **origen de todo el vino que salga de esa cuadra**.
La fecha deja de poder editarse en el momento en que exista un lote.

### Corregir un error antes de vinificar

Si la fecha o los kilos quedaron mal, se corrigen sin problema: **mientras la
vendimia no tenga lote**, la fecha es editable.

En cuanto el lote existe, el cambio se rechaza, y está bien que sea así: es lo
que hace que la trazabilidad valga.

### Descartar una vendimia

Si la uva no se usa (no estaba en estado, o se dejó para el otro día), se
**descarta con motivo**. No se borra, y el motivo queda escrito.

Una pérdida sin motivo es una pérdida que se repite el año siguiente.

## La uva entra al lagar

### Abrir el lote

En **Campo**, la vendimia tiene un botón para mandarla al lagar. Ahí ocurren
tres cosas **en una sola transacción**:

1. Se crea el lote con su código (`LT-LIT-2024-001`).
2. La vendimia pasa a `en_bodega`.
3. Se registra la etapa `recepcion_uva` con la fecha de la vendimia.

Si algo falla, no pasa ninguna de las tres. Es lo que evita tener una vendimia
en la bodega sin lote, o un lote cuyo origen no se sabe.

La etapa `recepcion_uva` se registra sola porque el hecho de abrir el lote
**es** el ingreso de la uva al lagar.

## La vinificación

### Registrar cada etapa

En **Lotes**, se abre el lote y se va registrando lo que va pasando: destaje,
fermentación, maceración, clarificación, estabilización.

Tres cosas que hay que tener claras:

**La etapa tiene que ser la siguiente.** Si se intenta la maceración antes de
fermentar, la API responde con un mensaje que dice cuál era la que tocaba. No
es un capricho de la pantalla: es el orden real del proceso.

**No se puede corregir una etapa.** Si la fecha del destaje quedó mal, se
registra la etapa correcta y la anterior queda como lo que ocurrió. Es lo único
que una bodega honesta puede hacer, y lo único que el sistema deja hacer.

**La fecha es la real.** No se puede registrar una etapa con fecha de mañana.
Una etapa con fecha futura se rechaza.

### Barrica y crianzas

Se **ofrecen**, no se imponen. Un vino joven no pasa por barrica y es un vino
legítimo.

Si se decide hacer la guarda en barrica, la etapa se registra y queda en el
historial: quince meses en barrica es un dato del vino.

Si el lote se estabiliza y después se pasa a botella sin pasar por barrica, no
falta nada: el sistema no exige la etapa opcional.

## Embotellar

Solo un lote **estabilizado** sale a botella. La pantalla lo dice y la API lo
exige, con el mensaje: "todavía no está estabilizado. Sin estabilización no
sale a botella".

En **Lotes**, se indican las botellas y el formato. El sistema registra la
etapa `embotellado` y crea el embotellado, en una sola transacción: o pasa
todo, o no pasa nada.

Un lote sale a botella **una vez**. Para sacar más botellas se abre otro lote.

## Vender

En **Ventas**, la persona con rol `piso` o superior abre una venta. Cada línea
apunta a un **embotellado**, no a un lote.

Abajo de cada línea aparece el origen del vino: el nombre de la parcela, la
variedad y la fecha de vendimia. No es adorno; es lo que permite responder
meses después de qué parcela era el vino que se vendió.

La venta se cobra. Cobrar es cerrar la caja; anular es una corrección del
encargado, y devuelve las botellas al embotellado.

## Comprobar que todo quedó bien

### La trazabilidad

En **Trazabilidad**, se escribe el código del lote o del embotellado y tiene
que aparecer:

- el lote y su nombre,
- la vendimia con su fecha y sus kilos,
- la parcela con su nombre y su variedad,
- y en grande, la línea de origen.

Si alguna vez aparece el aviso de "trazabilidad incompleta", **no se ignores**:
significa que falta un eslabón y hay que ir a buscarlo. Un origen que se
rellena a la ligera es peor que un origen que falta.

### La fecha no se movió

La fecha de vendimia es el dato que sostiene todo. Si aparece la fecha que se
registró ese día, y no otra, la trazabilidad está intacta.

### Las etapas no se tocan

Se puede revisar que las etapas del lote estén todas y en orden. Lo que **no**
se puede es editarlas ni borrarlas: no hay botón, y si alguien lo intentara
contra la base, la base lo rechaza.

## Lo que hay que mirar al final de la temporada

En **Resumen**:

- kilos cosechados y cuántas vendimias,
- cuántos lotes se embotellaron y cuántos se **perdieron**,
- cuántas botellas salieron,
- cuánto se vendió.

Los lotes perdidos están a la vista a propósito. El año que viene, esa es la
columna que dice si se está perdiendo más de lo que se cree.