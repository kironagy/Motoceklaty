<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\TeamController;
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
use Illuminate\Support\Facades\Schedule;
Route::post('/push-subscriptions', [PushSubscriptionController::class, 'store'])
    ->name('push-subscriptions.store');

Route::delete('/push-subscriptions', [PushSubscriptionController::class, 'destroy'])
    ->name('push-subscriptions.destroy');
// web.php
Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/session/csrf', fn () => response()->json(['token' => csrf_token()])
    ->header('Cache-Control', 'no-store, private'))->middleware('throttle:60,1')->name('session.csrf');

Route::match(['get', 'post'], '/track', [\App\Http\Controllers\TrackingController::class, 'index'])->middleware('throttle:20,1')->name('tracking.index');

// Dynamic Links: the customer's application form (no login - the 40-char token is the key)
Route::prefix('f/{token}')->where(['token' => '[A-Za-z0-9]{32,64}'])->controller(\App\Http\Controllers\FormLinkController::class)->group(function () {
    Route::get('/', 'show')->name('form-links.show');
    Route::middleware('throttle:120,1')->group(function () {
        Route::post('/ping', 'ping');
        Route::post('/answer', 'answer');
        Route::get('/plans', 'plans');
        Route::get('/files', 'files');
        Route::get('/files/{file}', 'fileContent')->whereNumber('file');
    });
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/files', 'upload');
        Route::delete('/files/{file}', 'deleteFile')->whereNumber('file');
        Route::post('/submit', 'submit');
    });
});

Route::get('/machines', [MachineController::class, 'index'])->name('machines.index');
Route::get('/machines/{id}', [MachineController::class, 'show'])->name('machines.show');

Route::get('/installment', [InstallmentController::class, 'index'])->name('installment.index');
Route::post('/installment/calculate', [InstallmentController::class, 'calculate'])
    ->middleware('throttle:60,1')
    ->name('installment.calculate');

Route::get('/brands', [BrandController::class, 'index'])->name('brands.index');
Route::get('/brands/{id}', [BrandController::class, 'show'])->name('brands.show');

Route::get('/about', [AboutController::class, 'index'])->name('about.index');
Route::post('/installments', [InstallmentRequestController::class, 'store'])->name('installments.store');

Route::get('/apply-installment', [ApplyInstallmentController::class, 'index'])
    ->name('installments.apply.form');

Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/{post:slug}', [BlogController::class, 'show'])->name('blog.show');
Route::post('/blog/{post:slug}/comments', [BlogController::class, 'comment'])
    ->middleware('throttle:5,1')
    ->name('blog.comment');

Route::get('/team', [TeamController::class, 'index'])->name('team.index');

Route::get('/contact', [ContactController::class, 'index'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('contact.store');

Route::post('/newsletter', [NewsletterController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('newsletter.store');


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



Schedule::command('requests:transfer-expired-paused')
    ->everyFiveMinutes();









Route::get('/staff/media/{messageMedia}', [\App\Http\Controllers\MessageMediaController::class, 'show'])
    ->middleware('auth:filament')
    ->name('staff.media.show');

















require __DIR__.'/finance.php';
