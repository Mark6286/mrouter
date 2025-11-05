<?php

use Route\Route;

require 'src/Route.php';

Route::get('/', fn() => 'Welcome to mrouter!');

Route::get('/user/{id}', function($id) {
    echo "User ID: $id";
});

Route::group('/admin', ['auth'], function() {
    Route::get('/dashboard', fn() => 'Admin Dashboard');
});

Route::registerMiddleware('auth', function() {
    if (!isset($_SESSION['user'])) {
        Route::redirect('/login');
        return false;
    }
});

Route::notFound(fn() => print('Custom 404 Page'));

Route::dispatch();
