# 10. Pruebas y estado

## LA VERDAD, PRIMERO

**Lo que sigue no se ha ejecutado.** Este documento describe qué pruebas
existen y qué comprueban, y qué falta. El árbol de archivos está completo, pero
**no se ha corrido `php artisan test` ni se ha levantado la aplicación en un
entorno real**, porque esta sesión no tenía Docker ni una base PostgreSQL.

Para verificar:

```bash
cd apps/vina
docker compose -f deploy/docker-compose.dev.yml up -d postgres
docker compose -f deploy/docker-compose.dev.yml run --rm api php artisan test
```

Ver la sección "Lo que hay que correr primero" más abajo.

## La base de pruebas

**PostgreSQL, no SQLite.** Y no es una preferencia.

El aislamiento entre viñas usa Row Level Security, y la inmutabilidad del
proceso usa gatillos de la base. En SQLite:

- no existen las políticas de RLS,
- no existen los gatillos,
- y las pruebas de aislamiento y de inmutabilidad **pasarían sin comprobar
  nada**.

Serían pruebas verdes que no prueban. Es peor que no tenerlas.

La base es `vina_test`, separada de la de desarrollo.

## Las cinco suites

Cada suite tiene una pregunta. Correr solo la que interesa:

```bash
php artisan test --testsuite=Aislamiento
php artisan test --testsuite=Trazabilidad
php artisan test --testsuite=Vinificacion
php artisan test --testsuite=Campo
php artisan test --testsuite=Ventas
```

### `Aislamiento`: que una viña no vea los datos de otra

`tests/Feature/Aislamiento/AislamientoDeDatosTest.php`

| Prueba | Qué comprueba |
|---|---|
| `test_una_vina_no_ve_las_parcelas_de_otra` | Solo ve lo suyo |
| `test_leer_sin_contexto_falla` | Sin contexto, la lectura falla |
| `test_la_funcion_de_contexto_falla_cerrada` | Lanza excepción, no devuelve NULL |
| `test_no_se_puede_escribir_una_fila_de_otra_vina` | El `WITH CHECK` funciona |
| `test_las_vendimias_tambien_estan_aisladas` | Todas las tablas, no solo una |
| `test_la_vista_publica_expone_solo_tres_columnas` | La vista no filtra de más |
| `test_la_vista_publica_no_muestra_vinas_inactivas` | Solo viñas activas |

### `Trazabilidad`: de dónde viene este vino

`tests/Feature/Trazabilidad/TrazabilidadTest.php`

| Prueba | Qué comprueba |
|---|---|
| `test_el_embotellado_devuelve_toda_la_cadena_hasta_la_parcela` | La cadena completa |
| `test_la_linea_de_origen_dice_la_parcela_la_variedad_y_la_fecha` | La línea de la etiqueta |
| `test_dos_lotes_de_dos_parcelas_dicen_parcelas_distintas` | No hay mezcla |
| `test_si_la_cadena_esta_rota_el_sistema_no_inventa_el_origen` | **No adivina** |
| `test_la_parcela_muestra_sus_lotes_y_sus_vendimias` | La vista por parcela |
| `test_el_lote_sin_embotellar_tiene_traza_desde_la_vendimia` | La traza no depende de la botella |
| `test_el_endpoint_de_trazabilidad_responde_por_codigo_de_lote` | El atajo por código |
| `test_un_codigo_inexistente_responde_404_sin_revelar_otros` | No filtra existencia |

La cuarta es la más importante de esta suite: **parte el camino a mano** y
comprueba que la respuesta sea "no hay trazabilidad", y no un origen inventado.
Un sistema que ahí rellenara el hueco sería un sistema que sabe mentir.

### `Vinificacion`: el proceso se registra y no se toca

`tests/Feature/Vinificacion/EtapasDeVinificacionTest.php`

| Prueba | Qué comprueba |
|---|---|
| `test_abrir_un_lote_registra_la_recepcion_de_la_uva` | La primera etapa sola |
| `test_no_se_puede_saltar_una_etapa_obligatoria` | El orden |
| `test_una_etapa_no_se_registra_dos_veces` | Una etapa, una vez |
| `test_una_etapa_no_puede_tener_fecha_de_futuro` | La fecha es real |
| `test_un_vino_sin_barrica_se_puede_embotellar_igual` | Las opcionales no bloquean |
| `test_se_puede_registrar_la_garupa_en_barrica` | La opcional sí se puede |
| `test_una_etapa_registrada_no_se_puede_editar_en_la_base` | **El `UPDATE` falla** |
| `test_una_etapa_registrada_no_se_puede_borrar_en_la_base` | **El `DELETE` falla** |
| `test_un_lote_descartado_queda_a_la_vista_con_su_motivo` | La pérdida se registra |
| `test_un_lote_descartado_no_acepta_mas_etapas` | Un lote perdido está cerrado |
| `test_una_vendimia_no_se_vinifica_dos_veces` | Una vendimia, un lote |
| `test_al_abrir_el_lote_la_vendimia_pasa_a_bodega` | Se mueven juntos |

Las dos del medio van **contra la base de datos directamente**, no por la
aplicación. La garantía tiene que estar en la base: si dependiera de que la
aplicación no mande un `UPDATE`, bastaría una consulta a mano.

### `Campo`: la fecha de vendimia

`tests/Feature/Campo/FechaDeVendimiaTest.php`

| Prueba | Qué comprueba |
|---|---|
| `test_la_fecha_se_puede_corregir_si_todavia_no_hay_lote` | Corregir es legítimo antes del lote |
| `test_la_fecha_no_se_puede_cambiar_cuando_ya_hay_lote` | **El `UPDATE` falla** |
| `test_la_api_rechaza_una_vendimia_con_fecha_de_futuro` | No se cosecha mañana |
| `test_el_lote_toma_el_anio_de_la_vendimia` | El año viene de la vendimia |
| `test_una_vendimia_descartada_no_se_vinifica` | No se procesa lo descartado |
| `test_una_vendimia_sin_analisis_guarda_null_y_no_cero` | `null` no es `0` |

### `Ventas`: donde la trazabilidad llega al vino vendido

`tests/Feature/Ventas/VentasTest.php`

| Prueba | Qué comprueba |
|---|---|
| `test_la_venta_apunta_al_embotellado_y_arrastra_la_trazabilidad` | El origen viaja con la venta |
| `test_no_se_venden_mas_botellas_de_las_que_hay` | No se vende de más |
| `test_no_se_vende_vino_de_un_lote_que_no_esta_embotellado` | Solo vino embotellado |
| `test_una_venta_anulada_devuelve_las_botellas` | Anular devuelve el vino |
| `test_cobrar_cierra_la_venta` | El cobro cierra |
| `test_una_venta_anulada_no_se_puede_cobrar` | No se cobra lo anulado |
| `test_una_venta_sin_botellas_se_rechaza` | Una venta sin botellas no es una venta |
| `test_una_venta_abierta_demasiado_tiempo_no_se_cobra` | La caja no queda abierta |
| `test_el_endpoint_de_disponibles_dice_la_trazabilidad` | Lo que el mostrador ve |

## Los ayudantes de las pruebas

`tests/TestCase.php` aporta tres cosas que hay que entender antes de escribir
una prueba nueva:

### `comoUsuario()`: sesión Y contexto de viña

```php
$this->comoUsuario($vina['bodega']);
```

`actingAs()` por sí solo **no** fija el contexto: el middleware
`ResolverTenant` corre dentro del grupo `web`, que las pruebas no ejecutan. Sin
fijar el contexto, la RLS no tendría contra qué filtrar y las pruebas de
aislamiento no probarían nada.

### `loteCompleto()`: el lote por las etapas reales

```php
$completo = $this->loteCompleto($vina, botellas: 12);
```

Lleva un lote hasta embotellado **paso a paso con el servicio real**, no
insertándolo ya embotellado. Un lote embotellado a propósito no es un lote: es
un atajo que ninguna prueba de proceso debería usar.

Si el servicio dejara pasar una etapa, esta función se caería, y con ella
todas las pruebas que necesitan un lote listo. Es una comprobación gratuita.

### `esperarFalloDeBase()`

```php
$this->esperarFalloDeBase(function () use ($etapa) {
    DB::table('etapas_vinificacion')->where('id', $etapa->id)->update([...]);
});
```

Espera un `QueryException` y falla con un mensaje claro si la operación
pasó. Se usa para probar los gatillos.

## Lo que hay que correr primero

En este orden, y **anotando el resultado**:

1. **`php artisan migrate` sobre una base vacía.** Si una migración falla, el
   resto de las pruebas van a fallar por razones que no son suyas.
2. **`php artisan test`.** La salida esperada es todo en verde.
3. **`php artisan vina:verificar-aislamiento`.** Debe decir `AISLAMIENTO: OK`.
4. **`php artisan db:seed`.** Debe crear la temporada de ejemplo.
5. **Levantar la interfaz y recorrer el camino**: campo → vendimia → bodega →
   etapas → embotellar → trazar → vender.

Si algo de eso falla, el arreglo va primero. Un sistema de trazabilidad cuyas
pruebas pasan pero que no arranca es peor que uno que no existe, porque da una
confianza falsa.

## Lo que NO está probado

Honestidad sobre los huecos:

- **Los controladores no tienen pruebas de HTTP una por una.** Hay pruebas de
  los servicios y de las rutas más importantes (trazabilidad, embotellado,
  cobro), pero no de cada endpoint.
- **No hay pruebas del middleware** `AuditarOperacion`, ni de
  `ClientDeAuthentik` (que necesita un proveedor real o un simulacro).
- **No hay pruebas de la interfaz.** El frontend no tiene pruebas
  automáticas. La validación es manual.
- **No hay pruebas de los scripts de respaldo y restauración.** Solo se
  comprobó la sintaxis con `bash -n`.
- **No hay prueba de carga.** No se sabe cómo se comporta con una viña que
  tenga muchas temporadas, ni con un embotellado de cien mil botellas.

## Lo que falta antes de producción

- **Certificado TLS**: descomentar el bloque del `Caddyfile` con el dominio
  real.
- **Fijar las imágenes por digest SHA256**: hoy están por tag exacto.
- **Monitoreo y alertas**: no hay. Si la base se cae, nadie se entera hasta
  que alguien intenta vender.
- **Rotación de secretos**: no hay procedimiento.
- **Copia de respaldos fuera del servidor**: ver
  [08-RESPALDOS.md](08-RESPALDOS.md).
- **El contraste con la viña real**: el modelo se diseñó a partir de cómo
  funciona una bodega chica, pero **nadie de una viña lo ha revisado**. Las
  reglas que necesitan confirmación están listadas en
  [05-CONFIGURACION.md](05-CONFIGURACION.md) y en
  [00-INDICE.md](00-INDICE.md).

## La decisión de diseño más discutible

**Un lote, un embotellado.**

La etapa `embotellado` es única por lote, así que un lote sale a botella una
sola vez. Para una bodega que embotella en varios formatos desde el mismo lote
(750 y 375, por ejemplo), esto es una limitación real: habría que abrir
distintos lotes desde la misma vendimia, y una vendimia da un solo lote.

La razón de la decisión actual: es lo que hace que la trazabilidad sea simple
de leer. Un lote es un camino y tiene un final.

**Si la viña embotella en varios formatos**, hay que cambiar el modelo y la
decisión queda invertida. Eso es una pregunta para la viña, no una decisión
técnica.