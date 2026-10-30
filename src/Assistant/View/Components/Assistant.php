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
    /**
     * @param  string|null  $setupApp  slug of the app whose setup page is open, so the turn carries setup_app
     */
    public function __construct(
        protected ConfigRepository $config,
        protected AuthFactory $auth,
        public ?string $setupApp = null,
    ) {}

    public function render(): View|string
    {
        if (! $this->config->get('sso.assistant.enabled', true) || ! $this->auth->guard()->check()) {
            return '';
        }

        $base = rtrim((string) $this->config->get('sso.assistant.path', '/assistant'), '/');

        $settings = [
            'bootstrap' => $base.'/bootstrap',
            'messages' => $base.'/messages',
            'uploads' => $base.'/uploads',
            'undo' => $base.'/actions/{action}/undo',
            'review' => $base.'/review/{plan}/{verb}',
            'conversation' => $base.'/conversation',
            'csrf' => csrf_token(),
        ];

        if ($this->setupApp !== null && $this->setupApp !== '') {
            $settings['setup_app'] = $this->setupApp;
        }

        return view('sso::components.assistant', [
            'src' => $base.'/widget.js',
            'settings' => $settings,
        ]);
    }
}
