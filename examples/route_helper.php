<?php
use Route\Route;

require 'src/Route.php';
require 'src/RouteHelper.php';

Route::get('/users', 'UserController@index')
    ->name('users.list')
    ->middleware(['auth'])
    ->with('description', 'Displays all users');
