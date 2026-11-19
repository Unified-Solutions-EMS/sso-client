<?php

use Illuminate\Support\Facades\Route;
use Unified\SsoClient\Assistant\Http\AssistantProxyController;
use Unified\SsoClient\Assistant\Http\AssistantToolsController;
use Unified\SsoClient\Assistant\Http\AssistantWidgetController;
use Unified\SsoClient\Assistant\Http\AuthenticateAssistant;
use Unified\SsoClient\Middleware\ValidateCoreApiKey;

// Tool catalog + execute: SSO calls these with CORE_APP_API_KEY while the
// assistant works on behalf of an admin. App binds AssistantToolProvider; no
// provider bound → 200 with an empty catalog.
Route::middleware(ValidateCoreApiKey::class)
    ->prefix('/api/internal/assistant')
    ->group(function (): void {
        Route::get('tools', [AssistantToolsController::class, 'index'])
            ->name('sso.assistant.tools');

        Route::post('tools/{name}', [AssistantToolsController::class, 'execute'])
            ->where('name', '[A-Za-z0-9_]+')
            ->name('sso.assistant.tools.execute');
    });

if (config('sso.assistant.enabled', true)) {
    $base = rtrim((string) config('sso.assistant.path', '/assistant'), '/');

    // The prebuilt widget bundle. No session: it is a static file.
    Route::get($base.'/widget.js', AssistantWidgetController::class)
        ->name('sso.assistant.widget');

    // Same-origin proxy for the widget. SSO hosts the service itself and
    // serves these paths from its own controllers (assistant.proxy = false).
    if (config('sso.assistant.proxy', true)) {
        // `auth` becomes AuthenticateAssistant: the same for a signed-in user,
        // and lets a website visitor through when sso.assistant.visitor is on.
        $middleware = array_map(
            fn (mixed $entry): mixed => $entry === 'auth' ? AuthenticateAssistant::class : $entry,
            (array) config('sso.assistant.middleware', ['web', 'auth']),
        );

        Route::middleware($middleware)
            ->prefix($base)
            ->group(function (): void {
                Route::get('bootstrap', [AssistantProxyController::class, 'bootstrap'])->name('sso.assistant.bootstrap');
                Route::post('messages', [AssistantProxyController::class, 'message'])->name('sso.assistant.messages');
                Route::post('escalate', [AssistantProxyController::class, 'escalate'])->name('sso.assistant.escalate');
                Route::get('updates', [AssistantProxyController::class, 'updates'])->name('sso.assistant.updates');
                Route::post('seen', [AssistantProxyController::class, 'seen'])->middleware('throttle:60,1')->name('sso.assistant.seen');
                Route::post('uploads', [AssistantProxyController::class, 'upload'])->name('sso.assistant.uploads');
                Route::get('uploads/{upload}', [AssistantProxyController::class, 'uploadStatus'])->whereNumber('upload')->name('sso.assistant.uploads.show');
                Route::post('actions/{action}/undo', [AssistantProxyController::class, 'undo'])->whereNumber('action')->name('sso.assistant.undo');
                Route::post('review/{plan}/approve', [AssistantProxyController::class, 'approveReview'])->name('sso.assistant.review.approve');
                Route::post('review/{plan}/remove', [AssistantProxyController::class, 'removeFromReview'])->name('sso.assistant.review.remove');
                Route::post('review/{plan}/choose', [AssistantProxyController::class, 'chooseInReview'])->name('sso.assistant.review.choose');
                Route::post('review/{plan}/set-aside', [AssistantProxyController::class, 'setAsideReview'])->name('sso.assistant.review.set-aside');
                Route::delete('conversation', [AssistantProxyController::class, 'clearConversation'])->name('sso.assistant.conversation.clear');
                Route::post('intro/dismiss', [AssistantProxyController::class, 'dismissIntro'])->name('sso.assistant.intro.dismiss');
                Route::post('rating', [AssistantProxyController::class, 'rating'])->middleware('throttle:20,1')->name('sso.assistant.rating');
                Route::post('callback', [AssistantProxyController::class, 'callback'])->middleware('throttle:5,1')->name('sso.assistant.callback');
                Route::post('feedback', [AssistantProxyController::class, 'feedback'])->name('sso.assistant.feedback');
                Route::post('feedback/{feedback}/follow-up', [AssistantProxyController::class, 'feedbackFollowUp'])->whereNumber('feedback')->name('sso.assistant.feedback.follow-up');
            });
    }
}
