<?php

use App\Livewire\Demo;
use App\Http\Controllers\DemoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/up', fn () => response()->json(['status' => 'ok']));

Route::get('/demo', Demo::class)->name('demo');

Route::get('/demo/health', [DemoController::class, 'health'])->name('demo.health');
Route::get('/demo/stats', [DemoController::class, 'stats'])->middleware('throttle:60,1')->name('demo.stats');

Route::match(['get', 'post'], '/demo/documents', [DemoController::class, 'documents'])->middleware('throttle:20,1')->name('demo.documents');
Route::delete('/demo/documents/{documentId}', [DemoController::class, 'destroyDocument'])->middleware('throttle:20,1')->name('demo.documents.destroy');

Route::post('/demo/chat', [DemoController::class, 'chat'])->middleware('throttle:30,1')->name('demo.chat');
