<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AiController;

Route::post('/ai/upload', [AiController::class, 'upload']);
Route::post('/ai/ask', [AiController::class, 'ask']);
Route::post('/ai/summarize', [AiController::class, 'summarize']);
