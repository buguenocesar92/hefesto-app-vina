# 09. API

Todas las rutas responden JSON y viven bajo `/api/v1`.

Las rutas están en el grupo `web` de Laravel a propósito: la interfaz (Nuxt)
actúa como servidor intermedio, así que la cookie de sesión viaja en el mismo
origen. Ver [01-ARQUITECTURA.md](01-ARQUITECTURA.md).

## Los códigos de error

Un error de negocio devuelve **422** con un código estable:

```json
{
  "mensaje": "No se puede registrar la etapa «Maceracion» todavia. Este lote va en «Fermentacion».",
  "codigo": "etapa_fuera_de_orden"
}
```

El mensaje se lee: dice qué pasó y cuál era la etapa que sí correspondía. El
código lo usa la interfaz para reaccionar sin adivinar, y **no cambia** aunque
se reescriba el mensaje.

| Código | Qué pasó |
|---|---|
| `etapa_fuera_de_orden` | La etapa no es la siguiente del proceso |
| `etapa_ya_registrada` | Esa etapa ya se registró en este lote |
| `lote_cerrado` | El lote ya está embotellado o fue descartado |
| `lote_no_estabilizado` | Sin estabilización no sale a botella |
| `vendimia_ya_vinificada` | Esa vendimia ya tiene un lote |
| `vendimia_descartada` | La vendimia se descartó |
| `fecha_de_vendimia_futura` | No se puede cosechar mañana |
| `fecha_de_etapa_futura` | No se puede registrar una etapa de mañana |
| `fecha_vendimia_inmutable` | La fecha ya no se puede cambiar |
| `venta_sin_embotellado` | La venta no apunta a un embotellado real |
| `lote_no_venduible` | El lote no está embotellado |
| `sin_botellas` | No quedan botellas suficientes |
| `venta_abierta_demasiado_tiempo` | La venta lleva demasiado tiempo abierta |
| `venta_sin_items` | Una venta sin botellas no es una venta |
| `venta_ya_pagada` | Una venta pagada no se anula |
| `descarte_sin_motivo` | Descartar necesita decir por qué |
| `estado_invalido` | La transición de estado no existe |
| `parcela_invalida` | La parcela no existe o está desactivada |
| `codigo_ausente` | Falta el código a consultar |
| `codigo_no_encontrado` | Ningún vino tiene ese código |

## Público

Estas rutas no necesitan sesión. Son las únicas cuatro.

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/salud` | ¿Está la API viva? No toca la base |
| `GET` | `/api/v1/vinas` | El selector de viñas |
| `GET` | `/api/v1/acceso` | Parte al proveedor de identidad |
| `GET` | `/api/v1/acceso/callback` | Vuelve del proveedor |

`/api/v1/vinas` lee la vista `vw_vinas_publicas`, que expone **tres columnas**
(`slug`, `nombre`, `activo`) y solo viñas activas. Es la compensación de que la
tabla `tenants` no lleve RLS: antes de autenticarse no hay `app.tenant_id`.

`/api/v1/salud` **no consulta la base** a propósito: se usa en el
`healthcheck` de Docker, y una consulta a la base haría que un problema de
PostgreSQL pareciera un problema de la API. Para comprobar la base está
`vina:verificar-aislamiento`.

## Con sesión

### Quién soy

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/yo` | La persona y su rol, con los permisos |
| `GET` | `/api/v1/yo/diagnostico` | Comprueba el aislamiento desde adentro |
| `POST` | `/api/v1/salir` | Cierra la sesión |

`/yo/diagnostico` compara lo que ve la sesión aislada con lo que vería una
lectura **sin** contexto. Si las dos son iguales, el aislamiento está abierto.

### El campo

| Método | Ruta | Rol | Qué hace |
|---|---|---|---|
| `GET` | `/api/v1/parcelas` | todos | Lista las parcelas |
| `POST` | `/api/v1/parcelas` | encargado | Crea una parcela |
| `GET` | `/api/v1/parcelas/{id}` | todos | Una parcela |
| `PUT` | `/api/v1/parcelas/{id}` | encargado | Edita una parcela |
| `POST` | `/api/v1/parcelas/{id}/desactivar` | encargado | La desactiva |

No hay `DELETE` de parcela: una parcela con vendimias **no se borra**, porque
sus vendimias son el origen de lotes que talvez ya se vendieron.

| Método | Ruta | Rol | Qué hace |
|---|---|---|---|
| `GET` | `/api/v1/vendimias` | todos | Lista las vendimias |
| `POST` | `/api/v1/vendimias` | bodega | Registra una vendimia |
| `GET` | `/api/v1/vendimias/{id}` | todos | Una vendimia |
| `POST` | `/api/v1/vendimias/{id}/bodega` | bodega | Manda al lagar y abre el lote |
| `POST` | `/api/v1/vendimias/{id}/descartar` | encargado | La descarta con motivo |

**No hay ruta para editar la fecha de una vendimia** cuando ya tiene lote. No
por un olvido en el router: hay un gatillo en la base que la rechaza.

### Lotes y vinificación

| Método | Ruta | Rol | Qué hace |
|---|---|---|---|
| `GET` | `/api/v1/lotes` | todos | Lista los lotes |
| `GET` | `/api/v1/lotes/{id}` | todos | Un lote con su proceso |
| `POST` | `/api/v1/lotes/{id}/etapas` | bodega | **Registra** una etapa |
| `POST` | `/api/v1/lotes/{id}/embotellar` | bodega | Embotella un lote estabilizado |
| `POST` | `/api/v1/lotes/{id}/descartar` | encargado | Lo descarta con motivo |

**Este es el grupo más importante de la API.**

`POST /lotes/{id}/etapas` es la **única** forma de avanzar un lote. No hay `PUT`
ni `DELETE` de etapas, y en la base hay un gatillo que revienta los dos
intentos.

Un lote mal criado no se puede deshacer.

La respuesta incluye qué sigue:

```json
{
  "etapa": { "etapa": "destaje", "etiqueta": "Destaje", "fecha": "2024-03-20" },
  "lote": { "...": "..." },
  "siguiente_etapa": { "etapa": "fermentacion", "etiqueta": "Fermentacion" },
  "opcionales_disponibles": [
    { "etapa": "barriqueado", "etiqueta": "Barriqueado" },
    { "etapa": "crianzas", "etiqueta": "Crianzas" }
  ]
}
```

`siguiente_etapa` en `null` significa que ya no queda nada obligatorio por hacer.
`opcionales_disponibles` son las etapas opcionales que el enologo puede tomar
todavía: se ofrecen, no se imponen.

### Trazabilidad

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/trazabilidad?codigo=...` | Busca por código de lote o embotellado |
| `GET` | `/api/v1/trazabilidad/lotes/{id}` | La trazabilidad de un lote |
| `GET` | `/api/v1/trazabilidad/embotellados/{id}` | La trazabilidad de un embotellado |
| `GET` | `/api/v1/trazabilidad/parcelas/{id}` | Qué se hizo con esa parcela |

La respuesta trae **toda** la cadena, eslabón por eslabón, y un indicador:

```json
{
  "lote":      { "codigo": "LT-LIT-2024-001", "anio": 2024, "estado": "embotellado" },
  "vendimia":  { "numero": "VEN-LIT-2024-001", "fecha_vendimia": "2024-03-18", "brix": 24.5 },
  "parcela":   { "codigo": "LIT", "nombre": "Litoral", "variedad": "Cabernet Sauvignon" },
  "completa": true,
  "linea_de_origen": "Litoral - Cabernet Sauvignon, vendimia del 2024-03-18"
}
```

**`completa: false` no es un error.** Es la respuesta honesta cuando a la cadena
le falta un eslabón, y el sistema no completa el hueco con nada inventado.

En ese caso `linea_de_origen` viene en `null`: una línea de origen con huecos
("Litoral - , vendimia del ") es peor que ninguna, porque parece que alguien se
lo saltó.

### Ventas

| Método | Ruta | Rol | Qué hace |
|---|---|---|---|
| `GET` | `/api/v1/ventas` | todos | Lista las ventas |
| `POST` | `/api/v1/ventas` | piso | Abre una venta |
| `GET` | `/api/v1/ventas/{id}` | todos | Una venta, con su origen |
| `POST` | `/api/v1/ventas/{id}/pagar` | piso | La cobra |
| `POST` | `/api/v1/ventas/{id}/anular` | encargado | La anula |
| `GET` | `/api/v1/embotellados/{id}/disponibles` | todos | Botellas que quedan |

Cada `item` de una venta apunta a un **embotellado**, y trae su
`linea_de_origen`. Por eso desde cualquier venta se puede volver hasta la
parcela sin que nadie mantenga una columna de procedencia aparte.

Anular una venta **devuelve las botellas** al embotellado. Un error de tipeo
en el mostrador no puede dejar el inventario corto para siempre.

### Configuración

| Método | Ruta | Rol | Qué hace |
|---|---|---|---|
| `GET` | `/api/v1/configuracion` | todos | El resumen de la temporada |
| `GET` | `/api/v1/configuracion/personas` | todos | Las personas con su rol |
| `POST` | `/api/v1/configuracion/personas/{id}/rol` | administrador | Cambia el rol |

El resumen sale de las filas, no de un resumen guardado: un resumen guardado se
desincroniza el día que un lote cambia de estado.

## Los tipos del lado del navegador

`web/app/tipos/api.ts` es una copia escrita a mano de lo que devuelve la API.
Si un tipo y la API no coinciden, **el problema está en el tipo**.

Podrían generarse desde OpenAPI, pero hoy no hay ningún endpoint que lo exponga,
y prometer el comando "para generarlo después" sería prometer algo que no
existe. Cuando exista, se reemplazan por los generados.

## El `null` en los tipos

En los tipos del navegador, `null` casi nunca es "falta un dato":

| Dato | `null` significa |
|---|---|
| `brix` | No se midió |
| `acidez` | No se midió |
| `linea_de_origen` | La cadena de trazabilidad está rota |
| `siguiente_etapa` | Ya no queda nada obligatorio |
| `brix_promedio` | Ninguna vendimia del año tiene análisis |

Cada caso lleva su `tiene_brix` / `tiene_acidez` cuando la interfaz lo necesita
para distinguir "no se midió" de "se midió y dio cero".