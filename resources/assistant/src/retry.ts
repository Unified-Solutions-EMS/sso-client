import { ApiError } from './api';
import { h, paragraphs } from './dom';
import type { BootstrapResponse, Message, TurnResponse } from './types';

export const RETRY_COPY = {
    working: 'Working on it',
    stillWorking: 'Still working… this one is taking a little longer',
    almostThere: 'Almost there',
    tryAgain: 'Try again',
    dismiss: 'Dismiss',
    checkingAgain: 'Checking again…',
};

export const STILL_WORKING_MS = 8000;
export const ALMOST_THERE_MS = 20000;
export const BUSY_POLL_MS = 5000;
export const BUSY_POLL_LIMIT_MS = 60000;

/** The unsent message per thread (`draft:<conversation id>`), so a refresh or a failed turn loses nothing. */
export const DRAFT_NAME_PREFIX = 'draft:';

export function draftName(conversationId: number | string | null): string {
    return `${DRAFT_NAME_PREFIX}${conversationId === null ? 'new' : String(conversationId)}`;
}

export function readDraft(key: string): string {
    try {
        return sessionStorage.getItem(key) ?? '';
    } catch {
        return '';
    }
}

export function saveDraft(key: string, text: string): void {
    try {
        if (text.trim() === '') {
            sessionStorage.removeItem(key);
        } else {
            sessionStorage.setItem(key, text);
        }
    } catch {
        /* private mode: the draft lasts this page only */
    }
}

/** Clears the draft once its message went through, unless the admin has typed something new since. */
export function clearDraftIfSent(key: string, sent: string): void {
    if (readDraft(key).trim() === sent.trim()) {
        saveDraft(key, '');
    }
}

/** SSO answers 409 while another request is still working on this thread. */
export function isBusy(error: unknown): boolean {
    return error instanceof ApiError && error.status === 409;
}

/**
 * A 200 that carries only an error (the assistant is not available right
 * now) is a failed turn too: the admin's message did not get an answer.
 */
export function failedTurnError(turn: TurnResponse): string | null {
    const error = typeof turn.error === 'string' ? turn.error.trim() : '';

    if (error === '') {
        return null;
    }

    const answered = Boolean(turn.reply?.trim()) || (turn.actions?.length ?? 0) > 0 || Boolean(turn.review) || Boolean(turn.handoff);

    return answered ? null : error;
}

/** What the waiting line says this far into a turn. */
export function slowLabel(elapsedMs: number): string {
    if (elapsedMs >= ALMOST_THERE_MS) {
        return RETRY_COPY.almostThere;
    }

    return elapsedMs >= STILL_WORKING_MS ? RETRY_COPY.stillWorking : RETRY_COPY.working;
}

/** Moves the waiting line on at 8 s and 20 s so a long turn is never silent. */
export class SlowTurnTimer {
    private timers: number[] = [];

    start(onLabel: (label: string) => void): void {
        this.stop();
        this.timers = [STILL_WORKING_MS, ALMOST_THERE_MS].map((at) => window.setTimeout(() => onLabel(slowLabel(at)), at));
    }

    stop(): void {
        this.timers.forEach((timer) => window.clearTimeout(timer));
        this.timers = [];
    }
}

/**
 * Whether the thread on the server ends with an answer the drawer has not
 * shown: the last message is the assistant's and its id is not one we have.
 */
export function hasNewerAnswer(serverMessages: Message[] | null | undefined, shown: Message[]): boolean {
    const last = (serverMessages ?? [])[(serverMessages ?? []).length - 1];

    if (!last || last.role !== 'assistant') {
        return false;
    }

    return !shown.some((message) => String(message.id) === String(last.id));
}

/**
 * After a 409, reads the thread every 5 s for up to 60 s. `onNewer` gets the
 * thread once its last answer is new to the drawer; `onGiveUp` runs when the
 * minute is up. A failed read is skipped, not fatal.
 */
export class BusyPoller {
    private timer: number | null = null;

    private startedAt = 0;

    constructor(
        private load: () => Promise<BootstrapResponse | null>,
        private shown: () => Message[],
        private onNewer: (bootstrap: BootstrapResponse) => void,
        private onGiveUp: () => void,
    ) {}

    get running(): boolean {
        return this.timer !== null;
    }

    start(): void {
        this.stop();
        this.startedAt = Date.now();
        this.schedule();
    }

    stop(): void {
        if (this.timer !== null) {
            window.clearTimeout(this.timer);
            this.timer = null;
        }
    }

    private schedule(): void {
        this.timer = window.setTimeout(() => void this.tick(), BUSY_POLL_MS);
    }

    private async tick(): Promise<void> {
        let bootstrap: BootstrapResponse | null = null;

        try {
            bootstrap = await this.load();
        } catch {
            bootstrap = null;
        }

        if (this.timer === null) {
            return;
        }

        if (bootstrap && hasNewerAnswer(bootstrap.conversation?.messages, this.shown())) {
            this.timer = null;
            this.onNewer(bootstrap);

            return;
        }

        if (Date.now() - this.startedAt >= BUSY_POLL_LIMIT_MS) {
            this.timer = null;
            this.onGiveUp();

            return;
        }

        this.schedule();
    }
}

export interface FailedTurnHandlers {
    onRetry: () => void;
    onDismiss: () => void;
}

/**
 * The error bubble under a message that did not get an answer. It lives only
 * in the drawer, never in the saved thread. `checking` shows the "Checking
 * again" line in place of the buttons while a busy thread is being watched.
 */
export function renderFailedTurn(text: string, handlers: FailedTurnHandlers, checking = false): HTMLElement {
    const bubble = h('div', { class: 'bubble error' });
    bubble.append(paragraphs(text));

    const turn = h('div', { class: 'turn assistant failed', role: 'alert' }, bubble);
    turn.append(checking ? checkingLine() : retryButtons(handlers));

    return turn;
}

export function showRetryButtons(turn: HTMLElement, handlers: FailedTurnHandlers): void {
    turn.querySelector('.checking')?.remove();
    turn.querySelector('.retry-row')?.remove();
    turn.append(retryButtons(handlers));
}

function checkingLine(): HTMLElement {
    return h('div', { class: 'waiting checking', role: 'status' }, h('span', { class: 'dots' }, h('span'), h('span'), h('span')), RETRY_COPY.checkingAgain);
}

function retryButtons(handlers: FailedTurnHandlers): HTMLElement {
    return h(
        'div',
        { class: 'retry-row' },
        h('button', { class: 'btn secondary retry', type: 'button', onclick: () => handlers.onRetry() }, RETRY_COPY.tryAgain),
        h('button', { class: 'btn ghost dismiss', type: 'button', onclick: () => handlers.onDismiss() }, RETRY_COPY.dismiss),
    );
}
