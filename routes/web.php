<?php

use App\Http\Controllers\Api\JobStatusController;
use App\Http\Controllers\BrandIdentityController;
use App\Http\Controllers\BrandLogoController;
use App\Http\Controllers\BrandProfileController;
use App\Http\Controllers\ContentGeneratorController;
use App\Http\Controllers\ContentPlanController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImageStudioController;
use App\Http\Controllers\MediaFolderController;
use App\Http\Controllers\PlatformSettingsController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\ScheduledPostController;
use App\Http\Controllers\StoreFactsController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware(['auth', 'brand.ready'])->group(function () {

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // تجهيز الحساب — الصفحة القديمة دُمجت في «هوية العلامة»؛ الرابط يبقى للإشارات المحفوظة
    Route::redirect('/brand/setup', '/brand/profile');
    // هوية العلامة: الإجابات وإعدادات الكتابة والأوصاف ونسخها
    Route::get('/brand/profile', [BrandProfileController::class, 'show'])->name('brand.profile');
    Route::put('/brand/profile/answers', [BrandProfileController::class, 'saveAnswers'])->name('brand.profile.answers');
    Route::put('/brand/profile/settings', [BrandProfileController::class, 'saveSettings'])->name('brand.profile.settings');
    // استدعاء نموذج لكل طلب — والمستخدم الجديد بلا رصيد يدفع منه، فالحد هنا لا في النقاط
    Route::post('/brand/profile/prefill', [BrandProfileController::class, 'prefill'])
        ->middleware('throttle:6,10')->name('brand.profile.prefill');
    Route::put('/brand/profile', [BrandProfileController::class, 'update'])->name('brand.profile.update');
    Route::delete('/brand/profile', [BrandProfileController::class, 'destroy'])->name('brand.profile.destroy');
    Route::post('/brand/profile/regenerate', [BrandProfileController::class, 'regenerate'])->name('brand.profile.regenerate');
    Route::post('/brand/profile/versions/{version}/restore', [BrandProfileController::class, 'restore'])
        ->whereNumber('version')->name('brand.profile.versions.restore');
    Route::delete('/brand/profile/versions/{version}', [BrandProfileController::class, 'destroyVersion'])
        ->whereNumber('version')->name('brand.profile.versions.destroy');

    // الهوية البصرية
    Route::get('/brand/identity', [BrandIdentityController::class, 'edit'])->name('brand.identity');
    Route::post('/brand/identity', [BrandIdentityController::class, 'update'])->name('brand.identity.update');
    Route::post('/brand/identity/patterns', [BrandIdentityController::class, 'storePatterns'])->name('brand.patterns.store');
    Route::delete('/brand/identity/patterns/{index}', [BrandIdentityController::class, 'destroyPattern'])
        ->whereNumber('index')->name('brand.patterns.destroy');

    Route::post('/brand/logos', [BrandLogoController::class, 'store'])->name('brand.logos.store');
    Route::patch('/brand/logos/{logo}', [BrandLogoController::class, 'update'])->name('brand.logos.update');
    Route::delete('/brand/logos/{logo}', [BrandLogoController::class, 'destroy'])->name('brand.logos.destroy');

    // حقائق البيع: التوصيل والدفع والعروض وتجارب العملاء — ما يجوز للمحتوى ذكره
    Route::get('/store/facts', [StoreFactsController::class, 'edit'])->name('store.facts');
    Route::put('/store/facts', [StoreFactsController::class, 'update'])->name('store.facts.update');
    Route::post('/store/offers', [StoreFactsController::class, 'storeOffer'])->name('store.offers.store');
    Route::patch('/store/offers/{offer}/toggle', [StoreFactsController::class, 'toggleOffer'])->name('store.offers.toggle');
    Route::delete('/store/offers/{offer}', [StoreFactsController::class, 'destroyOffer'])->name('store.offers.destroy');
    Route::post('/store/testimonials', [StoreFactsController::class, 'storeTestimonial'])->name('store.testimonials.store');
    Route::delete('/store/testimonials/{testimonial}', [StoreFactsController::class, 'destroyTestimonial'])->name('store.testimonials.destroy');

    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
    Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

    Route::post('/products/bulk', [ProductController::class, 'bulk'])->name('products.bulk');
    Route::post('/products/{product}/primary', [ProductController::class, 'togglePrimary'])->name('products.primary');
    Route::post('/products/{product}/duplicate', [ProductController::class, 'duplicate'])->name('products.duplicate');

    // استيراد المنتجات من متجر خارجي
    Route::post('/products/import/scan', [ProductImportController::class, 'scan'])->name('products.import.scan');
    Route::post('/products/import/single', [ProductImportController::class, 'single'])->name('products.import.single');
    Route::post('/products/import/{job}/more', [ProductImportController::class, 'more'])->name('products.import.more');
    Route::post('/products/import', [ProductImportController::class, 'store'])->name('products.import.store');
    Route::post('/products/summary', [ProductImportController::class, 'summary'])->name('products.summary');

    // كتابة المحتوى: ما يُكتب مسودة حتى يُضاف للخطة الشهرية
    Route::get('/content/generator', [ContentGeneratorController::class, 'index'])->name('content.generator');
    Route::post('/content/generator', [ContentGeneratorController::class, 'store'])->name('content.generate');
    Route::post('/content/{contentItem}/retry', [ContentGeneratorController::class, 'retry'])->name('content.retry');
    Route::post('/content/{contentItem}/add-to-plan', [ContentGeneratorController::class, 'addToPlan'])->name('content.add-to-plan');

    // الخطة الشهرية: تقويم النشر — جدولة داخلية، بلا نشر فعلي على المنصات بعد
    Route::get('/content/plan', [ContentPlanController::class, 'index'])->name('content.plan');
    Route::get('/content/plan/export', [ScheduledPostController::class, 'export'])->name('content.plan.export');
    Route::post('/content/schedule', [ScheduledPostController::class, 'store'])->name('scheduled.store');
    Route::post('/content/bulk-delete', [ScheduledPostController::class, 'bulkDestroy'])->name('content.bulk-delete');

    Route::get('/content/{contentItem}', [ContentPlanController::class, 'show'])->name('content.show');
    Route::put('/content/{contentItem}', [ContentPlanController::class, 'update'])->name('content.update');
    Route::delete('/content/{contentItem}', [ContentPlanController::class, 'destroy'])->name('content.destroy');
    Route::post('/content/{contentItem}/slides/{index}/rewrite', [ContentPlanController::class, 'rewriteSlide'])
        ->whereNumber('index')->name('content.slides.rewrite');

    // صناعة المحتوى
    Route::get('/studio', [ImageStudioController::class, 'index'])->name('studio.index');
    Route::post('/studio', [ImageStudioController::class, 'store'])->name('studio.generate');
    Route::post('/studio/carousel/{contentItem}', [ImageStudioController::class, 'carousel'])->name('studio.carousel');
    Route::post('/studio/{contentItem}/attach', [ImageStudioController::class, 'attach'])->name('studio.attach');
    Route::post('/studio/media/{mediaAsset}/regenerate', [ImageStudioController::class, 'regenerate'])->name('studio.regenerate');
    Route::post('/studio/uploads', [ImageStudioController::class, 'upload'])->name('studio.uploads');
    Route::post('/studio/enhance', [ImageStudioController::class, 'enhance'])
        ->middleware('throttle:20,1')->name('studio.enhance');
    Route::delete('/studio/media/{mediaAsset}', [ImageStudioController::class, 'destroy'])->name('studio.media.destroy');
    Route::post('/studio/media/{mediaAsset}/move', [ImageStudioController::class, 'move'])->name('studio.media.move');
    Route::post('/studio/media/{mediaAsset}/pin', [ImageStudioController::class, 'pin'])->name('studio.media.pin');
    Route::post('/studio/folders', [MediaFolderController::class, 'store'])->name('studio.folders.store');
    Route::delete('/studio/folders/{folder}', [MediaFolderController::class, 'destroy'])->name('studio.folders.destroy');

    // استطلاع حالة المهام
    Route::get('/api/jobs/{job}', [JobStatusController::class, 'show'])->name('api.jobs.show');
});

// إعدادات المنصة — خارج brand.ready: المدير يجب أن يصلها ولو لم يُكمل علامته
Route::middleware(['auth', 'can:manage-platform'])->prefix('settings')->name('settings.')->group(function () {
    Route::get('/ai', [PlatformSettingsController::class, 'edit'])->name('ai');
    Route::put('/ai', [PlatformSettingsController::class, 'update'])->name('ai.update');
    Route::post('/ai/test', [PlatformSettingsController::class, 'test'])
        ->middleware('throttle:10,1')->name('ai.test');
});

require __DIR__.'/auth.php';
