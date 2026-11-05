<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\DistrictController;
use App\Http\Controllers\Admin\OffenceCategoryController;
use App\Http\Controllers\Admin\OrganisationController;
use App\Http\Controllers\Admin\RoleMatrixController;
use App\Http\Controllers\Admin\SecurityAlertController;
use App\Http\Controllers\Admin\StationController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserLifecycleController;
use App\Http\Controllers\Admin\UserRoleController;
use App\Http\Controllers\Auth\AccountActivationController;
use App\Http\Controllers\Auth\TwoFactorSetupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Records\AccessRequestController;
use App\Http\Controllers\Records\CaseController;
use App\Http\Controllers\Records\CaseProgressController;
use App\Http\Controllers\Records\CourtController;
use App\Http\Controllers\Records\DocumentController;
use App\Http\Controllers\Records\DuplicateReviewController;
use App\Http\Controllers\Records\ExportController;
use App\Http\Controllers\Records\PersonController;
use App\Http\Controllers\Records\PersonDetailController;
use App\Http\Controllers\Records\RevealController;
use App\Http\Controllers\Records\SearchController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\SessionHeartbeatController;
use App\Http\Controllers\Settings\BrowserSessionController;
use App\Http\Controllers\Settings\SecondFactorConfirmationController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\TwoFactorResetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| Sign-in, sign-out, password reset, password confirmation and the two-factor
| challenge are provided by Laravel Fortify (see config/fortify.php and
| App\Providers\FortifyServiceProvider). There is no public registration.
|
*/

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('activate/{token}', [AccountActivationController::class, 'create'])
        ->name('activation.create');

    Route::post('activate', [AccountActivationController::class, 'store'])
        ->middleware('throttle:6,1,activation')
        ->name('activation.store');
});

Route::middleware('auth')->group(function () {
    Route::post('session/heartbeat', SessionHeartbeatController::class)
        ->middleware('throttle:30,1,heartbeat')
        ->name('session.heartbeat');

    // Shows (and creates) the secret, so it needs the same password
    // confirmation as Fortify's own routes that show it.
    Route::get('security/two-factor/setup', [TwoFactorSetupController::class, 'show'])
        ->middleware('password.confirm')
        ->name('two-factor.setup');

    Route::middleware('mfa')->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('people', [PersonController::class, 'index'])->name('persons.index');
        Route::get('people/create', [PersonController::class, 'create'])->name('persons.create');
        Route::post('people', [PersonController::class, 'store'])->name('persons.store');
        Route::get('people/duplicates', [DuplicateReviewController::class, 'index'])->name('persons.duplicates.index');
        Route::post('people/duplicates/{candidate}/merge', [DuplicateReviewController::class, 'merge'])->middleware('password.confirm')->name('persons.duplicates.merge');
        Route::post('people/duplicates/{candidate}/dismiss', [DuplicateReviewController::class, 'dismiss'])->name('persons.duplicates.dismiss');
        Route::get('people/{person}', [PersonController::class, 'show'])->name('persons.show');
        Route::put('people/{person}', [PersonController::class, 'update'])->name('persons.update');
        Route::post('people/{person}/identities', [PersonDetailController::class, 'storeIdentity'])->name('persons.identities.store');
        Route::put('people/{person}/identities/{identity}/verification', [PersonDetailController::class, 'updateVerification'])->name('persons.identities.verification');
        Route::post('people/{person}/contacts', [PersonDetailController::class, 'storeContact'])->name('persons.contacts.store');
        Route::post('people/{person}/addresses', [PersonDetailController::class, 'storeAddress'])->name('persons.addresses.store');

        Route::get('cases', [CaseController::class, 'index'])->name('cases.index');
        Route::get('cases/create', [CaseController::class, 'create'])->name('cases.create');
        Route::post('cases', [CaseController::class, 'store'])->name('cases.store');
        Route::get('cases/{case}', [CaseController::class, 'show'])->name('cases.show');
        Route::put('cases/{case}', [CaseController::class, 'update'])->name('cases.update');
        Route::post('cases/{case}/notes', [CaseProgressController::class, 'storeNote'])->name('cases.notes.store');
        Route::put('cases/{case}/status', [CaseProgressController::class, 'updateStatus'])->name('cases.status.update');
        Route::put('cases/{case}/lifecycle', [CaseProgressController::class, 'updateLifecycle'])->name('cases.lifecycle.update');
        Route::post('cases/{case}/assignments', [CaseProgressController::class, 'storeAssignment'])->name('cases.assignments.store');
        Route::delete('cases/{case}/assignments/{assignment}', [CaseProgressController::class, 'destroyAssignment'])->name('cases.assignments.destroy');
        Route::post('cases/{case}/people', [CaseProgressController::class, 'storePerson'])->name('cases.people.store');
        Route::delete('cases/{case}/people/{link}', [CaseProgressController::class, 'destroyPerson'])->name('cases.people.destroy');
        Route::get('cases/{case}/print', [CaseController::class, 'printRecord'])->name('cases.print');

        Route::post('cases/{case}/documents', [DocumentController::class, 'store'])->middleware('throttle:30,1,document-upload')->name('cases.documents.store');
        Route::get('documents/{document}', [DocumentController::class, 'show'])->middleware('throttle:60,1,document-download')->name('documents.show');
        Route::put('documents/{document}/legal-hold', [DocumentController::class, 'updateHold'])->name('documents.legal-hold');

        Route::post('cases/{case}/court-proceedings', [CourtController::class, 'storeProceeding'])->name('cases.court-proceedings.store');
        Route::post('court-proceedings/{proceeding}/outcomes', [CourtController::class, 'storeOutcome'])->name('court-proceedings.outcomes.store');
        Route::put('court-outcomes/{outcome}/verification', [CourtController::class, 'verifyOutcome'])->middleware('password.confirm')->name('court-outcomes.verification');

        // Searches are POST so that identifiers and names stay out of URLs.
        Route::middleware('can:search.records')->group(function () {
            Route::get('search', [SearchController::class, 'show'])->name('search.show');
            Route::post('search/cases', [SearchController::class, 'caseReference'])->middleware('throttle:60,1,search-cases')->name('search.cases');
            Route::post('search/people/name', [SearchController::class, 'name'])->middleware(['can:persons.view', 'throttle:20,1,search-people'])->name('search.people.name');
        });

        Route::post('search/people/identity', [SearchController::class, 'identity'])->middleware(['can:search.identifiers', 'throttle:10,1,search-identity'])->name('search.people.identity');

        Route::post('people/{person}/reveal', RevealController::class)->middleware('throttle:10,1,reveal')->name('persons.reveal');

        Route::get('access-requests', [AccessRequestController::class, 'index'])->name('access-requests.index');
        Route::post('access-requests', [AccessRequestController::class, 'store'])->middleware(['can:access.request', 'throttle:10,1,access-request'])->name('access-requests.store');
        Route::post('access-requests/{justification}/decision', [AccessRequestController::class, 'decide'])->middleware('password.confirm')->name('access-requests.decide');

        Route::get('reports', [ReportController::class, 'operational'])->middleware('can:reports.operational')->name('reports.index');
        Route::get('reports/statistics', [ReportController::class, 'statistics'])->middleware('can:reports.statistics')->name('reports.statistics');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::get('notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');

        Route::get('exports', [ExportController::class, 'index'])->name('exports.index');
        Route::post('exports', [ExportController::class, 'store'])->middleware('throttle:10,1,export')->name('exports.store');
        Route::post('exports/{export}/decision', [ExportController::class, 'decide'])->middleware('password.confirm')->name('exports.decide');
        Route::get('exports/{export}/download', [ExportController::class, 'download'])->name('exports.download');

        Route::prefix('admin')->name('admin.')->group(function () {
            Route::middleware('can:organisation.manage')->group(function () {
                Route::get('organisation', [OrganisationController::class, 'index'])->name('organisation.index');
                Route::post('districts', [DistrictController::class, 'store'])->name('districts.store');
                Route::put('districts/{district}', [DistrictController::class, 'update'])->name('districts.update');
                Route::post('stations', [StationController::class, 'store'])->name('stations.store');
                Route::put('stations/{station}', [StationController::class, 'update'])->name('stations.update');
                Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');
                Route::put('departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
            });

            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');

            // Changing what someone can reach needs a recent password check.
            Route::middleware('password.confirm')->group(function () {
                Route::post('users', [UserController::class, 'store'])->name('users.store');
                Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
                Route::post('users/{user}/roles', [UserRoleController::class, 'store'])->name('users.roles.store');
                Route::delete('users/{user}/roles/{assignment}', [UserRoleController::class, 'destroy'])->name('users.roles.destroy');
                Route::post('users/{user}/suspend', [UserLifecycleController::class, 'suspend'])->name('users.suspend');
                Route::post('users/{user}/deactivate', [UserLifecycleController::class, 'deactivate'])->name('users.deactivate');
                Route::post('users/{user}/reactivate', [UserLifecycleController::class, 'reactivate'])->name('users.reactivate');
                Route::post('users/{user}/reset-two-factor', [UserLifecycleController::class, 'resetTwoFactor'])->name('users.reset-two-factor');
                Route::post('users/{user}/resend-invitation', [UserLifecycleController::class, 'resendInvitation'])->name('users.resend-invitation');
            });

            Route::middleware('can:catalogue.manage')->group(function () {
                Route::get('catalogue', [OffenceCategoryController::class, 'index'])->name('catalogue.index');
                Route::post('catalogue', [OffenceCategoryController::class, 'store'])->name('catalogue.store');
                Route::put('catalogue/{category}', [OffenceCategoryController::class, 'update'])->name('catalogue.update');
            });

            Route::get('roles', RoleMatrixController::class)->middleware('can:users.view')->name('roles.index');
            Route::get('audit', AuditLogController::class)->middleware('can:audit.view')->name('audit.index');

            Route::middleware('can:alerts.manage')->group(function () {
                Route::get('alerts', [SecurityAlertController::class, 'index'])->name('alerts.index');
                Route::put('alerts/{alert}/review', [SecurityAlertController::class, 'review'])->name('alerts.review');
            });
        });

        Route::middleware('password.confirm')->prefix('settings/security')->group(function () {
            Route::get('/', [SecurityController::class, 'show'])->name('security.show');

            Route::delete('sessions/{session}', [BrowserSessionController::class, 'destroy'])
                ->name('security.sessions.destroy');

            Route::delete('sessions', [BrowserSessionController::class, 'destroyOthers'])
                ->name('security.sessions.destroy-others');

            Route::post('second-factor/confirm', SecondFactorConfirmationController::class)
                ->middleware('throttle:5,1,second-factor')
                ->name('security.second-factor.confirm');

            Route::post('two-factor/reset', TwoFactorResetController::class)
                ->middleware('throttle:5,1,two-factor-reset')
                ->name('security.two-factor.reset');
        });
    });
});
