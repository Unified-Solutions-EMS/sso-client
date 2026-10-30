import type {
    BootstrapResponse,
    ReviewResponse,
    TurnResponse,
    UndoResponse,
    UploadResponse,
    WidgetSettings,
} from './types';

export class ApiError extends Error {
    constructor(
        public status: number,
        message: string,
    ) {
        super(message);
    }
}

const UNREACHABLE = 'The assistant is not reachable right now. Try again in a minute.';

export class AssistantApi {
    constructor(private settings: WidgetSettings) {}

    /**
     * Null when the server answered 204: this session gets no assistant.
     * With a conversation id the server reopens that thread instead of the
     * current one.
     */
    async bootstrap(conversationId: number | string | null = null): Promise<BootstrapResponse | null> {
        const url =
            conversationId === null
                ? this.settings.bootstrap
                : `${this.settings.bootstrap}${this.settings.bootstrap.includes('?') ? '&' : '?'}conversation=${encodeURIComponent(String(conversationId))}`;

        const response = await this.request('GET', url);

        if (response.status === 204) {
            return null;
        }

        return (await this.read(response)) as BootstrapResponse;
    }

    async send(message: string, setupApp: string | null, conversationId: number | string | null = null): Promise<TurnResponse> {
        const body: Record<string, unknown> = {
            message,
            page: { url: location.href, title: document.title },
        };

        if (setupApp) {
            body.setup_app = setupApp;
        }

        if (conversationId !== null) {
            body.conversation_id = conversationId;
        }

        return (await this.json('POST', this.settings.messages, body)) as TurnResponse;
    }

    async upload(file: File): Promise<UploadResponse> {
        const form = new FormData();
        form.append('file', file, file.name);

        const response = await this.request('POST', this.settings.uploads, form);

        return (await this.read(response)) as UploadResponse;
    }

    async undo(actionId: number): Promise<UndoResponse> {
        return (await this.json('POST', this.settings.undo.replace('{action}', String(actionId)), {})) as UndoResponse;
    }

    async review(planId: string, verb: 'approve' | 'remove' | 'set-aside', body: Record<string, unknown>): Promise<ReviewResponse> {
        const url = this.settings.review.replace('{plan}', encodeURIComponent(planId)).replace('{verb}', verb);

        return (await this.json('POST', url, body)) as ReviewResponse;
    }

    /** Ends the current thread; the answer is the bootstrap shape with a new empty one. */
    async clear(): Promise<BootstrapResponse> {
        return (await this.json('DELETE', this.settings.conversation, {})) as BootstrapResponse;
    }

    /** The admin has seen the first-run callout; SSO stops sending it. */
    async dismissIntro(): Promise<void> {
        const url = this.settings.intro_dismiss || this.settings.bootstrap.replace(/\/bootstrap(\?.*)?$/, '/intro/dismiss');

        await this.json('POST', url, {});
    }

    private async json(method: string, url: string, body: Record<string, unknown>): Promise<unknown> {
        const response = await this.request(method, url, JSON.stringify(body), 'application/json');

        return this.read(response);
    }

    private async request(method: string, url: string, body?: BodyInit, contentType?: string): Promise<Response> {
        const headers: Record<string, string> = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };

        if (this.settings.csrf) {
            headers['X-CSRF-TOKEN'] = this.settings.csrf;
        }

        if (contentType) {
            headers['Content-Type'] = contentType;
        }

        try {
            return await fetch(url, { method, headers, body, credentials: 'same-origin' });
        } catch {
            throw new ApiError(0, UNREACHABLE);
        }
    }

    private async read(response: Response): Promise<unknown> {
        let data: unknown = null;

        try {
            data = await response.json();
        } catch {
            data = null;
        }

        if (response.status === 204) {
            return {};
        }

        if (!response.ok) {
            const record = (data ?? {}) as Record<string, unknown>;
            const message =
                (typeof record.error === 'string' && record.error) ||
                (typeof record.message === 'string' && record.message) ||
                (response.status === 419 || response.status === 401
                    ? 'Your session has expired. Refresh the page and try again.'
                    : response.status >= 500
                      ? UNREACHABLE
                      : 'That did not go through. Try again.');

            throw new ApiError(response.status, message);
        }

        return data ?? {};
    }
}
