# 05. Configuración

Esta es la parte del sistema que la viña decide. Todo lo de acá son **reglas
de negocio**, y ninguna se puede inventar: si no se sabe, se deja en el valor
por omisión y se anota como pendiente.

## Las variables

Todas viven en `deploy/.env` (producción) o `api/.env` (desarrollo). Se leen
en `config/vina.php`.

| Variable | Por omisión | Qué hace |
|---|---|---|
| `VINA_BRIX_POR_DEFECTO` | vacío | Brix cuando la vendimia no tiene análisis |
| `VINA_ACIDEZ_POR_DEFECTO` | vacío | Acidez cuando la vendimia no tiene análisis |
| `VINA_TOLERANCIA_KILOS` | `0` | Aviso de diferencia de peso |
| `VINA_DIAS_AVISO_SIN_ACTIVIDAD` | `30` | Aviso de lote parado |
| `VINA_MINUTOS_MAXIMOS_VENTA` | `30` | Tiempo máximo de una venta abierta |
| `VINA_CORRER_MIGRACIONES` | `false` | Correr migraciones al arrancar |

## Las reglas que NO son configurables

### El orden del proceso

No está en el ambiente y no puede estarlo. Vive en el orden de las constantes
de `App\Enums\EtapaVinificacion`.

Configurarlo por variable de entorno permitiría desordenar el proceso sin que
nadie lo decidiera, y el orden es justamente lo que sostiene la trazabilidad.

### Las etapas de barrica y crianzas

No son obligatorias y tampoco configurables: la decisión es del enologo
según el vino. El sistema las ofrece y respeta la decisión, no inventa un
método que la bodega no declaró.

### La inmutabilidad del proceso

Las etapas no se editan ni se borran. No hay ninguna variable que lo desactive,
porque no es una preferencia de configuración: es la razón por la que el
sistema existe. El gatillo está en la base de datos, no en el código de la
aplicación.

## Lo que no se inventa

### El brix y la acidez

Si una vendimia no tiene análisis registrado, `brix` y `acidez` quedan en
**NULL**, no en cero y no en un número por omisión.

La razón: un brix de relleno decidiría cuándo se destaja. Es un dato de
producción, no de estimación. Y cero es una lectura distinta de "no se midió":
cero significa que alguien lo midió con un equipo y le dio cero.

Por eso `VINA_BRIX_POR_DEFECTO` está vacío por omisión, y por eso la interfaz
muestra "sin medir" en cursiva en vez de un guion.

**Si la viña prefiere un valor por omisión** (porque en su operación el brix se
estima siempre), se puede definir. Pero conviene saber que en ese caso la
trazabilidad del proceso depende de una estimación, y conviene que quede
registrada como tal.

## Lo que avisa y lo que bloquea

Esta distinción importa y hay que tenerla clara en cada variable:

| Variable | ¿Bloquea? | Por qué |
|---|---|---|
| `VINA_DIAS_AVISO_SIN_ACTIVIDAD` | No | Una guarda larga en barrica es legítima |
| `VINA_TOLERANCIA_KILOS` | No | El peso real es el de la báscula, no el declarado |
| `VINA_MINUTOS_MAXIMOS_VENTA` | **Sí** | Una venta abierta descuadra la caja |
| `VINA_BRIX_POR_DEFECTO` | No | Es un dato de referencia |

El sistema avisa cuando algo es raro y bloquea solo cuando continuar dejaría
el registro en un estado que no se puede arreglar después.

## El resumen de temporada

Corre una vez al día (`schedule:work`, a las 07:00) y registra: cuántas
parcelas activas hay, cuántas vendimias lleva el año y cuántos lotes llevan
mucho tiempo sin actividad.

Registra, no avisa por correo ni genera reportes. Mandar avisos automáticos
sería otra aplicación.

## Los pendientes de confirmar con la viña

Esto **no se puede decidir desde el código**. Cada uno está marcado en el lugar
donde aparece.

### El tiempo máximo de una venta abierta

Hoy son 30 minutos. Si la viña vende en la sala de catas, donde alguien prueba
tres vinos antes de decidir, 30 minutos es poco.

### La curva de brix por variedad

La pantalla de campo esconde el campo de brix para Chardonnay, Sauvignon Blanc
y Pinot Noir, por criterio técnico general (las whites no siguen la misma curva
que las tintas). **Es un criterio asumido, no uno declarado por la bodega.**

Si la viña mide brix en las whites, hay que sacar esa restricción de
`web/app/pages/campo.vue`.

### Los precios

No hay ninguna lista de precios: cada venta lleva los suyos. Si la viña tiene
catálogo con precios fijos, hace falta otra cosa: la venta tendría que leer el
precio de un catálogo y no aceptarlo desde la pantalla, porque un precio
escrito a mano en el mostrador es un precio que alguien puede equivocar.

### Si una parcela puede tener dos vendimias el mismo día

Hoy sí, con numeración correlativa separada. En una vendimia real, la misma
cuadra se puede cosechar en dos momentos distintos del mismo día, y eso es
legítimo.

### Un lote, un embotellado

Un lote sale a botella **una vez**, porque la etapa `embotellado` es única por
lote. Si la viña embotella en varios formatos desde el mismo lote, hay que
cambiar el modelo. Ver
[02-MODELO-DE-DATOS.md](02-MODELO-DE-DATOS.md).

### La unidad de medida

Los montos están en pesos. La columna admite decimales, pero la interfaz no los
muestra. Si la viña vende en otra unidad, hay que ajustar.