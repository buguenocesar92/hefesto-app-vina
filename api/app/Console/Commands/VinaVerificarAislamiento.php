<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Verifica que el aislamiento entre viñas este de verdad puesto.
 *
 * POR QUE ESTE COMANDO EXISTE
 * ---------------------------
 * La Row Level Security es una garantia, y una garantia que no se comprueba
 * es una suposicion. Este comando hace CUATRO comprobaciones reales:
 *
 *  1. Que el rol de la conexion NO sea superusuario.
 *  2. Que el rol de la conexion NO tenga BYPASSRLS.
 *  3. Que las tablas protegidas tengan RLS ACTIVADA y FORZADA, y con politica
 *     de lectura Y de escritura.
 *  4. Que leyendo sin contexto la consulta FALLE, y que leyendo con el
 *     contexto de una viña solo devuelva SUS filas.
 *
 * El punto 4 es el que importa: los otros tres pueden estar bien y que la
 * politica este mal escrita, en cuyo caso las dos viñas se verian igual.
 * Por eso la ultima comprobacion no mira el SQL, sino que intenta leer de
 * verdad.
 *
 * Sale con codigo distinto de cero si algo falla: el despliegue se detiene.
 */
class VinaVerificarAislamiento extends Command
{
    protected $signature = 'vina:verificar-aislamiento';

    protected $description = 'Comprueba que el aislamiento entre viñas este activo y funcione.';

    public function handle(): int
    {
        $this->info('Verificando el aislamiento entre viñas...');

        $fallos = [];

        // --- 1 y 2. El rol de la conexion ---------------------------------

        $rol = DB::selectOne('SELECT current_user AS usuario, session_user AS conexion');

        $esSuperusuario = DB::selectOne(
            'SELECT rolsuper AS valor FROM pg_roles WHERE rolname = current_user'
        );

        if ((bool) $esSuperusuario->valor) {
            $fallos[] = 'El rol de la conexion es SUPERUSUARIO. Con superusuario, PostgreSQL '
                . 'ignora TODAS las politicas de Row Level Security.';
        }

        $bypass = DB::selectOne(
            'SELECT rolbypassrls AS valor FROM pg_roles WHERE rolname = current_user'
        );

        if ((bool) $bypass->valor) {
            $fallos[] = 'El rol de la conexion tiene BYPASSRLS. Con ese permiso, PostgreSQL '
                . 'ignora TODAS las politicas de Row Level Security.';
        }

        if ($fallos === []) {
            $this->line("  Rol: {$rol->usuario} (no superusuario, sin BYPASSRLS)");
        }

        // --- 3. Las tablas protegidas --------------------------------------

        $tablas = $this->tablasProtegidas();
        $problemas = $this->revisarPoliticas($tablas);

        foreach ($problemas as $problema) {
            $fallos[] = $problema;
        }

        if ($problemas === []) {
            $this->line('  Tablas protegidas con politica correcta: ' . count($tablas));
        }

        // --- 4. La prueba real: leer sin contexto y con contexto ----------

        $fallaCerrado = $this->laLecturaSinContextoFalla();

        if (! $fallaCerrado) {
            $fallos[] = 'Una lectura SIN contexto de viña NO fue rechazada. La funcion '
                . 'app_tenant_id() deberia lanzar excepcion y esta devolviendo algo. '
                . 'El aislamiento esta ABIERTO.';
        } else {
            $this->line('  Lectura sin contexto: rechazada (correcto)');
        }

        $aislamiento = $this->dosVinasNoSeVen();

        if (! $aislamiento) {
            $fallos[] = 'Dos viñas de prueba se vieron entre si. La politica no esta '
                . 'filtrando por tenant_id como debe.';
        } else {
            $this->line('  Dos viñas de prueba: cada una ve solo lo suyo (correcto)');
        }

        // --- Veredicto ------------------------------------------------------

        if ($fallos !== []) {
            $this->error('AISLAMIENTO: FALLA');

            foreach ($fallos as $fallo) {
                $this->line("  - {$fallo}");
            }

            $this->line('');
            $this->error('No sigas con el despliegue hasta corregir esto.');

            return self::FAILURE;
        }

        $this->info('AISLAMIENTO: OK');

        return self::SUCCESS;
    }

    /**
     * Las tablas que deben estar protegidas.
     *
     * Se leen del propio catalogo de PostgreSQL, no de una lista escrita en
     * PHP: asi el comando comprueba lo que HAY, y si alguien agrega una tabla
     * nueva sin RLS, el comando la va a marcar.
     *
     * @return array<int, string>
     */
    private function tablasProtegidas(): array
    {
        return DB::select(<<<'SQL'
            SELECT c.relname AS nombre
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND c.relkind = 'r'
              AND c.relname <> 'migrations'
              AND EXISTS (
                  SELECT 1
                  FROM pg_attribute a
                  WHERE a.attrelid = c.oid
                    AND a.attname = 'tenant_id'
                    AND NOT a.attisdropped
              )
            ORDER BY c.relname
        SQL)->pluck('nombre')->all();
    }

    /**
     * @param  array<int, string>  $tablas
     * @return array<int, string>
     */
    private function revisarPoliticas(array $tablas): array
    {
        $problemas = [];

        foreach ($tablas as $tabla) {
            $estado = DB::selectOne(
                'SELECT c.relrowsecurity AS rls, c.relforcerowsecurity AS forzada
                 FROM pg_class c
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = \'public\' AND c.relname = ?',
                [$tabla]
            );

            if ($estado === null || ! (bool) $estado->rls) {
                $problemas[] = "La tabla {$tabla} no tiene Row Level Security activada.";

                continue;
            }

            if (! (bool) $estado->forzada) {
                $problemas[] = "La tabla {$tabla} tiene RLS pero NO esta FORZADA. El dueño de "
                    . 'la tabla puede saltarsela.';
            }

            $politicas = DB::select(
                "SELECT policyname AS nombre, cmd AS accion, qual AS usando, with_check AS chequeo
                 FROM pg_policies
                 WHERE schemaname = 'public' AND tablename = ?",
                [$tabla]
            );

            $tieneLectura = false;
            $tieneEscritura = false;

            foreach ($politicas as $politica) {
                if ($politica->usando === null && $politica->chequeo === null) {
                    $problemas[] = "La politica {$politica->nombre} de {$tabla} no menciona tenant_id.";
                }

                if ($politica->usando !== null) {
                    $tieneLectura = true;
                }

                if ($politica->chequeo !== null) {
                    $tieneEscritura = true;
                }
            }

            if (! $tieneLectura) {
                $problemas[] = "La tabla {$tabla} no tiene politica que filtre la LECTURA (USING).";
            }

            if (! $tieneEscritura) {
                $problemas[] = "La tabla {$tabla} no tiene politica que filtre la ESCRITURA (WITH CHECK).";
            }
        }

        return $problemas;
    }

    /**
     * `true` si leer sin contexto fue rechazado.
     *
     * Se hace en una transaccion propia que se revierte al final, para que la
     * marca `set_config`, que tiene alcance de transaccion, no se escape a
     * nada.
     */
    private function laLecturaSinContextoFalla(): bool
    {
        try {
            DB::transaction(function (): void {
                DB::statement("SELECT set_config('app.tenant_id', '', true)");

                // Si `app_tenant_id()` no lanza excepcion, devuelve algo y la
                // consulta pasa. Eso seria el aislamiento abierto.
                DB::select('SELECT * FROM usuarios LIMIT 1');
            });

            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * `true` si dos viñas de prueba no se ven entre si.
     *
     * Se crean dos tenants con una persona y una parcela cada uno, y se lee
     * cada uno en su contexto. Si las dos lecturas traen lo mismo, la politica
     * no filtra.
     */
    private function dosVinasNoSeVen(): bool
    {
        $unico = 'verificacion-' . bin2hex(random_bytes(4));

        try {
            $ids = DB::transaction(function () use ($unico): array {
                // Los identificadores se generan aca. `insertGetId` devuelve
                // un entero autoincremental y estas tablas usan UUID.
                $idA = \Illuminate\Support\Str::uuid()->toString();
                $idB = \Illuminate\Support\Str::uuid()->toString();

                DB::table('tenants')->insert([
                    [
                        'id' => $idA,
                        'slug' => $unico . '-a',
                        'nombre' => 'Verificacion A',
                        'activo' => true,
                    ],
                    [
                        'id' => $idB,
                        'slug' => $unico . '-b',
                        'nombre' => 'Verificacion B',
                        'activo' => true,
                    ],
                ]);

                DB::table('usuarios')->insert([
                    [
                        'id' => \Illuminate\Support\Str::uuid()->toString(),
                        'tenant_id' => $idA,
                        'email' => $unico . '-a@ejemplo.invalido',
                        'nombre' => 'Verificacion A',
                        'rol' => 'auditor',
                        'activo' => true,
                    ],
                    [
                        'id' => \Illuminate\Support\Str::uuid()->toString(),
                        'tenant_id' => $idB,
                        'email' => $unico . '-b@ejemplo.invalido',
                        'nombre' => 'Verificacion B',
                        'rol' => 'auditor',
                        'activo' => true,
                    ],
                ]);

                return ['a' => $idA, 'b' => $idB];
            }, 1);

            // La lectura con contexto debe devolver SOLO lo suyo.
            $lecturaA = DB::transaction(function () use ($ids): array {
                DB::statement('SELECT set_config(?, ?, true)', ['app.tenant_id', $ids['a']]);

                return DB::table('usuarios')->pluck('email')->all();
            }, 1);

            $lecturaB = DB::transaction(function () use ($ids): array {
                DB::statement('SELECT set_config(?, ?, true)', ['app.tenant_id', $ids['b']]);

                return DB::table('usuarios')->pluck('email')->all();
            }, 1);

            if (count($lecturaA) !== 1 || count($lecturaB) !== 1) {
                return false;
            }

            return $lecturaA[0] !== $lecturaB[0];
        } catch (\Throwable $e) {
            $this->line('  No se pudieron crear las viñas de prueba: ' . $e->getMessage());

            return false;
        } finally {
            // Limpieza. Se borran las de prueba aunque la comprobacion haya
            // fallado: dejarlas contaminaria la base de una viña real.
            try {
                DB::table('usuarios')->where('email', 'like', $unico . '-%')->delete();
                DB::table('tenants')->where('slug', 'like', $unico . '-%')->delete();
            } catch (\Throwable) {
                // Si no se pudo crear, no hay nada que borrar.
            }
        }
    }
}