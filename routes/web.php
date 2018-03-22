<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});
Route::group(['prefix' => 'manage'], function () {
    Route::group(['prefix' => 'front', 'namespace' => 'Front'], function() {
        Route::get('index', 'IndexController@indexView');// 首页视图
        Route::group(['prefix' => 'api'], function () {
            Route::post('index', 'IndexController@index');//首页接口
        });
    });
});

    
