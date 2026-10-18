<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\LoteController;
use App\Http\Controllers\ParcelaController;
use App\Http\Controllers\SaludController;
use App\Http\Controllers\TrazabilidadController;
use App\Http\Controllers\VendimiaController;
use App\Http\Controllers\VentaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API de la viña
|--------------------------------------------------------------------------
|
| Las rutas viven en el grupo `web` de Laravel a proposito: la viña se opera
| desde una interfaz servida por Nuxt, que actua como servidor intermedio
| (BFF). Asi la cookie de sesion viaja en el mismo origen y el token CSRF se
| reenvia como cabecera `X-XSRF-TOKEN`. Ver docs/07.
|
| Lo que es publico: el acceso, el callback, la salud y el selector de viñas.
| Nada mas. No hay endpoints de datos de negocio sin sesion.
|
*/

// --- Publico ---------------------------------------------------------------

Route::get('/api/v1/salud', SaludController::class)->name('salud');

/*
| El selector del acceso.
|
| Lee la vista `vw_vinas_publicas`, que expone SOLO tres columnas
| (slug, nombre, activo) y solo de las viñas activas. Esta es la
| compensacion de que la tabla `tenants` no lleve Row Level Security: antes
| de autenticarse no hay `app.tenant_id` que poner, asi que la lectura previa
| se hace contra la vista y no contra la tabla.
|
| La vista se USA de verdad: este endpoint es lo que la pantalla de entrada
| muestra, y el `slug` que devuelve es el que viaja en la sesion hasta el
| callback. Ver docs/03.
*/
Route::get('/api/v1/vinas', [AuthController::class, 'vinas'])->name('vinas');

Route::get('/api/v1/acceso', [AuthController::class, 'entrar'])->name('acceso');
Route::get('/api/v1/acceso/callback', [AuthController::class, 'callback'])->name('acceso.callback');

// --- Con sesion -----------------------------------------------------------

Route::middleware('auth')->group(function (): void {

    Route::post('/api/v1/salir', [AuthController::class, 'salir'])->name('salir');
    Route::get('/api/v1/yo', [AuthController::class, 'yo'])->name('yo');
    Route::get('/api/v1/yo/diagnostico', [AuthController::class, 'diagnostico'])->name('yo.diagnostico');

    // --- Parcelas ---------------------------------------------------------
    //
    // La parcela es el origen de toda la trazabilidad. Si el nombre esta mal,
    // todo el vino que salga de ahi quedara mal identificado.

    Route::get('/api/v1/parcelas', [ParcelaController::class, 'index'])->name('parcelas.index');
    Route::post('/api/v1/parcelas', [ParcelaController::class, 'store'])->name('parcelas.store');
    Route::get('/api/v1/parcelas/{parcela}', [ParcelaController::class, 'show'])->name('parcelas.show');
    Route::put('/api/v1/parcelas/{parcela}', [ParcelaController::class, 'update'])->name('parcelas.update');
    Route::post('/api/v1/parcelas/{parcela}/desactivar', [ParcelaController::class, 'desactivar'])
        ->name('parcelas.desactivar');

    // --- Vendimias --------------------------------------------------------
    //
    // La fecha de la vendimia es el origen de la trazabilidad de todo el vino.
    // Por eso no hay ruta para editarla una vez que hay un lote.

    Route::get('/api/v1/vendimias', [VendimiaController::class, 'index'])->name('vendimias.index');
    Route::post('/api/v1/vendimias', [VendimiaController::class, 'store'])->name('vendimias.store');
    Route::get('/api/v1/vendimias/{vendimia}', [VendimiaController::class, 'show'])->name('vendimias.show');

    // Mandar la uva al lagar. Aqui nace el lote, y las dos cosas ocurren en
    // la misma transaccion.
    Route::post('/api/v1/vendimias/{vendimia}/bodega', [VendimiaController::class, 'enviarABodega'])
        ->name('vendimias.bodega');

    Route::post('/api/v1/vendimias/{vendimia}/descartar', [VendimiaController::class, 'descartar'])
        ->name('vendimias.descartar');

    // --- Lotes y vinificacion ---------------------------------------------
    //
    // ESTE ES EL GRUPO MAS IMPORTANTE DE LA API. Registrar una etapa es la
    // UNICA forma de avanzar un lote: no hay ruta para editar una etapa ni
    // para borrarla, y en la base hay un gatillo que revienta los dos
    // intentos. Un lote mal criado no se puede deshacer.

    Route::get('/api/v1/lotes', [LoteController::class, 'index'])->name('lotes.index');
    Route::get('/api/v1/lotes/{lote}', [LoteController::class, 'show'])->name('lotes.show');

    Route::post('/api/v1/lotes/{lote}/etapas', [LoteController::class, 'registrarEtapa'])
        ->name('lotes.etapas');

    Route::post('/api/v1/lotes/{lote}/embotellar', [LoteController::class, 'embotellar'])
        ->name('lotes.embotellar');

    Route::post('/api/v1/lotes/{lote}/descartar', [LoteController::class, 'descartar'])
        ->name('lotes.descartar');

    // --- Trazabilidad -----------------------------------------------------
    //
    // "De que parcela y de que fecha de vendimia es este vino". Es la pregunta
    // que sostiene la promesa del sistema, y se responde LEYENDO el camino de
    // relaciones, sin deducirlo.

    Route::get('/api/v1/trazabilidad', [TrazabilidadController::class, 'buscar'])
        ->name('trazabilidad.buscar');
    Route::get('/api/v1/trazabilidad/parcelas/{parcela}', [TrazabilidadController::class, 'deParcela'])
        ->name('trazabilidad.parcela');
    Route::get('/api/v1/trazabilidad/lotes/{lote}', [TrazabilidadController::class, 'deLote'])
        ->name('trazabilidad.lote');
    Route::get('/api/v1/trazabilidad/embotellados/{embotellado}', [TrazabilidadController::class, 'deEmbotellado'])
        ->name('trazabilidad.embotellado');

    // --- Ventas -----------------------------------------------------------
    //
    // Cada venta apunta a un EMBOTELLADO, no a un lote. Es lo que permite
    // responder meses despues de que parcela era el vino que se vendio.

    Route::get('/api/v1/ventas', [VentaController::class, 'index'])->name('ventas.index');
    Route::post('/api/v1/ventas', [VentaController::class, 'store'])->name('ventas.store');
    Route::get('/api/v1/ventas/{venta}', [VentaController::class, 'show'])->name('ventas.show');
    Route::post('/api/v1/ventas/{venta}/pagar', [VentaController::class, 'pagar'])->name('ventas.pagar');
    Route::post('/api/v1/ventas/{venta}/anular', [VentaController::class, 'anular'])->name('ventas.anular');

    // Las botellas que quedan: lo que el mostrador mira antes de vender.
    Route::get('/api/v1/embotellados/{embotellado}/disponibles', [VentaController::class, 'disponibles'])
        ->name('embotellados.disponibles');

    // --- Configuracion ----------------------------------------------------

    Route::get('/api/v1/configuracion', [ConfiguracionController::class, 'resumen'])
        ->name('configuracion.resumen');

    Route::get('/api/v1/configuracion/personas', [ConfiguracionController::class, 'personas'])
        ->name('configuracion.personas');
    Route::post('/api/v1/configuracion/personas/{usuario}/rol', [ConfiguracionController::class, 'cambiarRol'])
        ->name('configuracion.personas.rol');
});