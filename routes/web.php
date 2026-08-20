<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\ApplyInstallmentController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InstallmentController;
use App\Http\Controllers\InstallmentRequestController;
use App\Http\Controllers\MachineController;
use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient as ClientImageAnnotatorClient;
use Illuminate\Support\Facades\Route;
//notifecation
use App\Http\Controllers\PushSubscriptionController;

Route::post('/push-subscriptions', [PushSubscriptionController::class, 'store'])
    ->name('push-subscriptions.store');

Route::delete('/push-subscriptions', [PushSubscriptionController::class, 'destroy'])
    ->name('push-subscriptions.destroy');
// web.php
Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/machines', [MachineController::class, 'index'])->name('machines.index');
Route::get('/machines/{id}', [MachineController::class, 'show'])->name('machines.show');

Route::get('/installment', [InstallmentController::class, 'index'])->name('installment.index');
Route::post('/installment/calculate', [InstallmentController::class, 'calculate'])->name('installment.calculate');

Route::get('/brands', [BrandController::class, 'index'])->name('brands.index');
Route::get('/brands/{id}', [BrandController::class, 'show'])->name('brands.show');

Route::get('/about', [AboutController::class, 'index'])->name('about.index');
Route::get('/machines/{machine}', [InstallmentRequestController::class, 'show'])->name('machines.show');
Route::post('/installments', [InstallmentRequestController::class, 'store'])->name('installments.store');

Route::get('/apply-installment', [ApplyInstallmentController::class, 'index'])
    ->name('installments.apply.form');


    use App\Http\Controllers\ReferralController;

Route::get('/{referral_code}', [ReferralController::class, 'handleReferral'])
    ->where('referral_code', 'em-[A-Za-z0-9\-]+');


use App\Models\Notification;

Route::post('/admin/notifications/read/{id}', function ($id) {
    Notification::where('id', $id)
        ->where('user_id', auth()->id())
        ->update(['is_read' => true]);

    return back();
})->name('notifications.markRead');


use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\Image;

Route::get('/test-vision', function () {
    $imagePath = storage_path('app/test.jpg'); // ضع الصورة هنا

    // إنشاء الكلينت باستخدام المفتاح
    $client = new ImageAnnotatorClient([
        'credentials' => base_path(env('GOOGLE_CLOUD_KEY_PATH')),
    ]);

    // قراءة الصورة كباينري
    $imageData = file_get_contents($imagePath);

    // ✅ استدعاء الطريقة الصحيحة لتحليل النص
    $response = $client->documentTextDetection($imageData);

    // الحصول على النتيجة
    $annotation = $response->getFullTextAnnotation();

    if ($annotation && $annotation->getText()) {
        // عرض النص المستخرج بالعربي
        return nl2br($annotation->getText());
    } else {
        return "❌ لم يتم التعرف على أي نص في الصورة.";
    }
});
Route::get('/ocr-test', function () {
    $path = storage_path('app/public/ids/3YD6JQ6NEzcSYuVbWLpWdBIcoJPrg8sWnzvGuM7i.jpg');
    $client = new ImageAnnotatorClient([
        'credentials' => base_path(env('GOOGLE_CLOUD_KEY_PATH')),
    ]);
    $image = file_get_contents($path);
    $response = $client->documentTextDetection($image);
    $annotation = $response->getFullTextAnnotation();
    dd($annotation?->getText());
});








Route::view('/contact', 'contact');
















