import type {
    BestTime,
    BootstrapResponse,
    CallbackResponse,
    FeedbackResponse,
    RatingResponse,
    ReviewResponse,
    TurnResponse,
    UndoResponse,
    UploadResponse,
    UploadStatusResponse,
    UpdatesResponse,
    WidgetSettings,
} from './types';

export type ReviewVerb = 'approve' | 'remove' | 'choose' | 'set-aside';

export function chooseBody(planHash: string, index: number, code: string | null): { plan_hash: string; index: number; code: string | null } {
    return { plan_hash: planHash, index, code };
}

export class ApiError extends Error {
    constructor(
        public status: number,
        message: string,
        /** Field errors from a 422, keyed by field. */
        public errors: Record<string, string[]> = {},
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

    async send(message: string, setupApp: string | null, conversationId: number | string | null = null, attachments: number[] = []): Promise<TurnResponse> {
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

        if (attachments.length > 0) {
            body.attachments = attachments;
        }

        return (await this.json('POST', this.settings.messages, body)) as TurnResponse;
    }

    /**
     * "Talk to our team". The answer is the bootstrap shape with the "Handed to
     * our team" line. With `message` SSO first stores it as the administrator's
     * message, with its files, and answers with "Our team has your message".
     */
    async escalate(conversationId: number | string | null, question: string, message: string | null = null, attachments: number[] = []): Promise<BootstrapResponse> {
        const body: Record<string, unknown> = { question };

        if (conversationId !== null) {
            body.conversation_id = conversationId;
        }

        if (message !== null) {
            body.message = message;
        }

        if (attachments.length > 0) {
            body.attachments = attachments;
        }

        return (await this.json('POST', this.sibling('escalate', this.settings.escalate), body)) as BootstrapResponse;
    }

    /** Our team's replies newer than `after`. */
    async updates(conversationId: number | string, after: number): Promise<UpdatesResponse> {
        const url = this.sibling('updates', this.settings.updates);
        const query = `conversation=${encodeURIComponent(String(conversationId))}&after=${encodeURIComponent(String(after))}`;
        const response = await this.request('GET', `${url}${url.includes('?') ? '&' : '?'}${query}`);

        return (await this.read(response)) as UpdatesResponse;
    }

    /** A route next to messages, for a component that does not send it yet. */
    private sibling(name: string, given: string | null | undefined): string {
        return given || this.settings.messages.replace(/messages\/?$/, name);
    }

    /**
     * The file goes with the same setup app and page the turns carry, so SSO
     * files it under the app whose setup page is open, not the agency's first app.
     */
    async upload(file: File, setupApp: string | null): Promise<UploadResponse> {
        const form = new FormData();
        form.append('file', file, file.name);
        form.append('page[url]', location.href);
        form.append('page[title]', document.title);

        if (setupApp) {
            form.append('setup_app', setupApp);
        }

        const response = await this.request('POST', this.settings.uploads, form);

        return (await this.read(response)) as UploadResponse;
    }

    /** Where a file SSO is reading stands. The route sits next to the upload route. */
    async uploadStatus(uploadId: number): Promise<UploadStatusResponse> {
        const response = await this.request('GET', `${this.settings.uploads.replace(/\/+$/, '')}/${encodeURIComponent(String(uploadId))}`);

        return (await this.read(response)) as UploadStatusResponse;
    }

    async undo(actionId: number): Promise<UndoResponse> {
        return (await this.json('POST', this.settings.undo.replace('{action}', String(actionId)), {})) as UndoResponse;
    }

    async review(planId: string, verb: ReviewVerb, body: Record<string, unknown>): Promise<ReviewResponse> {
        const url = this.settings.review.replace('{plan}', encodeURIComponent(planId)).replace('{verb}', verb);

        return (await this.json('POST', url, body)) as ReviewResponse;
    }

    /** Picks the code for a row that needs a choice; a null code leaves the row out. */
    async choose(planId: string, planHash: string, index: number, code: string | null): Promise<ReviewResponse> {
        return this.review(planId, 'choose', chooseBody(planHash, index, code));
    }

    /** Whether escalate carries the administrator's message in the same call (a newer component). */
    escalatesWithMessage(): boolean {
        return this.settings.escalate_message === true;
    }

    /** Whether this app's component sends the feedback routes (an older sso-client does not). */
    canRecordFeedback(): boolean {
        return Boolean(this.settings.feedback && this.settings.feedback_follow_up);
    }

    /** "Did I handle that correctly?" Null when this component has no feedback route. */
    async feedback(conversationId: number | string, messageId: number | string | null, helpful: boolean): Promise<FeedbackResponse | null> {
        if (!this.settings.feedback) {
            return null;
        }

        const body: Record<string, unknown> = { conversation_id: conversationId, helpful };

        if (messageId !== null && typeof messageId === 'number') {
            body.message_id = messageId;
        }

        return (await this.json('POST', this.settings.feedback, body)) as FeedbackResponse;
    }

    async feedbackFollowUp(feedbackId: number, wantsFollowUp: boolean): Promise<FeedbackResponse | null> {
        if (!this.settings.feedback_follow_up) {
            return null;
        }

        return (await this.json('POST', this.settings.feedback_follow_up.replace('{feedback}', String(feedbackId)), { follow_up: wantsFollowUp })) as FeedbackResponse;
    }

    /** Whether this app's component sends the rating route (an older sso-client does not). */
    canRate(): boolean {
        return Boolean(this.settings.rating);
    }

    /** Whether "Yes, call me" can be sent from this app. */
    canRequestCall(): boolean {
        return Boolean(this.settings.callback);
    }

    /** The 1 to 5 rating at the end of a conversation, with an optional note. */
    async rate(conversationId: number | string, score: number, note: string | null): Promise<RatingResponse> {
        const body: Record<string, unknown> = { conversation_id: conversationId, score };

        if (note !== null && note.trim() !== '') {
            body.note = note.trim();
        }

        return (await this.json('POST', this.settings.rating ?? '', body)) as RatingResponse;
    }

    /** "Request a call": our team gets the conversation with the number and the best time. */
    async requestCall(conversationId: number | string, phone: string, bestTime: BestTime, note: string | null): Promise<CallbackResponse> {
        const body: Record<string, unknown> = { conversation_id: conversationId, phone, best_time: bestTime };

        if (note !== null && note.trim() !== '') {
            body.note = note.trim();
        }

        return (await this.json('POST', this.settings.callback ?? '', body)) as CallbackResponse;
    }

    /** Ends the current thread; the answer is the bootstrap shape with a new empty one. */
    async clear(): Promise<BootstrapResponse> {
        return (await this.json('DELETE', this.settings.conversation, {})) as BootstrapResponse;
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

            const errors: Record<string, string[]> = {};

            if (record.errors && typeof record.errors === 'object') {
                for (const [field, list] of Object.entries(record.errors as Record<string, unknown>)) {
                    if (Array.isArray(list)) {
                        errors[field] = list.filter((item): item is string => typeof item === 'string');
                    }
                }
            }

            throw new ApiError(response.status, message, errors);
        }

        return data ?? {};
    }
}
