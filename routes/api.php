<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\PhotoController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\StaffAppointmentController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\StaffTimeOffController;
use App\Http\Controllers\Api\StaffWorkingHourController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\Admin\StaffManagementController;
use App\Http\Controllers\Api\SuperAdmin\CompanyManagementController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
});

Route::middleware('auth:sanctum')->get('/me', [AuthController::class, 'me']);

Route::get('/staff', [StaffController::class, 'index']);
Route::get('/companies', [CompanyController::class, 'index']);
Route::get('/services', [ServiceController::class, 'index']);
Route::get('/services/{service}', [ServiceController::class, 'show']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);
Route::get('/availability', [AvailabilityController::class, 'index']);
Route::get('/media', [MediaController::class, 'index']);

Route::middleware(['auth:sanctum', 'role:staff,admin'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::post('/services', [ServiceController::class, 'store']);
    Route::put('/services/{service}', [ServiceController::class, 'update']);
    Route::delete('/services/{service}', [ServiceController::class, 'destroy']);
    Route::post('/services/{service}/photo', [PhotoController::class, 'service']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{product}', [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);
    Route::get('/staff/appointments', [StaffAppointmentController::class, 'index']);
    Route::put('/staff/appointments/{appointment}', [StaffAppointmentController::class, 'update']);
    Route::post('/staff/appointments/{appointment}/cancel', [StaffAppointmentController::class, 'cancel']);
    Route::post('/staff/appointments/{appointment}/status', [StaffAppointmentController::class, 'updateStatus']);
    Route::delete('/staff/appointments/{appointment}', [StaffAppointmentController::class, 'destroy']);
    Route::get('/staff/working-hours', [StaffWorkingHourController::class, 'index']);
    Route::post('/staff/working-hours', [StaffWorkingHourController::class, 'store']);
    Route::put('/staff/working-hours/{workingHour}', [StaffWorkingHourController::class, 'update']);
    Route::delete('/staff/working-hours/{workingHour}', [StaffWorkingHourController::class, 'destroy']);
    Route::get('/staff/time-off', [StaffTimeOffController::class, 'index']);
    Route::post('/staff/time-off', [StaffTimeOffController::class, 'store']);
    Route::delete('/staff/time-off/{timeOff}', [StaffTimeOffController::class, 'destroy']);
    Route::post('/staff/{user}/photo', [PhotoController::class, 'staff']);
    Route::post('/upload', [UploadController::class, 'store']);
    Route::post('/products/{product}/photo', [PhotoController::class, 'product']);
    Route::delete('/media/{media}', [MediaController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/staff', [StaffManagementController::class, 'index']);
    Route::post('/staff', [StaffManagementController::class, 'store']);
    Route::put('/staff/{user}', [StaffManagementController::class, 'update']);
    Route::delete('/staff/{user}', [StaffManagementController::class, 'destroy']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/companies/my', [CompanyController::class, 'my']);
    Route::get('/appointments', [AppointmentController::class, 'index']);
    Route::post('/appointments', [AppointmentController::class, 'store']);
    Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);
    Route::post('/me/photo', [ProfileController::class, 'uploadPhoto']);
    Route::post('/me/password', [ProfileController::class, 'updatePassword']);
});

Route::middleware(['auth:sanctum', 'global_role:super_admin'])->prefix('super-admin')->group(function () {
    Route::get('/companies', [CompanyManagementController::class, 'companiesIndex']);
    Route::post('/companies', [CompanyManagementController::class, 'companiesStore']);
    Route::put('/companies/{company}', [CompanyManagementController::class, 'companiesUpdate']);
    Route::delete('/companies/{company}', [CompanyManagementController::class, 'companiesDestroy']);

    Route::get('/companies/{company}/memberships', [CompanyManagementController::class, 'membershipsIndex']);
    Route::post('/companies/{company}/memberships', [CompanyManagementController::class, 'membershipsStore']);
    Route::put('/companies/{company}/memberships/{membership}', [CompanyManagementController::class, 'membershipsUpdate']);
    Route::delete('/companies/{company}/memberships/{membership}', [CompanyManagementController::class, 'membershipsDestroy']);

    Route::get('/users', [CompanyManagementController::class, 'usersIndex']);
    Route::post('/users', [CompanyManagementController::class, 'usersStore']);
});
