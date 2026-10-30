import { ApiError, AssistantApi } from './api';
import { h, icons, paragraphs, svg, timeLabel } from './dom';
import { askHelp, hasIntercom, setIntercomHidden } from './intercom';
import { styles } from './styles';
import type { Action, Handoff, Message, PlanChange, ReviewPlan, TurnResponse, WidgetSettings } from './types';

const COPY = {
    launcher: 'Assistant',
    title: 'Assistant',
    subtitle: 'Does tasks for you. For questions about how something works, use Help.',
    intro: "I'm your assistant. Tell me what to do and I'll do it: add people, set up facilities, fill a shift, change a setting. If you want to know how something works, Help is the place to ask.",
    placeholder: 'Tell me what to do',
    send: 'Send',
    attach: 'Add a file',
    close: 'Close',
    working: 'Working on it',
    askHelpInstead: 'Ask Help instead',
    handoff: "I can't do that one, but Help can take it from here.",
    askHelp: 'Ask Help',
    noHelp: 'Open Help from the menu to send this.',
    copy: 'Copy',
    copied: 'Copied',
    undo: 'Undo',
    undone: 'Undone',
    undoing: 'Undoing',
    reviewTitle: 'Review these changes',
    reviewHint: 'Take out anything you do not want, then approve.',
    needsCare: 'Needs extra care. Check to include it.',
    approve: 'Approve and apply',
    setAside: 'Set aside',
    remove: 'Remove',
    applying: 'Applying your changes.',
    setAsideDone: 'Set aside. Ask me when you want to pick it up again.',
    nothingLeft: 'Nothing left to apply.',
    fileAdded: 'I added a file',
    uploading: 'Adding your file',
    enterHint: 'Enter to send, Shift+Enter for a new line',
};

const OPEN_KEY = 'unified-assistant:open';

/**
 * SSO's review plan is an ai-core proposal: 'drafted' and 'refined' are still
 * open for the admin; 'approved' / 'executing' are being applied; 'rejected'
 * is set aside. The widget's own optimistic words are kept alongside.
 */
const OPEN_PLAN_STATUSES = ['drafted', 'refined', 'proposed', 'draft', 'pending'];
const SET_ASIDE_PLAN_STATUSES = ['rejected', 'set_aside'];

/** Host pages listen for this to refetch what the assistant just changed. */
const ACTION_EVENT = 'unified-assistant:action';

/** An action with no summary is not an action: nothing to show, nothing to announce. */
function realActions(actions: Action[] | null | undefined): Action[] {
    return (actions ?? []).filter((action) => typeof action.summary === 'string' && action.summary.trim() !== '');
}

function announceActions(actions: Action[], undone?: number): void {
    const detail: { actions: Action[]; undone?: number } = { actions };

    if (undone !== undefined) {
        detail.undone = undone;
    }

    window.dispatchEvent(new CustomEvent(ACTION_EVENT, { detail }));
}

interface SettingsWithContext extends WidgetSettings {
    setup_app?: string | null;
}

class AssistantWidget {
    private api: AssistantApi;

    private root: ShadowRoot;

    private host: HTMLElement;

    private launcher!: HTMLButtonElement;

    private scrim!: HTMLDivElement;

    private drawer!: HTMLElement;

    private list!: HTMLDivElement;

    private input!: HTMLTextAreaElement;

    private sendButton!: HTMLButtonElement;

    private attachButton!: HTMLButtonElement;

    private fileInput!: HTMLInputElement;

    private messages: Message[] = [];

    private pendingReview: ReviewPlan | null = null;

    private open = false;

    private busy = false;

    private booted = false;

    private setupApp: string | null;

    private waitingRow: HTMLElement | null = null;

    private lastFocus: Element | null = null;

    constructor(settings: SettingsWithContext) {
        this.api = new AssistantApi(settings);
        this.setupApp = settings.setup_app ?? null;
        this.host = document.createElement('unified-assistant');
        this.root = this.host.attachShadow({ mode: 'open' });
        this.syncTheme();
        this.build();
    }

    async boot(): Promise<void> {
        let bootstrap;

        try {
            bootstrap = await this.api.bootstrap();
        } catch {
            return;
        }

        if (!bootstrap || bootstrap.enabled === false) {
            return;
        }

        this.messages = (bootstrap.conversation?.messages ?? []).slice();
        this.pendingReview = bootstrap.review ?? null;
        this.booted = true;

        document.body.append(this.host);
        this.renderMessages();
        this.launcher.hidden = false;

        if (bootstrap.notice) {
            this.list.append(h('div', { class: 'notice' }, bootstrap.notice));
        }

        let wasOpen = false;

        try {
            wasOpen = sessionStorage.getItem(OPEN_KEY) === '1';
        } catch {
            wasOpen = false;
        }

        if (wasOpen) {
            this.setOpen(true, false);
        }

        window.addEventListener('pagehide', () => setIntercomHidden(false));
    }

    /* Public API (window.UnifiedAssistant) */

    openDrawer(): void {
        if (this.booted) {
            this.setOpen(true);
        }
    }

    closeDrawer(): void {
        this.setOpen(false);
    }

    ask(text: string): void {
        if (!this.booted) {
            return;
        }

        this.setOpen(true);
        void this.send(text);
    }

    setSetupApp(slug: string | null): void {
        this.setupApp = slug;
    }

    /* Rendering */

    private build(): void {
        const style = document.createElement('style');
        style.textContent = styles;

        this.launcher = h(
            'button',
            { class: 'launcher', type: 'button', hidden: true, 'aria-haspopup': 'dialog', 'aria-expanded': 'false', onclick: () => this.setOpen(true) },
            svg(icons.sparkles, 18),
            COPY.launcher,
        );

        this.scrim = h('div', { class: 'scrim', onclick: () => this.setOpen(false) });

        this.list = h('div', { class: 'list', role: 'log', 'aria-live': 'polite', 'aria-relevant': 'additions' });

        this.input = h('textarea', {
            rows: '1',
            placeholder: COPY.placeholder,
            'aria-label': COPY.placeholder,
            onkeydown: (event: Event) => this.onComposerKey(event as KeyboardEvent),
            oninput: () => this.autosize(),
        });

        this.sendButton = h('button', { class: 'icon-btn send', type: 'button', 'aria-label': COPY.send, onclick: () => void this.send() }, svg(icons.send));

        this.fileInput = h('input', { type: 'file', class: 'sr', tabindex: '-1', 'aria-hidden': 'true', onchange: () => void this.onFilePicked() });
        this.attachButton = h('button', { class: 'icon-btn', type: 'button', 'aria-label': COPY.attach, title: COPY.attach, onclick: () => this.fileInput.click() }, svg(icons.paperclip));

        const header = h(
            'header',
            { class: 'header' },
            h('div', { class: 'title-wrap' }, h('h2', { id: 'ua-title' }, svg(icons.sparkles, 16), COPY.title), h('div', { class: 'subtitle' }, COPY.subtitle)),
            h('button', { class: 'icon-btn', type: 'button', 'aria-label': COPY.close, onclick: () => this.setOpen(false) }, svg(icons.close)),
        );

        const footer = h(
            'footer',
            { class: 'footer' },
            h('div', { class: 'composer' }, this.attachButton, this.input, this.sendButton),
            h(
                'div',
                { class: 'footer-row' },
                h('span', {}, COPY.enterHint),
                h('button', { class: 'link', type: 'button', onclick: () => this.askHelpInstead() }, COPY.askHelpInstead),
            ),
            this.fileInput,
        );

        this.drawer = h('section', { class: 'drawer', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'ua-title', 'aria-hidden': 'true' }, header, this.list, footer);

        this.drawer.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                this.setOpen(false);
            }
        });

        this.root.append(style, this.launcher, this.scrim, this.drawer);
    }

    private setOpen(open: boolean, persist = true, toggleIntercom = true): void {
        if (open === this.open) {
            return;
        }

        this.open = open;
        this.drawer.classList.toggle('open', open);
        this.scrim.classList.toggle('open', open);
        this.drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
        this.launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
        this.launcher.hidden = open;

        if (toggleIntercom) {
            setIntercomHidden(open);
        }

        if (persist) {
            try {
                sessionStorage.setItem(OPEN_KEY, open ? '1' : '0');
            } catch {
                /* private mode: the drawer simply reopens closed */
            }
        }

        if (open) {
            this.lastFocus = document.activeElement;
            this.scrollToEnd();
            window.setTimeout(() => this.input.focus(), 50);
        } else if (this.lastFocus instanceof HTMLElement && this.lastFocus.isConnected) {
            this.lastFocus.focus();
        } else {
            this.launcher.focus();
        }
    }

    private renderMessages(): void {
        this.list.replaceChildren();

        if (this.messages.length === 0) {
            this.list.append(this.renderTurn({ id: 'intro', role: 'assistant', content: COPY.intro }));
        }

        for (const message of this.messages) {
            this.list.append(this.renderTurn(message));
        }

        if (this.pendingReview && !this.messages.some((m) => m.review?.id === this.pendingReview?.id)) {
            this.list.append(h('div', { class: 'turn assistant' }, this.renderReview(this.pendingReview)));
        }

        this.scrollToEnd();
    }

    private renderTurn(message: Message): HTMLElement {
        const turn = h('div', { class: `turn ${message.role}`, 'data-id': String(message.id) });

        if (message.role === 'assistant' && message.activity && message.activity.length > 0) {
            turn.append(h('div', { class: 'activity' }, ...message.activity.map((line) => h('div', {}, line))));
        }

        if (message.content) {
            const bubble = h('div', { class: `bubble${message.tone ? ` ${message.tone}` : ''}` });
            bubble.append(paragraphs(message.content));
            turn.append(bubble);
        }

        const actions = realActions(message.actions);

        if (actions.length > 0) {
            turn.append(h('div', { class: 'chips' }, ...actions.map((action) => this.renderChip(action))));
        }

        if (message.review) {
            turn.append(this.renderReview(message.review));
        }

        if (message.handoff) {
            turn.append(this.renderHandoff(message.handoff));
        }

        const time = timeLabel(message.at);

        if (time) {
            turn.append(h('div', { class: 'time' }, time));
        }

        return turn;
    }

    private renderChip(action: Action): HTMLElement {
        const undone = Boolean(action.undone_at);
        const chip = h('div', { class: `chip${undone ? ' undone' : ''}` });
        const mark = h('span', { class: 'mark' }, svg(icons.check, 16));
        const meta = [action.app, timeLabel(action.at)].filter(Boolean).join(' · ');
        const text = h('div', { class: 'text' }, h('div', {}, action.summary), meta ? h('div', { class: 'meta' }, meta) : null);

        chip.append(mark, text);

        if (undone) {
            chip.append(h('span', { class: 'status' }, COPY.undone));
        } else if (action.undoable) {
            const button = h('button', { class: 'btn ghost sm', type: 'button' }, COPY.undo);
            button.addEventListener('click', () => void this.undo(action, chip, button));
            chip.append(button);
        }

        return chip;
    }

    private async undo(action: Action, chip: HTMLElement, button: HTMLButtonElement): Promise<void> {
        button.disabled = true;
        button.textContent = COPY.undoing;

        try {
            const result = await this.api.undo(action.id);

            if (result.ok === false || result.error) {
                throw new ApiError(200, result.error || result.message || 'That did not go through.');
            }

            action.undone_at = result.undone_at ?? new Date().toISOString();
            chip.classList.add('undone');
            button.replaceWith(h('span', { class: 'status' }, COPY.undone));
            announceActions([action], action.id);
        } catch (error) {
            button.disabled = false;
            button.textContent = COPY.undo;
            this.appendNotice(this.errorText(error));
        }
    }

    private renderHandoff(handoff: Handoff): HTMLElement {
        const card = h('div', { class: 'card' }, h('div', { class: 'card-title' }, COPY.handoff));

        if (hasIntercom()) {
            card.append(
                h('div', { class: 'quote' }, handoff.question),
                h(
                    'div',
                    { class: 'row-actions' },
                    h(
                        'button',
                        {
                            class: 'btn primary',
                            type: 'button',
                            onclick: () => this.handOffToHelp(handoff.question),
                        },
                        COPY.askHelp,
                    ),
                ),
            );
        } else {
            const copyButton = h('button', { class: 'btn secondary sm', type: 'button' }, svg(icons.copy, 14), COPY.copy);
            copyButton.addEventListener('click', () => {
                void navigator.clipboard?.writeText(handoff.question).then(() => {
                    copyButton.replaceChildren(svg(icons.check, 14), COPY.copied);
                });
            });

            card.append(h('div', { class: 'quote' }, handoff.question), h('div', { class: 'row-actions' }, copyButton, h('span', { class: 'hint' }, COPY.noHelp)));
        }

        return card;
    }

    private renderReview(plan: ReviewPlan): HTMLElement {
        const card = h('div', { class: 'card review', 'data-plan': plan.id });
        const resolved = !OPEN_PLAN_STATUSES.includes(plan.status);

        card.append(h('div', { class: 'card-title' }, COPY.reviewTitle), h('div', { class: 'hint' }, COPY.reviewHint));

        if (plan.changes.length === 0) {
            card.append(h('div', { class: 'hint' }, COPY.nothingLeft));
        }

        const rows = h('div', { class: 'changes' });
        const careBoxes = new Map<number, HTMLInputElement>();

        for (const change of plan.changes) {
            rows.append(this.renderChange(plan, change, careBoxes, resolved, card));
        }

        card.append(rows);

        if (!resolved) {
            const approve = h('button', { class: 'btn primary', type: 'button', disabled: plan.changes.length === 0 }, COPY.approve);
            const setAside = h('button', { class: 'btn secondary', type: 'button' }, COPY.setAside);

            approve.addEventListener('click', () => {
                const confirmed = [...careBoxes.entries()].filter(([, box]) => box.checked).map(([index]) => index);
                void this.reviewAction(plan, 'approve', { plan_hash: plan.plan_hash, confirmed_danger: confirmed }, card, COPY.applying);
            });
            setAside.addEventListener('click', () => {
                void this.reviewAction(plan, 'set-aside', {}, card, COPY.setAsideDone);
            });

            card.append(h('div', { class: 'row-actions' }, approve, setAside));
        } else {
            card.classList.add('done');
            card.append(h('div', { class: 'hint' }, SET_ASIDE_PLAN_STATUSES.includes(plan.status) ? COPY.setAsideDone : COPY.applying));
        }

        return card;
    }

    private renderChange(plan: ReviewPlan, change: PlanChange, careBoxes: Map<number, HTMLInputElement>, resolved: boolean, card: HTMLElement): HTMLElement {
        const body = h('div', { class: 'body' }, h('div', { class: 'what' }, change.title));
        const delta = change.from ? `${change.from} to ${change.to}` : change.to;

        if (delta) {
            body.append(h('div', { class: 'delta' }, delta));
        }

        if (change.why) {
            body.append(h('div', { class: 'why' }, change.why));
        }

        const needsCare = change.needs_confirmation || change.risk === 'high';

        if (needsCare && !resolved) {
            const box = h('input', { type: 'checkbox', id: `ua-care-${plan.id}-${change.index}` });
            careBoxes.set(change.index, box);
            body.append(h('label', { class: 'care', for: box.id }, box, COPY.needsCare));
        }

        const row = h('div', { class: 'change' }, body);

        if (!resolved) {
            const remove = h('button', { class: 'icon-btn remove', type: 'button', 'aria-label': `${COPY.remove}: ${change.title}`, title: COPY.remove }, svg(icons.close, 14));
            remove.addEventListener('click', () => {
                void this.reviewAction(plan, 'remove', { plan_hash: plan.plan_hash, index: change.index }, card, null);
            });
            row.append(remove);
        }

        return row;
    }

    private async reviewAction(plan: ReviewPlan, verb: 'approve' | 'remove' | 'set-aside', body: Record<string, unknown>, card: HTMLElement, doneText: string | null): Promise<void> {
        card.querySelectorAll('button').forEach((button) => (button.disabled = true));

        try {
            const result = await this.api.review(plan.id, verb, body);

            if (result.error) {
                throw new ApiError(200, result.error);
            }

            if (result.review) {
                const next = result.review;
                this.pendingReview = next;
                this.messages.forEach((message) => {
                    if (message.review?.id === next.id) {
                        message.review = next;
                    }
                });
                card.replaceWith(this.renderReview(next));
            } else {
                this.pendingReview = null;
                this.messages.forEach((message) => {
                    if (message.review?.id === plan.id) {
                        message.review = { ...plan, status: verb === 'set-aside' ? 'set_aside' : 'approved' };
                    }
                });
                card.classList.add('done');
                card.querySelectorAll('.row-actions, .remove, .care').forEach((el) => el.remove());
                card.append(h('div', { class: 'hint' }, doneText ?? COPY.applying));
            }

            if (result.reply) {
                this.push({ id: `r-${Date.now()}`, role: 'assistant', content: result.reply, at: new Date().toISOString() });
            }

            if (result.notice) {
                this.appendNotice(result.notice);
            }
        } catch (error) {
            card.querySelectorAll('button').forEach((button) => (button.disabled = false));
            this.appendNotice(this.errorText(error));
        }
    }

    /* Composer */

    private onComposerKey(event: KeyboardEvent): void {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            void this.send();
        }
    }

    private autosize(): void {
        this.input.style.height = 'auto';
        this.input.style.height = `${Math.min(this.input.scrollHeight, 140)}px`;
    }

    private async send(preset?: string): Promise<void> {
        const text = (preset ?? this.input.value).trim();

        if (!text || this.busy) {
            return;
        }

        if (preset === undefined) {
            this.input.value = '';
            this.autosize();
        }

        this.push({ id: `u-${Date.now()}`, role: 'user', content: text, at: new Date().toISOString() });
        this.setBusy(true);

        try {
            const turn = await this.api.send(text, this.setupApp);
            this.acceptTurn(turn);
        } catch (error) {
            this.push({ id: `e-${Date.now()}`, role: 'assistant', content: this.errorText(error), tone: 'error', at: new Date().toISOString() });
        } finally {
            this.setBusy(false);
        }
    }

    private acceptTurn(turn: TurnResponse): void {
        const actions = realActions(turn.actions);

        const message: Message = {
            id: turn.message_id ?? `a-${Date.now()}`,
            role: 'assistant',
            content: turn.reply ?? '',
            at: new Date().toISOString(),
            activity: turn.activity ?? null,
            actions,
            review: turn.review ?? null,
            handoff: turn.handoff ?? null,
        };

        if (turn.error) {
            message.content = message.content || turn.error;
            message.tone = 'error';
        }

        if (turn.review) {
            this.pendingReview = turn.review;
        }

        if (message.content || message.actions?.length || message.review || message.handoff) {
            this.push(message);
        }

        if (turn.notice) {
            this.appendNotice(turn.notice);
        }

        if (actions.length > 0) {
            announceActions(actions);
        }
    }

    private async onFilePicked(): Promise<void> {
        const file = this.fileInput.files?.[0];
        this.fileInput.value = '';

        if (!file || this.busy) {
            return;
        }

        this.setBusy(true, COPY.uploading);

        try {
            const result = await this.api.upload(file);

            if (result.ok === false || result.error) {
                throw new ApiError(200, result.error || 'That file did not go through.');
            }

            this.setBusy(false);
            await this.send(`${COPY.fileAdded}: ${file.name}`);
        } catch (error) {
            this.setBusy(false);
            this.push({ id: `e-${Date.now()}`, role: 'assistant', content: this.errorText(error), tone: 'error', at: new Date().toISOString() });
        }
    }

    private askHelpInstead(): void {
        if (!this.handOffToHelp('')) {
            this.appendNotice(COPY.noHelp);
        }
    }

    /**
     * Closes the drawer first, then opens Help. askHelp() restores the Intercom
     * launcher itself and nothing may send Intercom('update') after it: an update
     * resets the Messenger composer and loses the prefilled question.
     */
    private handOffToHelp(question: string): boolean {
        if (!hasIntercom()) {
            return false;
        }

        this.setOpen(false, true, false);

        return askHelp(question);
    }

    /* Helpers */

    private push(message: Message): void {
        this.messages.push(message);

        if (this.messages.length === 1) {
            this.list.querySelector('[data-id="intro"]')?.remove();
        }

        this.list.append(this.renderTurn(message));
        this.scrollToEnd();
    }

    private setBusy(busy: boolean, label: string = COPY.working): void {
        this.busy = busy;
        this.sendButton.disabled = busy;
        this.attachButton.disabled = busy;
        this.waitingRow?.remove();
        this.waitingRow = null;

        if (busy) {
            this.waitingRow = h('div', { class: 'turn assistant' }, h('div', { class: 'waiting' }, h('span', { class: 'dots' }, h('span'), h('span'), h('span')), label));
            this.list.append(this.waitingRow);
            this.scrollToEnd();
        }
    }

    private appendNotice(text: string): void {
        this.list.append(h('div', { class: 'notice' }, text));
        this.scrollToEnd();
    }

    private errorText(error: unknown): string {
        if (error instanceof ApiError) {
            return error.message;
        }

        return 'That did not go through. Try again.';
    }

    private scrollToEnd(): void {
        window.requestAnimationFrame(() => {
            this.list.scrollTop = this.list.scrollHeight;
        });
    }

    private syncTheme(): void {
        const apply = (): void => {
            const html = document.documentElement;
            const dark = html.classList.contains('dark') || html.dataset.theme === 'dark';
            const light = html.classList.contains('light') || html.dataset.theme === 'light';
            this.host.dataset.theme = dark ? 'dark' : light ? 'light' : '';

            if (!this.host.dataset.theme) {
                delete this.host.dataset.theme;
            }
        };

        apply();
        new MutationObserver(apply).observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme'] });
    }
}

declare global {
    interface Window {
        UnifiedAssistant?: {
            open(): void;
            close(): void;
            ask(text: string): void;
            setSetupApp(slug: string | null): void;
        };
    }
}

function readSettings(): SettingsWithContext | null {
    const script = (document.currentScript as HTMLScriptElement | null) ?? document.querySelector<HTMLScriptElement>('script[data-unified-assistant]');
    const raw = script?.dataset.unifiedAssistant;

    if (!raw) {
        return null;
    }

    try {
        return JSON.parse(raw) as SettingsWithContext;
    } catch {
        return null;
    }
}

(function main(): void {
    if (window.UnifiedAssistant) {
        return;
    }

    const settings = readSettings();

    if (!settings) {
        return;
    }

    const widget = new AssistantWidget(settings);

    window.UnifiedAssistant = {
        open: () => widget.openDrawer(),
        close: () => widget.closeDrawer(),
        ask: (text: string) => widget.ask(text),
        setSetupApp: (slug: string | null) => widget.setSetupApp(slug),
    };

    const start = (): void => void widget.boot();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
})();
