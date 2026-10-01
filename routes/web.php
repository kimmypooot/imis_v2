<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function (Request $request) {
    if ($request->user()) {
        return redirect()->route('dashboard');
    }

    return Inertia::render('Home', [
        'appName' => config('app.name'),
    ]);
});

require __DIR__.'/auth.php';
require __DIR__.'/portal.php';
require __DIR__.'/admin.php';
