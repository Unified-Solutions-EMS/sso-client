<?php

namespace Unified\SsoClient\Assistant\View\Components;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * <x-unified-assistant /> mounts the assistant drawer. Renders nothing when
 * the feature is off or nobody is signed in; whether this user gets the
 * launcher at all (admin for the company, SSO decides) is settled by the
 * bootstrap call the script makes.
 */
class Assistant extends Component
{
    public function __construct(
        protected ConfigRepository $config,
        protected AuthFactory $auth,
    ) {}

    public function render(): View|string
    {
        if (! $this->config->get('sso.assistant.enabled', true) || ! $this->auth->guard()->check()) {
            return '';
        }

        $base = rtrim((string) $this->config->get('sso.assistant.path', '/assistant'), '/');

        return view('sso::components.assistant', [
            'src' => $base.'/widget.js',
            'settings' => [
                'bootstrap' => $base.'/bootstrap',
                'messages' => $base.'/messages',
                'uploads' => $base.'/uploads',
                'undo' => $base.'/actions/{action}/undo',
                'review' => $base.'/review/{plan}/{verb}',
                'conversation' => $base.'/conversation',
                'csrf' => csrf_token(),
            ],
        ]);
    }
}
