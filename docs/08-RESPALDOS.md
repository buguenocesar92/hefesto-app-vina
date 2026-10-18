# 08. Respaldos

## Las seis reglas

No son preferencias de estilo. Cada una existe porque lo contrario ya costó
algo en algún sistema:

1. **Manifiesto con SHA-256** de cada archivo. Sirve para saber si el respaldo
   está completo y para detectar corrupción antes de restaurar.
2. **Marca de completitud al final.** Se escribe después del manifiesto. Un
   respaldo sin la marca está incompleto aunque tenga todos los archivos.
3. **Validar rutas y espacio antes de escribir.** Se comprueba el destino y
   el espacio libre antes de volcar nada.
4. **Rechazar destino ocupado** salvo que se pase `--force`.
5. **Nunca `DROP`, nunca sobrescritura automática.** No hay una sola sentencia
   `DROP` en los scripts.
6. **Dejar el servicio detenido tras restaurar.** Es lo que evita que la
   aplicación escriba sobre una base a medio restaurar.

## El respaldo

```bash
cd apps/vina/deploy
./respaldos/respaldo.sh
```

Se genera en `deploy/respaldos/vina-<fecha>-<hora>/`:

```
vina-20260118-030000/
├── vina-20260118-030000.dump   <- el respaldo
├── manifesto.txt                <- SHA-256 y datos del respaldo
└── .completo                    <- la marca de que terminó bien
```

### Qué contiene

**Toda la base**, no un subconjunto de tablas.

Lo que hay que recuperar de una bodega no son solo tablas: son las fechas de
vendimia y las etapas de vinificación. Un respaldo que restaura las parcelas
pero no las etapas deja a la bodega sin poder responder de qué parcela es un
vino, que es exactamente para lo que está el sistema.

### El formato

`pg_dump -Fc` (formato propio de PostgreSQL). Es comprimido y permite
restaurar con `pg_restore` sin depender de que el servidor destino tenga
exactamente la misma versión.

## Verificar un respaldo

**Antes de confiar en él:**

```bash
cd deploy/respaldos/vina-20260118-030000
sha256sum -c manifiesto.txt
```

Y comprobar que tiene la marca:

```bash
ls -la .completo
```

Si no hay `.completo`, el proceso se cayó y **no hay que restaurar**. Esa
regla existe porque un respaldo a medias se ve como uno bueno hasta que se
intenta restaurar.

## Restaurar

```bash
cd apps/vina/deploy
./respaldos/restauracion.sh respaldos/vina-20260118-030000
```

El script, en orden:

1. Verifica que exista la marca de completitud. Si no, se niega a seguir.
2. Verifica los SHA-256. Si no calzan, se niega a seguir.
3. Detiene la API y la interfaz.
4. Vacía las tablas con `TRUNCATE ... CASCADE`, en una sola transacción.
5. Restaura con `pg_restore --clean --if-exists --single-transaction`.
6. **Deja el servicio DETENIDO**.

### Por qué la restauración es más delicada acá

Las etapas de vinificación son **inmutables**: hay un gatillo en la base que
rechaza cualquier `UPDATE` o `DELETE` sobre `etapas_vinificacion`.

Por eso la restauración va con `--clean --if-exists` y `--single-transaction`:
el `pg_restore` no debe intentar ejecutar un `DROP` o un `UPDATE` que choque
con los gatillos, y todo tiene que pasar o no pasar.

Por eso se usa `TRUNCATE` y no `DROP`: `TRUNCATE` vacía las tablas y deja
intacta la estructura, el propietario y los permisos. Un `DROP` eliminaría la
tabla y habría que volver a crearla con el propietario correcto, que es donde
aparecen los problemas de permisos después de restaurar.

### Por qué el servicio queda detenido

Una aplicación que vuelve a levantar contra una base a medio restaurar escribe
sobre datos incompletos. Y el problema original queda duplicado: ahora no
sabemos si lo que falta se perdió en el respaldo o en la restauración.

Después de restaurar, revisar y luego levantar a mano:

```bash
cd deploy/respaldos/vina-20260118-030000 && sha256sum -c manifesto.txt
docker compose -f docker-compose.prod.yml up -d
```

## Automatizarlo

Un cron en el host:

```bash
# Todos los días a las 03:00.
0 3 * * * cd /ruta/apps/vina/deploy && ./respaldos/respaldo.sh --etiqueta automatico >> /var/log/vina-respaldo.log 2>&1
```

La etiqueta sirve para distinguir los automáticos de los manuales.

## La copia fuera del servidor

**Un respaldo en el mismo servidor no es un respaldo.**

Si el servidor se cae, se apaga, o alguien entra a borrarlo, se pierden el
respaldo y los datos juntos. El respaldo tiene que estar **en otro lugar**.

La opción más simple y la que alcanza para una viña chica: una copia por SSH
a otra máquina o a un disco externo, con el mismo `sha256sum -c`.

Sin eso, el respaldo protege contra una equivocación, pero no contra una
pérdida del servidor. Y una pérdida del servidor es el caso que más importa.

## La rotación

Los respaldos automáticos se acumulan. Hay que borrarlos **a mano**, revisando
que el último esté completo.

No hay rotación automática a propósito: un script que borra respaldos es un
script que puede borrar el último respaldo bueno el día que el disco se llena.
El espacio se revisa antes de escribir (regla 3), y si no alcanza, el respaldo
se niega a empezar en vez de escribirse a medias.

## Lo que un respaldo NO protege

- **Un borrado accidental seguido de una restauración.** Si alguien vacía una
  tabla y el respaldo diario es de hace 24 horas, se perdió un día entero.
- **Un error de lógica.** Si el sistema aceptó una vendimia con la fecha
  incorrecta antes de que el lote existiera, el respaldo la contiene tal cual.
- **Un acceso indebido.** Si alguien leyó datos que no debía, el respaldo los
  tiene.
- **El código.** Los respaldos son de la base de datos, no del repositorio.

La primera es la más grave en el uso diario, y la razón por la que el respaldo
no es automático: una restauración automática puede ser peor que esperar.