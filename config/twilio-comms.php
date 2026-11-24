<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Twilio Comms email transport
    |--------------------------------------------------------------------------
    |
    | Backs the `twilio-comms` mailer the package registers. An app sends mail
    | through Twilio by setting MAIL_MAILER=twilio-comms plus the two account
    | credentials below; it needs no code or mail config of its own. These are
    | the same env names SSO, the public website and Bridge already use, so an
    | app that has its own copy of the transport can delete it without
    | touching its environment.
    |
    */

    'account_sid' => env('TWILIO_ACCOUNT_SID'),

    'auth_token' => env('TWILIO_AUTH_TOKEN'),

    'endpoint' => env('TWILIO_COMMS_EMAIL_ENDPOINT', 'https://comms.twilio.com/v1/Emails'),

    /*
    |--------------------------------------------------------------------------
    | Timeouts and retries
    |--------------------------------------------------------------------------
    |
    | Seconds. The send is synchronous inside a request or a queued job, so a
    | hung connection must fail fast. A request can carry up to 10 MB of
    | attachments, hence the longer total timeout.
    |
    | Only 429 and 503 are retried, as Twilio's error guide says, waiting the
    | Retry-After it sends (capped at `max_retry_wait`). Timeouts and 500s are
    | NOT retried here: the API has no idempotency key, so a resend after a
    | request Twilio may already have accepted could deliver the mail twice.
    | The exception lets the queue's own retry decide.
    |
    */

    'connect_timeout' => (int) env('TWILIO_COMMS_EMAIL_CONNECT_TIMEOUT', 5),

    'timeout' => (int) env('TWILIO_COMMS_EMAIL_TIMEOUT', 30),

    'retries' => 2,

    'max_retry_wait' => 5,

];
