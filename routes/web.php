<?php

use App\Http\Controllers\Admin\AdministratorController;
use App\Http\Controllers\Admin\CampaignController;
use App\Http\Controllers\Admin\CampaignImpactController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DecidedHelpApplicationController;
use App\Http\Controllers\Admin\HelpApplicationController as AdminHelpApplicationController;
use App\Http\Controllers\Admin\HelpApplicationDecisionController;
use App\Http\Controllers\Admin\HelpApplicationDuplicateWarningController;
use App\Http\Controllers\Admin\InReviewHelpApplicationController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AidDeliveryController;
use App\Http\Controllers\Applicant\HelpApplicationController;
use App\Http\Controllers\Applicant\HelpApplicationDocumentController;
use App\Http\Controllers\AssistanceCoordinationController;
use App\Http\Controllers\DonationCaseController;
use App\Http\Controllers\DonationController;
use App\Http\Controllers\HomepageController;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\EnsureCoordinationAccount;
use App\Http\Middleware\EnsureSandboxDonationsEnabled;
use Illuminate\Support\Facades\Route;

Route::get('/', HomepageController::class)->name('home');
Route::get('/{locale}', HomepageController::class)->whereIn('locale', ['ar', 'en'])->name('home.localized');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/admin/reports', ReportController::class)
    ->middleware(['auth', 'role:admin,super_admin', 'throttle:admin-reports'])
    ->name('admin.reports.index');

Route::get('/admin', AdminDashboardController::class)
    ->middleware(['auth', 'role:admin,super_admin'])
    ->name('admin.dashboard');

Route::middleware(['auth', 'role:admin,super_admin'])->group(function () {
    Route::get('/admin/help-applications/decided', [DecidedHelpApplicationController::class, 'index'])
        ->name('admin.help-applications.decided.index');
    Route::get('/admin/help-applications/decided/{helpApplication}', [DecidedHelpApplicationController::class, 'show'])
        ->whereUuid('helpApplication')->name('admin.help-applications.decided.show');
    Route::post('/admin/help-applications/decided/{helpApplication}/convert-to-campaign', [DecidedHelpApplicationController::class, 'convert'])
        ->whereUuid('helpApplication')->middleware('throttle:10,1')->name('admin.help-applications.decided.convert-to-campaign');
    Route::get('/admin/help-applications/in-review', [InReviewHelpApplicationController::class, 'index'])
        ->name('admin.help-applications.in-review.index');
    Route::post('/admin/help-applications/in-review/{helpApplication}/assign-category', [InReviewHelpApplicationController::class, 'assignCategory'])
        ->whereUuid('helpApplication')
        ->middleware('throttle:10,1')
        ->name('admin.help-applications.in-review.assign-category');
    Route::get('/admin/help-applications/in-review/{helpApplication}/duplicate-warnings', [HelpApplicationDuplicateWarningController::class, 'index'])
        ->whereUuid('helpApplication')
        ->name('admin.help-applications.in-review.duplicate-warnings.index');
    Route::post('/admin/help-applications/in-review/{helpApplication}/duplicate-warnings/{duplicateWarning}/resolve', [HelpApplicationDuplicateWarningController::class, 'resolve'])
        ->whereUuid(['helpApplication', 'duplicateWarning'])
        ->middleware('throttle:10,1')
        ->name('admin.help-applications.in-review.duplicate-warnings.resolve');
    Route::post('/admin/help-applications/in-review/{helpApplication}/decide', HelpApplicationDecisionController::class)
        ->whereUuid('helpApplication')
        ->middleware('throttle:10,1')
        ->name('admin.help-applications.in-review.decide');
    Route::get('/admin/help-applications/in-review/{helpApplication}', [InReviewHelpApplicationController::class, 'show'])
        ->whereUuid('helpApplication')
        ->name('admin.help-applications.in-review.show');
    Route::get('/admin/help-applications', [AdminHelpApplicationController::class, 'index'])
        ->name('admin.help-applications.index');
    Route::get('/admin/help-applications/{helpApplication}', [AdminHelpApplicationController::class, 'show'])
        ->whereUuid('helpApplication')
        ->name('admin.help-applications.show');
    Route::post('/admin/help-applications/{helpApplication}/start-review', [AdminHelpApplicationController::class, 'startReview'])
        ->whereUuid('helpApplication')
        ->middleware('throttle:10,1')
        ->name('admin.help-applications.start-review');

    Route::get('/admin/campaigns', [CampaignController::class, 'index'])->name('admin.campaigns.index');
    Route::get('/admin/campaigns/create', [CampaignController::class, 'create'])->name('admin.campaigns.create');
    Route::post('/admin/campaigns', [CampaignController::class, 'store'])->middleware('throttle:10,1')->name('admin.campaigns.store');
    Route::get('/admin/campaigns/{campaign}/edit', [CampaignController::class, 'edit'])->name('admin.campaigns.edit');
    Route::post('/admin/campaigns/{campaign}/publish', [CampaignController::class, 'publish'])->middleware('throttle:10,1')->name('admin.campaigns.publish');
    Route::get('/admin/campaigns/{campaign}/impact', [CampaignImpactController::class, 'edit'])->name('admin.campaigns.impact.edit');
    Route::post('/admin/campaigns/{campaign}/impact/draft', [CampaignImpactController::class, 'draft'])->middleware('throttle:impact-draft')->name('admin.campaigns.impact.draft');
    Route::post('/admin/campaigns/{campaign}/impact/publish', [CampaignImpactController::class, 'publish'])->middleware('throttle:impact-publish')->name('admin.campaigns.impact.publish');
    Route::patch('/admin/campaigns/{campaign}', [CampaignController::class, 'update'])->middleware('throttle:10,1')->name('admin.campaigns.update');
    Route::get('/admin/campaigns/{campaign}/image', [CampaignController::class, 'showImage'])->name('admin.campaigns.image.show');
    Route::post('/admin/campaigns/{campaign}/image', [CampaignController::class, 'storeImage'])->middleware('throttle:10,1')->name('admin.campaigns.image.store');
    Route::delete('/admin/campaigns/{campaign}/image', [CampaignController::class, 'destroyImage'])->middleware('throttle:10,1')->name('admin.campaigns.image.destroy');

    Route::get('/admin/categories', [CategoryController::class, 'index'])
        ->name('admin.categories.index');
    Route::get('/admin/categories/trashed', [CategoryController::class, 'trashed'])
        ->name('admin.categories.trashed');
    Route::get('/admin/categories/create', [CategoryController::class, 'create'])
        ->name('admin.categories.create');
    Route::post('/admin/categories', [CategoryController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('admin.categories.store');
    Route::get('/admin/categories/{category}/edit', [CategoryController::class, 'edit'])
        ->name('admin.categories.edit');
    Route::patch('/admin/categories/{category}', [CategoryController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('admin.categories.update');
    Route::patch('/admin/categories/{category}/image', [CategoryController::class, 'updateImage'])
        ->middleware('throttle:10,1')
        ->name('admin.categories.image.update');
    Route::delete('/admin/categories/{category}/image', [CategoryController::class, 'destroyImage'])
        ->middleware('throttle:10,1')
        ->name('admin.categories.image.destroy');
    Route::delete('/admin/categories/{category}', [CategoryController::class, 'destroy'])
        ->middleware('throttle:10,1')
        ->name('admin.categories.destroy');
    Route::patch('/admin/categories/{category}/restore', [CategoryController::class, 'restore'])
        ->middleware('throttle:10,1')
        ->name('admin.categories.restore');
});

Route::middleware(['auth', 'role:super_admin'])->group(function () {
    Route::get('/admin/administrators', [AdministratorController::class, 'index'])
        ->name('admin.administrators.index');
    Route::get('/admin/administrators/create', [AdministratorController::class, 'create'])
        ->name('admin.administrators.create');
    Route::post('/admin/administrators', [AdministratorController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('admin.administrators.store');
});

Route::get('/admin/users', [AdminUserController::class, 'index'])
    ->middleware(['auth', 'role:admin,super_admin'])
    ->name('admin.users.index');

Route::get('/admin/users/{user}', [AdminUserController::class, 'show'])
    ->middleware(['auth', 'role:admin,super_admin'])
    ->name('admin.users.show');

Route::middleware(['auth', 'role:admin,super_admin', 'throttle:10,1'])->group(function () {
    Route::patch('/admin/users/{user}/disable', [AdminUserController::class, 'disable'])
        ->name('admin.users.disable');
    Route::patch('/admin/users/{user}/reactivate', [AdminUserController::class, 'reactivate'])
        ->name('admin.users.reactivate');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'role:user'])->prefix('help-applications')->name('help-applications.')->group(function () {
    Route::get('/', [HelpApplicationController::class, 'index'])->name('index');
    Route::get('/create', [HelpApplicationController::class, 'create'])->name('create');
    Route::post('/', [HelpApplicationController::class, 'store'])->middleware('throttle:6,1')->name('store');
    Route::post('/{helpApplication}/submit', [HelpApplicationController::class, 'submit'])->middleware('throttle:6,1')->name('submit');
    Route::post('/{helpApplication}/documents', [HelpApplicationDocumentController::class, 'store'])->middleware('throttle:6,1')->name('documents.store');
    Route::delete('/{helpApplication}/documents/{helpApplicationDocument}', [HelpApplicationDocumentController::class, 'destroy'])->middleware('throttle:10,1')->name('documents.destroy');
    Route::get('/{helpApplication}/edit', [HelpApplicationController::class, 'edit'])->name('edit');
    Route::patch('/{helpApplication}', [HelpApplicationController::class, 'update'])->middleware('throttle:10,1')->name('update');
});

Route::get('/{locale}/cases', [DonationCaseController::class, 'index'])->whereIn('locale', ['ar', 'en'])->name('cases.index');
Route::get('/{locale}/cases/{campaign}/image', [DonationCaseController::class, 'image'])->whereIn('locale', ['ar', 'en'])->where('campaign', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('cases.image');
Route::get('/{locale}/cases/{campaign}', [DonationCaseController::class, 'show'])->whereIn('locale', ['ar', 'en'])->where('campaign', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('cases.show');

Route::prefix('{locale}')->whereIn('locale', ['ar', 'en'])->name('donations.')->middleware(EnsureSandboxDonationsEnabled::class)->controller(DonationController::class)->group(function () {
    Route::get('/my-donations', 'index')->middleware('auth')->name('index');
    Route::get('/cases/{campaign}/donate', 'create')->where('campaign', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('create');
    Route::post('/cases/{campaign}/donate', 'store')->where('campaign', '[a-z0-9]+(?:-[a-z0-9]+)*')->middleware('throttle:donation-entry')->name('store');
    Route::get('/donations/{donation}/checkout/{capability?}', 'checkout')->where('donation', '[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}')->where('capability', '[0-9a-f]{64}')->middleware('throttle:donation-checkout')->name('checkout');
    Route::post('/donations/{donation}/outcome/{action}/{capability?}', 'outcome')->where('donation', '[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}')->whereIn('action', ['success', 'failure', 'cancellation'])->where('capability', '[0-9a-f]{64}')->middleware('throttle:donation-outcome')->name('outcome');
    Route::get('/donations/{donation}/result/{capability?}', 'show')->where('donation', '[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}')->where('capability', '[0-9a-f]{64}')->middleware('throttle:donation-result')->name('show');
});

// Delivery is private, UUID-bound, and applicant reads have no mutation routes.
foreach (['admin' => ['admin/aid-delivery', 'admin.aid-delivery.', 'admin,super_admin'],
    'applicant' => ['help-applications', 'help-applications.aid-delivery.', 'user']] as $side => [$prefix, $name, $role]) {
    Route::prefix($prefix)->name($name)->middleware([EnsureCoordinationAccount::class, 'auth', 'role:'.$role])
        ->group(function () use ($side) {
            $entry = $side === 'admin' ? '/{helpApplication}/{coordination}' : '/{helpApplication}/aid-delivery/{coordination}';
            Route::get($entry, [AidDeliveryController::class, 'show'])->whereUuid(['helpApplication', 'coordination'])->middleware('throttle:aid-delivery-read')->name('index');
            Route::get($entry.'/{delivery}', [AidDeliveryController::class, 'show'])->whereUuid(['helpApplication', 'coordination', 'delivery'])->middleware('throttle:aid-delivery-read')->name('show');
            if ($side === 'admin') {
                Route::post($entry.'/complete', [AidDeliveryController::class, 'complete'])->whereUuid(['helpApplication', 'coordination'])->middleware('throttle:aid-delivery-complete')->name('complete');
                foreach (['start', 'problem', 'resume', 'success'] as $action) {
                    Route::post($entry.($action === 'start' ? '' : '/{delivery}').'/'.$action, [AidDeliveryController::class, 'mutate'])
                        ->whereUuid(['helpApplication', 'coordination', 'delivery'])->middleware('throttle:aid-delivery-'.$action)->name($action);
                }
            }
        });
}

// Private coordination uses UUID references and its own stricter authorization.
foreach (['admin' => ['admin/assistance-coordination', 'admin.coordination.', 'admin,super_admin'],
    'applicant' => ['help-applications', 'help-applications.coordination.', 'user']] as $side => [$prefix, $name, $role]) {
    Route::prefix($prefix)->name($name)->middleware([EnsureCoordinationAccount::class, 'auth', 'role:'.$role])
        ->group(function () use ($side) {
            $entry = $side === 'admin' ? '/{helpApplication}' : '/{helpApplication}/coordination';
            Route::get($entry, [AssistanceCoordinationController::class, 'show'])->whereUuid('helpApplication')->middleware('throttle:coordination-read')->name('entry');
            Route::get($entry.'/{coordination}', [AssistanceCoordinationController::class, 'show'])->whereUuid(['helpApplication', 'coordination'])->middleware('throttle:coordination-read')->name('show');
            $actions = $side === 'admin' ? ['start', 'message', 'correct', 'confirm'] : ['message', 'respond'];
            foreach ($actions as $action) {
                Route::post($entry.($action === 'start' ? '' : '/{coordination}').'/'.$action,
                    [AssistanceCoordinationController::class, 'mutate'])
                    ->whereUuid(['helpApplication', 'coordination'])->middleware('throttle:coordination-'.$action)->name($action);
            }
        });
}
require __DIR__.'/auth.php';

// Enhanced modern Islamic Glassmorphism UI routes integrated successfully.
