<?php

use Illuminate\Support\Facades\Route;

// Panel web (SPA ligera en JS puro que consume /api). No requiere compilar con Vite.
Route::view('/', 'panel')->name('panel');
