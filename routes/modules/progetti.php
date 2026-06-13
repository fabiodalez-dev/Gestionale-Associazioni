<?php

use App\Http\Controllers\Progetti\MilestoneController;
use App\Http\Controllers\Progetti\ProgettoController;
use App\Http\Controllers\Progetti\ReportProgettoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modulo: progetti (piano "Organizzazione 3", sezione C — f.12–f.16)
|--------------------------------------------------------------------------
|
| CRUD progetti con pagina dettaglio (f.12) che aggrega rendiconto della
| prima nota (f.13), eventi e turni collegati (f.14) e milestone (f.16),
| più export del report PDF intestato (f.15). Area admin: auth + tenant,
| famiglia permessi struttura.* (ProgettoPolicy).
|
*/

Route::middleware(['auth', 'tenant'])->group(function (): void {
    // f.12 — CRUD progetti + dettaglio
    Route::get('/progetti', [ProgettoController::class, 'index'])->name('progetti.index');
    Route::get('/progetti/nuovo', [ProgettoController::class, 'create'])->name('progetti.create');
    Route::post('/progetti', [ProgettoController::class, 'store'])->name('progetti.store');
    Route::get('/progetti/{progetto}', [ProgettoController::class, 'show'])->name('progetti.show');
    Route::get('/progetti/{progetto}/modifica', [ProgettoController::class, 'edit'])->name('progetti.edit');
    Route::put('/progetti/{progetto}', [ProgettoController::class, 'update'])->name('progetti.update');
    Route::delete('/progetti/{progetto}', [ProgettoController::class, 'destroy'])->name('progetti.destroy');

    // f.15 — report progetto PDF intestato
    Route::get('/progetti/{progetto}/report-pdf', ReportProgettoController::class)->name('progetti.report-pdf');

    // f.16 — milestone (checklist con barra avanzamento) dentro il dettaglio
    Route::post('/progetti/{progetto}/milestone', [MilestoneController::class, 'store'])->name('progetti.milestone.store');
    Route::put('/progetti/{progetto}/milestone/{milestone}', [MilestoneController::class, 'update'])->name('progetti.milestone.update');
    Route::post('/progetti/{progetto}/milestone/{milestone}/toggle', [MilestoneController::class, 'toggle'])->name('progetti.milestone.toggle');
    Route::delete('/progetti/{progetto}/milestone/{milestone}', [MilestoneController::class, 'destroy'])->name('progetti.milestone.destroy');
});
