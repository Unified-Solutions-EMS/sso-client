import { ApiError, AssistantApi, type ReviewVerb } from './api';
import { pendingChoices, renderChoice, setRowBusy, showRowError, stillNeedText } from './choice';
import { BarAvoidance, SCROLLER_ATTRIBUTE, SCROLLER_PADDING_VAR } from './avoid';
import { dayLabel, fileSize, h, icons, link, paragraphs, shortUrl, svg, timeLabel } from './dom';
import { askHelp, hasIntercom, setIntercomHidden } from './intercom';
import { BusyPoller, DRAFT_NAME_PREFIX, type FailedTurnHandlers, SlowTurnTimer, clearDraftIfSent, draftName, failedTurnError, isBusy, readDraft, renderFailedTurn, saveDraft, showRetryButtons } from './retry';
import { type LiveConversation, type LiveMessage, type LiveTyping, SupportSocket, liveToMessage } from './realtime';
import { RatingCard } from './rating';
import { SeenTracker } from './seen';
import { MessageSound } from './sound';
import { styles } from './styles';
import { UPLOAD_COPY, stillReading, waitUntilRead } from './upload';
import type { Action, BootstrapResponse, CallbackResponse, Conversation, ConversationSummary, Handoff, Intro, Message, MessageAttachment, MessageLink, PlanChange, Rating, Reach, ReviewPlan, ReviewResponse, Suggestion, Support, Tour, TourStep, TurnResponse, WidgetSettings } from './types';

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
    teamHandoff: "I can't finish this one, but our team can.",
    talkToTeam: 'Talk to our team',
    teamPrompt: 'Tell me what you need and I will pass it to our team.',
    toOurTeam: 'To our team',
    teamHasConversation: 'Our team has this conversation',
    isTyping: 'is typing',
    assigned: 'from our team has your conversation',
    resolved: 'Our team marked this resolved.',
    teamTag: 'Unified',
    removeFile: 'Remove',
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
    conversations: 'Conversations',
    newConversation: 'New conversation',
    noConversations: 'No previous conversations yet.',
    untitled: 'Untitled conversation',
    reopened: 'Reopened',
    thingsICanDo: 'Things I can do',
    ideas: 'Ideas',
    hideIdeas: 'Hide ideas',
    introTitle: 'Meet your Assistant',
    introBody: 'Tell it what to do and it does it: add people, set up facilities, fill a shift, change a setting.',
    tryIt: 'Try it',
    dismiss: 'Dismiss',
    walkthrough: 'Walkthrough',
    feedbackQuestion: 'Did I handle that correctly?',
    yes: 'Yes',
    no: 'No',
    feedbackThanks: 'Thanks for letting me know.',
    feedbackSorry: 'Sorry about that. Our team will look at this conversation.',
    feedbackFollowUp: 'Would you like someone from our team to follow up with you?',
    followUpYes: 'Yes, please',
    followUpNo: 'No thanks',
    feedbackWillContact: "Thanks. We'll be in touch.",
    feedbackNoted: 'Thanks for letting us know.',
    sounds: 'Sounds',
    on: 'On',
    off: 'Off',
};

/**
 * The words that change for someone the Assistant only answers questions for
 * (`reach: none`, every member of the agency who is not an administrator): it
 * changes nothing for them, so it never says "tell me what to do".
 */
const MEMBER_COPY = {
    subtitle: 'Ask how something works, or talk to our team.',
    subtitleNoTeam: 'Ask how something works. For anything else, use Help.',
    intro: 'Hi. Ask me how something works in your apps, or tell me what is going on and I will pass it to our team.',
    introNoTeam: 'Hi. Ask me how something works in your apps.',
    placeholder: 'Ask a question',
};

/**
 * An Assistant-only conversation is offered the rating card once the person
 * has done nothing for this long after a reply.
 */
const RATING_IDLE_MS = 2 * 60 * 1000;

/** After a 4 or 5 sent from New conversation, the thanks shows this long before the new thread. */
const RATING_THEN_NEW_MS = 1500;

/** Skip hides the rating card for that thread in this tab (`rating-skipped:<conversation id>`). */
const RATING_SKIPPED_PREFIX = 'rating-skipped:';

/** How long the thank-you after a yes stays before the card goes. */
const FEEDBACK_THANKS_MS = 4000;

/**
 * Everything the widget keeps in sessionStorage sits under this prefix, scoped
 * to the bootstrap's `viewer` (`unified-assistant:<viewer>:<name>`): SSO
 * changes `viewer` for every user and every login, so a different person, or
 * the same person after signing in again, starts clean in the same tab. An SSO
 * that sends no `viewer` gets the unscoped names (`unified-assistant:<name>`).
 */
const STORAGE_PREFIX = 'unified-assistant:';

const OPEN_NAME = 'open';

/**
 * Set when the admin closes the first-run callout (X) or sends a first message,
 * so callout and badge stay away for this tab and login. SSO keeps sending
 * `intro.show` until the admin's first real turn, which it records itself; the
 * widget never tells SSO to stop.
 */
const INTRO_HIDDEN_NAME = 'intro-dismissed';

const INTRO_DELAY_MS = 1500;

/** The next walkthrough step follows the reply after a beat, so it reads as a follow-up. */
const TOUR_ADVANCE_DELAY_MS = 600;

/**
 * Walkthrough progress per thread (`tour:<conversation id>`), so a page change
 * inside the app resumes at the same step. Holds the steps too: once the
 * callout is dismissed SSO stops sending `tour`.
 */
const TOUR_NAME_PREFIX = 'tour:';

const UNSCOPED_NAMES = [OPEN_NAME, INTRO_HIDDEN_NAME];

function viewerOf(bootstrap: BootstrapResponse): string | null {
    return typeof bootstrap.viewer === 'string' && bootstrap.viewer.trim() !== '' ? bootstrap.viewer.trim() : null;
}

/** Whether a stored key (minus the prefix) belongs to this viewer's set of names. */
function belongsToViewer(rest: string, viewer: string | null): boolean {
    if (viewer !== null) {
        return rest.startsWith(`${viewer}:`);
    }

    return UNSCOPED_NAMES.includes(rest) || rest.startsWith(TOUR_NAME_PREFIX) || rest.startsWith(DRAFT_NAME_PREFIX);
}

/** Drops what earlier viewers (other people, earlier logins) left in this tab. */
function pruneStorage(viewer: string | null): void {
    try {
        const stale: string[] = [];

        for (let i = 0; i < sessionStorage.length; i++) {
            const key = sessionStorage.key(i);

            if (key !== null && key.startsWith(STORAGE_PREFIX) && !belongsToViewer(key.slice(STORAGE_PREFIX.length), viewer)) {
                stale.push(key);
            }
        }

        stale.forEach((key) => sessionStorage.removeItem(key));
    } catch {
        /* private mode: nothing stored to prune */
    }
}

interface StoredTour {
    step: number;
    tour: Tour;
}

function validTour(tour: Tour | null | undefined): Tour | null {
    if (!tour || !Array.isArray(tour.steps)) {
        return null;
    }

    const steps = tour.steps
        .filter((step): step is TourStep => Boolean(step) && typeof step.text === 'string' && step.text.trim() !== '')
        .map((step) => ({
            text: step.text,
            kind: (step.kind === 'action' ? 'action' : 'question') as TourStep['kind'],
            suggestions: (Array.isArray(step.suggestions) ? step.suggestions : []).filter((s) => s && typeof s.prompt === 'string' && s.prompt.trim() !== ''),
        }));

    return steps.length > 0 ? { steps, closing: typeof tour.closing === 'string' ? tour.closing : '' } : null;
}

const DOCK_ATTRIBUTE = 'data-unified-assistant';

const DOCK_STYLE_ID = 'unified-assistant-dock';

function ensureDockStyle(): void {
    if (document.getElementById(DOCK_STYLE_ID)) {
        return;
    }

    const style = document.createElement('style');
    style.id = DOCK_STYLE_ID;
    const target = `html[${DOCK_ATTRIBUTE}="docked"] [${SCROLLER_ATTRIBUTE}]`;
    const padding = (extra: number): string => `padding-bottom: calc(var(${SCROLLER_PADDING_VAR}, 0px) + ${extra}px) !important;`;

    style.textContent = `${target} { ${padding(150)} }
@media (max-width: 639px) { ${target} { ${padding(120)} } }`;
    document.head.append(style);
}

/**
 * SSO's review plan is an ai-core proposal: 'drafted' and 'refined' are still
 * open for the admin; 'approved' / 'executing' are being applied; 'rejected'
 * is set aside. The widget's own optimistic words are kept alongside.
 */
const OPEN_PLAN_STATUSES = ['drafted', 'refined', 'proposed', 'draft', 'pending'];
const SET_ASIDE_PLAN_STATUSES = ['rejected', 'set_aside'];
const APPLIED_PLAN_STATUSES = ['approved', 'executing', 'applied', 'completed'];

/** Host pages listen for this to refetch what the assistant just changed. */
const ACTION_EVENT = 'unified-assistant:action';

/** How often to ask for our team's replies: drawer open and showing, or not. */
const TEAM_POLL_OPEN_MS = 5000;
const TEAM_POLL_HIDDEN_MS = 20000;
/** While the socket is connected, polling is only a safety net. */
const TEAM_POLL_LIVE_MS = 60000;
/** "{name} is typing" goes when no typing event has come for this long. */
const TYPING_SHOWN_MS = 6000;

function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    const letters = parts.length > 1 ? parts[0][0] + parts[parts.length - 1][0] : (parts[0] ?? '?').slice(0, 2);

    return letters.toUpperCase();
}

/** Files on a message: a link that opens in a new tab when there is one to download from. */
function renderAttachments(files: MessageAttachment[]): HTMLElement {
    return h(
        'div',
        { class: 'attachments' },
        ...files.map((file) => {
            const parts = [svg(icons.paperclip, 14), h('span', { class: 'name' }, file.name), file.size ? h('span', { class: 'size' }, fileSize(file.size)) : null];

            return file.url && /^https?:\/\//i.test(file.url)
                ? h('a', { class: 'file-chip', href: file.url, target: '_blank', rel: 'noopener noreferrer' }, ...parts)
                : h('span', { class: 'file-chip' }, ...parts);
        }),
    );
}

/** Pages to open with a reply: small chips under the message, each opening in a new tab. */
function renderLinks(links: MessageLink[] | null | undefined): HTMLElement | null {
    const chips = (links ?? []).flatMap((item) => {
        let url: URL;

        try {
            url = new URL(item.url);
        } catch {
            return [];
        }

        if (url.protocol !== 'https:' && url.protocol !== 'http:') {
            return [];
        }

        const label = typeof item.label === 'string' && item.label.trim() !== '' ? item.label.trim() : shortUrl(url);
        const chip = link(url, label, 'link-chip');
        chip.prepend(svg(icons.external, 14));

        return [chip];
    });

    return chips.length > 0 ? h('div', { class: 'links' }, ...chips) : null;
}

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

    private subtitleEl!: HTMLElement;

    /** SSO's AssistantReach for this person; null from an older SSO (the admin drawer). */
    private reach: Reach | null = null;

    private fileInput!: HTMLInputElement;

    private messages: Message[] = [];

    private pendingReview: ReviewPlan | null = null;

    private conversation: Conversation | null = null;

    private conversations: ConversationSummary[] = [];

    private suggestions: Suggestion[] = [];

    /** The Ideas link forces the pills on; otherwise they show only on an empty thread. */
    private ideasOpen = false;

    private suggestionsEl: HTMLElement | null = null;

    private panel!: HTMLElement;

    private panelList!: HTMLDivElement;

    private panelOpen = false;

    private historyButton!: HTMLButtonElement;

    private reopenedBar!: HTMLDivElement;

    private open = false;

    private busy = false;

    private booted = false;

    private setupApp: string | null;

    private waitingRow: HTMLElement | null = null;

    /** The error bubble under the last message that got no answer; never part of the saved thread. */
    private failedRow: HTMLElement | null = null;

    private retryHandlers: FailedTurnHandlers | null = null;

    private slowTimer = new SlowTurnTimer();

    private busyPoller: BusyPoller;

    private lastFocus: Element | null = null;

    private lifted = false;

    private avoidance: BarAvoidance;

    private badge!: HTMLSpanElement;

    private callout: HTMLElement | null = null;

    /** Set while the first-run intro is unseen: badge on, callout pending or showing. */
    private introPending = false;

    private intro: Intro | null = null;

    private introTimer: number | null = null;

    /** The walkthrough SSO offered with this boot's intro; only Try it starts it. */
    private tour: Tour | null = null;

    /** SSO's opaque per-user, per-login token that scopes this tab's storage; null from an older SSO. */
    private viewer: string | null = null;

    private tourActive = false;

    /** Index of the step whose pills are showing (awaiting the admin's turn). */
    private tourStep = 0;

    private tourPillsEl: HTMLElement | null = null;

    /** The pause before the next step is appended; cleared when the tour ends. */
    private tourAdvanceTimer: number | null = null;

    /** SSO's say on whether to ask "Did I handle that correctly?" after a completed task. */
    private askFeedback = false;

    /** The one feedback card showing; a new task or a new message replaces it. */
    private feedbackCard: HTMLElement | null = null;

    /** The end-of-conversation rating from SSO. Null: an older SSO, never asked. */
    private rating: Rating | null = null;

    /** The closing card showing at the bottom of the thread, if any. */
    private ratingCard: RatingCard | null = null;

    private ratingIdleTimer: number | null = null;

    /** The idle time passed while the drawer was closed: show the card when it opens. */
    private ratingDue = false;

    /** What to do once the card is answered or skipped (New conversation pressed before rating). */
    private afterRating: (() => void) | null = null;

    /** Our team behind the Assistant, from SSO. Null: an older SSO (no team, files sent at once). */
    private support: Support | null = null;

    /** Files added and read, waiting to go with the next message. */
    private pendingFiles: Array<{ id: number; name: string; size: number }> = [];

    private pendingEl!: HTMLDivElement;

    /** The next poll for our team's replies while a teammate has the conversation. */
    private pollTimer: number | null = null;

    /**
     * "Talk to our team" was chosen: the next message goes to our team
     * (escalate) instead of to the Assistant, until the chip is removed.
     */
    private teamMode = false;

    private teamChipEl!: HTMLDivElement;

    /** The standing "Talk to our team" item in the Conversations menu, or the status line once our team has it. */
    private teamEntryEl!: HTMLDivElement;

    /** Our team's replies the moment they are written, over the CRM's socket. */
    private socket: SupportSocket;

    /** "{name} is typing" below the last message. */
    private typingEl!: HTMLDivElement;

    private typingTimer: number | null = null;

    /** The teammate the CRM last said has the conversation. */
    private assignee: string | null = null;

    /** A soft chime when a reply from our team arrives. */
    private sound = new MessageSound();

    /** The Sounds switch in the Conversations panel. */
    private soundSwitch!: HTMLButtonElement;

    /** Read receipts: tells our team which of their replies the person has had on screen. */
    private seen!: SeenTracker;

    constructor(settings: SettingsWithContext) {
        this.api = new AssistantApi(settings);
        this.setupApp = settings.setup_app ?? null;
        this.host = document.createElement('unified-assistant');
        this.root = this.host.attachShadow({ mode: 'open' });
        this.syncTheme();
        this.build();
        this.seen = new SeenTracker(this.list, {
            send: (messageId) => this.sendSeen(messageId),
            visible: () => this.threadVisible(),
        });
        this.busyPoller = new BusyPoller(
            () => this.api.bootstrap(this.conversation?.id ?? null),
            () => this.messages,
            (bootstrap) => this.applyBootstrap(bootstrap),
            () => this.busyGaveUp(),
        );
        this.socket = new SupportSocket({
            live: (live) => this.onLive(live),
            message: (message) => this.onLiveMessage(message),
            conversation: (update) => this.onLiveConversation(update),
            typing: (typing) => this.onLiveTyping(typing),
        });
        this.avoidance = new BarAvoidance(this.host, (lifted) => {
            this.lifted = lifted;
            this.syncDock();
        });
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

        this.booted = true;
        this.viewer = viewerOf(bootstrap);
        pruneStorage(this.viewer);
        this.askFeedback = bootstrap.feedback?.ask === true && Boolean(this.api.canRecordFeedback());
        this.tour = bootstrap.intro?.show === true ? validTour(bootstrap.tour) : null;

        document.body.append(this.host);
        this.applyBootstrap(bootstrap);
        this.launcher.hidden = false;
        this.syncDock();
        this.avoidance.start();

        let wasOpen = false;

        try {
            wasOpen = sessionStorage.getItem(this.storageKey(OPEN_NAME)) === '1';
        } catch {
            wasOpen = false;
        }

        if (bootstrap.intro?.show === true) {
            this.offerIntro(bootstrap.intro);
        }

        if (wasOpen) {
            this.setOpen(true, false);
        }

        window.addEventListener('pagehide', () => setIntercomHidden(false));
        document.addEventListener('visibilitychange', () => {
            this.reschedulePoll();
            this.seen.check();
        });
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

    /**
     * Hides the first-run callout and badge for this tab and login (the X, or
     * the first message sent). SSO is not told: it keeps `intro.show` on until
     * it sees the admin's first turn, so a new login shows the callout again
     * until then.
     */
    dismissIntro(): void {
        if (this.introTimer !== null) {
            window.clearTimeout(this.introTimer);
            this.introTimer = null;
        }

        this.badge.hidden = true;
        this.hideCallout();

        if (!this.introPending) {
            return;
        }

        this.introPending = false;
        this.intro = null;

        try {
            sessionStorage.setItem(this.storageKey(INTRO_HIDDEN_NAME), '1');
        } catch {
            /* private mode: the callout returns on the next page */
        }
    }

    /* First-run callout */

    private offerIntro(intro: Intro): void {
        let hidden = false;

        try {
            hidden = sessionStorage.getItem(this.storageKey(INTRO_HIDDEN_NAME)) === '1';
        } catch {
            hidden = false;
        }

        if (hidden) {
            return;
        }

        this.intro = intro;
        this.introPending = true;
        this.badge.textContent = String(intro.badge ?? 1);
        this.badge.hidden = false;

        this.introTimer = window.setTimeout(() => {
            this.introTimer = null;

            if (this.introPending && !this.open) {
                this.showCallout(intro);
            }
        }, INTRO_DELAY_MS);
    }

    private showCallout(intro: Intro): void {
        this.hideCallout();

        this.callout = h(
            'div',
            { class: 'callout', role: 'region', 'aria-labelledby': 'ua-callout-title' },
            h(
                'div',
                { class: 'callout-head' },
                h('div', { class: 'callout-title', id: 'ua-callout-title' }, intro.title?.trim() || COPY.introTitle),
                h('button', { class: 'icon-btn dismiss', type: 'button', 'aria-label': COPY.dismiss, onclick: () => this.dismissIntro() }, svg(icons.close, 16)),
            ),
            h('div', { class: 'callout-body' }, intro.body?.trim() || COPY.introBody),
            h('div', { class: 'callout-actions' }, h('button', { class: 'btn primary', type: 'button', onclick: () => this.tryIt() }, intro.cta?.trim() || COPY.tryIt)),
        );

        this.root.append(this.callout);
        window.requestAnimationFrame(() => this.callout?.classList.add('in'));
    }

    private hideCallout(): void {
        this.callout?.remove();
        this.callout = null;
    }

    /* Walkthrough */

    /** The callout's button (SSO's `intro.cta`): open the drawer and, when SSO sent a tour, begin it. */
    private tryIt(): void {
        this.setOpen(true);

        if (!this.tour || this.tourActive || this.busy) {
            return;
        }

        this.tourActive = true;
        this.tourStep = 0;
        this.ideasOpen = false;
        this.renderSuggestions();
        this.renderTourStep();
        this.saveTour();
    }

    private storageKey(name: string): string {
        return this.viewer === null ? `${STORAGE_PREFIX}${name}` : `${STORAGE_PREFIX}${this.viewer}:${name}`;
    }

    private tourKey(): string | null {
        return this.conversation ? this.storageKey(`${TOUR_NAME_PREFIX}${String(this.conversation.id)}`) : null;
    }

    private saveTour(): void {
        const key = this.tourKey();

        if (!key || !this.tour) {
            return;
        }

        try {
            sessionStorage.setItem(key, JSON.stringify({ step: this.tourStep, tour: this.tour } satisfies StoredTour));
        } catch {
            /* private mode: the walkthrough lasts this page only */
        }
    }

    private cancelTourAdvance(): void {
        if (this.tourAdvanceTimer !== null) {
            window.clearTimeout(this.tourAdvanceTimer);
            this.tourAdvanceTimer = null;
        }
    }

    private clearStoredTour(): void {
        this.cancelTourAdvance();

        const key = this.tourKey();

        if (!key) {
            return;
        }

        try {
            sessionStorage.removeItem(key);
        } catch {
            /* nothing stored */
        }
    }

    /** After a bootstrap: pick the walkthrough up where this thread left it. */
    private resumeTour(): void {
        this.tourActive = false;
        this.tourPillsEl = null;

        const key = this.tourKey();

        if (!key) {
            return;
        }

        let stored: StoredTour | null = null;

        try {
            const raw = sessionStorage.getItem(key);
            stored = raw ? (JSON.parse(raw) as StoredTour) : null;
        } catch {
            stored = null;
        }

        const tour = validTour(stored?.tour);

        if (!tour || typeof stored?.step !== 'number' || stored.step < 0 || stored.step >= tour.steps.length) {
            return;
        }

        this.tour = tour;
        this.tourActive = true;
        this.tourStep = stored.step;
        this.renderSuggestions();
        this.renderTourStep();
    }

    private renderTourStep(): void {
        if (!this.tour) {
            return;
        }

        const step = this.tour.steps[this.tourStep];
        const pills = step.suggestions.map((suggestion) => {
            const pill = h('button', { class: 'pill', type: 'button' }, suggestion.label?.trim() || suggestion.prompt);
            pill.addEventListener('click', () => {
                // One click per step: the pills go at once and never come back, while the
                // step itself waits for a reply that finishes the exchange.
                this.tourPillsEl?.remove();
                this.tourPillsEl = null;
                void this.send(suggestion.prompt);
            });

            return pill;
        });

        this.tourPillsEl = pills.length > 0 ? h('div', { class: 'tour-pills' }, ...pills) : null;
        this.list.append(this.renderTourBubble(step.text, this.tourPillsEl));
        this.scrollToEnd();
    }

    private renderTourBubble(text: string, pills: HTMLElement | null): HTMLElement {
        const bubble = h('div', { class: 'bubble' });
        bubble.append(paragraphs(text));

        return h('div', { class: 'turn assistant tour' }, h('div', { class: 'tour-label' }, svg(icons.sparkles, 12), COPY.walkthrough), bubble, pills);
    }

    /**
     * A reply landed during the walkthrough. The step moves on only when the
     * exchange it started is finished: a `question` step after a reply SSO
     * marks complete (not a question, no confirmation gate) with no review
     * list open; an `action` step only once the reply carries a change (an
     * action chip or an approved review). Otherwise the step waits: the admin's
     * answers keep flowing as normal turns and the next complete reply is
     * checked again. The next step follows after a short pause.
     */
    private advanceTour(turn: TurnResponse): void {
        if (!this.tourActive || !this.tour || this.tourAdvanceTimer !== null) {
            return;
        }

        const step = this.tour.steps[this.tourStep];
        const reviewOpen = this.pendingReview !== null && OPEN_PLAN_STATUSES.includes(this.pendingReview.status);
        const applied = turn.review !== null && turn.review !== undefined && APPLIED_PLAN_STATUSES.includes(turn.review.status);
        const finished =
            step.kind === 'action'
                ? realActions(turn.actions).length > 0 || applied
                : (turn.turn_state ?? 'complete') === 'complete' && !reviewOpen;

        if (!finished) {
            return;
        }

        this.tourAdvanceTimer = window.setTimeout(() => {
            this.tourAdvanceTimer = null;
            this.nextTourStep();
        }, TOUR_ADVANCE_DELAY_MS);
    }

    private nextTourStep(): void {
        if (!this.tourActive || !this.tour) {
            return;
        }

        this.tourPillsEl?.remove();
        this.tourPillsEl = null;
        this.tourStep += 1;

        if (this.tourStep < this.tour.steps.length) {
            this.renderTourStep();
            this.saveTour();

            return;
        }

        if (this.tour.closing.trim() !== '') {
            this.list.append(this.renderTourBubble(this.tour.closing, null));
        }

        this.endTour();
        this.scrollToEnd();
    }

    private endTour(): void {
        this.clearStoredTour();
        this.cancelTourAdvance();
        this.tourActive = false;
        this.tourPillsEl?.remove();
        this.tourPillsEl = null;
        this.renderSuggestions();
    }

    /* Rendering */

    private build(): void {
        const style = document.createElement('style');
        style.textContent = styles;

        this.launcher = h(
            'button',
            {
                class: 'launcher',
                type: 'button',
                hidden: true,
                'aria-label': COPY.launcher,
                title: COPY.launcher,
                'aria-haspopup': 'dialog',
                'aria-expanded': 'false',
                onclick: () => this.setOpen(true),
            },
            svg(icons.sparkles, 20),
            h('span', { class: 'tip', 'aria-hidden': 'true' }, COPY.launcher),
        );

        this.badge = h('span', { class: 'badge', hidden: true });
        this.launcher.append(this.badge);

        this.scrim = h('div', { class: 'scrim', onclick: () => this.setOpen(false) });

        this.list = h('div', { class: 'list', role: 'log', 'aria-live': 'polite', 'aria-relevant': 'additions' });
        this.typingEl = h('div', { class: 'typing', role: 'status', hidden: true });

        this.input = h('textarea', {
            rows: '1',
            placeholder: COPY.placeholder,
            'aria-label': COPY.placeholder,
            onkeydown: (event: Event) => this.onComposerKey(event as KeyboardEvent),
            oninput: () => {
                this.stopRatingIdle();
                this.autosize();
                saveDraft(this.draftKey(), this.input.value);

                if (this.input.value.trim() !== '') {
                    this.socket.typing();
                }
            },
        });

        this.sendButton = h('button', { class: 'icon-btn send', type: 'button', 'aria-label': COPY.send, onclick: () => void this.send() }, svg(icons.send));

        this.pendingEl = h('div', { class: 'pending-files', hidden: true, 'aria-live': 'polite' });

        this.teamChipEl = h('div', { class: 'team-mode', hidden: true });
        this.teamEntryEl = h('div', { class: 'team-entry', hidden: true });

        this.fileInput = h('input', { type: 'file', class: 'sr', tabindex: '-1', 'aria-hidden': 'true', onchange: () => void this.onFilePicked() });
        this.attachButton = h('button', { class: 'icon-btn', type: 'button', 'aria-label': COPY.attach, title: COPY.attach, onclick: () => this.fileInput.click() }, svg(icons.paperclip));

        this.historyButton = h(
            'button',
            {
                class: 'icon-btn history',
                type: 'button',
                'aria-label': COPY.conversations,
                title: COPY.conversations,
                'aria-expanded': 'false',
                onclick: () => this.setPanelOpen(!this.panelOpen),
            },
            svg(icons.clock),
        );

        this.subtitleEl = h('div', { class: 'subtitle' }, COPY.subtitle);

        const header = h(
            'header',
            { class: 'header' },
            h('div', { class: 'title-wrap' }, h('h2', { id: 'ua-title' }, svg(icons.sparkles, 16), COPY.title), this.subtitleEl),
            this.historyButton,
            h('button', { class: 'icon-btn', type: 'button', 'aria-label': COPY.close, onclick: () => this.setOpen(false) }, svg(icons.close)),
        );

        this.reopenedBar = h('div', { class: 'reopened', hidden: true, role: 'status' });

        this.panelList = h('div', { class: 'threads' });
        this.panel = h(
            'section',
            { class: 'panel', 'aria-label': COPY.conversations, hidden: true },
            h('div', { class: 'panel-head' }, h('div', { class: 'panel-title' }, COPY.conversations), this.buildSoundSwitch()),
            h(
                'div',
                { class: 'panel-body' },
                h('button', { class: 'btn secondary new-conversation', type: 'button', onclick: () => void this.newConversation() }, svg(icons.plus, 14), COPY.newConversation),
                this.teamEntryEl,
                this.panelList,
            ),
        );

        const footer = h(
            'footer',
            { class: 'footer' },
            this.teamChipEl,
            this.pendingEl,
            h('div', { class: 'composer' }, this.attachButton, this.input, this.sendButton),
            h(
                'div',
                { class: 'footer-row' },
                h('span', {}, COPY.enterHint),
                h(
                    'span',
                    { class: 'footer-links' },
                    h('button', { class: 'link ideas', type: 'button', 'aria-pressed': 'false', onclick: () => this.toggleIdeas() }, COPY.ideas),
                    h('button', { class: 'link', type: 'button', onclick: () => this.askHelpInstead() }, COPY.askHelpInstead),
                ),
            ),
            this.fileInput,
        );

        const body = h('div', { class: 'body' }, this.reopenedBar, this.list, footer, this.panel);

        this.drawer = h('section', { class: 'drawer', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'ua-title', 'aria-hidden': 'true' }, header, body);

        this.drawer.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }

            if (this.panelOpen) {
                event.stopPropagation();
                this.setPanelOpen(false);

                return;
            }

            this.setOpen(false);
        });

        this.root.append(style, this.launcher, this.scrim, this.drawer);
    }

    private setOpen(open: boolean, persist = true, toggleIntercom = true): void {
        if (open === this.open) {
            return;
        }

        this.open = open;

        if (open) {
            this.hideCallout();
        } else if (this.introPending && this.intro && this.introTimer === null) {
            this.showCallout(this.intro);
        }

        this.drawer.classList.toggle('open', open);
        this.scrim.classList.toggle('open', open);
        this.drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
        this.launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
        this.launcher.hidden = open;
        this.syncDock();

        if (toggleIntercom) {
            setIntercomHidden(open);
        }

        if (this.booted) {
            this.avoidance.setActive(!open);
        }

        if (open && !this.introPending) {
            this.badge.hidden = true;
        }

        this.reschedulePoll();
        this.seen.check();

        if (!open && this.booted) {
            this.offerRating();
        } else if (open && this.ratingDue) {
            this.offerRating();
        }

        if (persist) {
            try {
                sessionStorage.setItem(this.storageKey(OPEN_NAME), open ? '1' : '0');
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
        this.seen.forget();
        this.list.replaceChildren();

        if (this.messages.length === 0) {
            this.list.append(this.renderTurn({ id: 'intro', role: 'assistant', content: this.copyFor('intro') }));
        }

        for (const message of this.messages) {
            this.list.append(this.renderTurn(message));
        }

        if (this.pendingReview && !this.messages.some((m) => m.review?.id === this.pendingReview?.id)) {
            this.list.append(h('div', { class: 'turn assistant' }, this.renderReview(this.pendingReview)));
        }

        this.renderSuggestions();
        this.scrollToEnd();
    }

    /* Threads */

    private applyBootstrap(bootstrap: BootstrapResponse): void {
        this.messages = (bootstrap.conversation?.messages ?? []).slice();
        this.pendingReview = bootstrap.review ?? null;
        this.conversation = bootstrap.conversation ?? null;
        this.conversations = (bootstrap.conversations ?? []).slice();
        this.suggestions = (bootstrap.suggestions ?? []).filter((s) => s && (s.kind === 'team' || (typeof s.prompt === 'string' && s.prompt.trim() !== '')));
        this.ideasOpen = false;
        this.waitingRow = null;
        this.tourActive = false;
        this.tourPillsEl = null;
        this.feedbackCard = null;
        this.rating = bootstrap.rating ?? null;
        this.ratingCard = null;
        this.ratingDue = false;
        this.afterRating = null;
        this.stopRatingIdle();
        this.busyPoller.stop();
        this.failedRow = null;
        this.support = bootstrap.support ?? null;
        this.seen.reset(this.support?.seen?.message_id);
        this.assignee = null;
        this.hideTyping();
        this.reach = bootstrap.reach ?? null;
        this.applyReach();
        this.teamMode = false;
        this.renderTeamMode();
        this.pendingFiles = [];
        this.renderPendingFiles();
        this.input.value = readDraft(this.draftKey());
        this.autosize();
        this.renderMessages();
        this.resumeTour();
        this.renderThreads();
        this.renderTeamEntry();

        if (bootstrap.notice) {
            this.appendNotice(bootstrap.notice);
        }

        this.syncPolling();

        if (this.support?.status === 'resolved') {
            this.offerRating();
        }
    }

    private setPanelOpen(open: boolean): void {
        if (open === this.panelOpen) {
            return;
        }

        this.panelOpen = open;
        this.panel.hidden = !open;
        this.seen.check();
        this.historyButton.setAttribute('aria-expanded', open ? 'true' : 'false');
        this.list.setAttribute('aria-hidden', open ? 'true' : 'false');

        if (open) {
            this.renderThreads();
            this.renderSoundSwitch();
            window.setTimeout(() => this.panel.querySelector<HTMLElement>('.new-conversation')?.focus(), 30);
        } else {
            this.historyButton.focus();
        }
    }

    private buildSoundSwitch(): HTMLButtonElement {
        this.soundSwitch = h('button', {
            class: 'sound-switch',
            type: 'button',
            role: 'switch',
            onclick: () => {
                this.sound.setMuted(!this.sound.isMuted());
                this.renderSoundSwitch();
            },
        });
        this.renderSoundSwitch();

        return this.soundSwitch;
    }

    private renderSoundSwitch(): void {
        const on = !this.sound.isMuted();
        this.soundSwitch.setAttribute('aria-checked', on ? 'true' : 'false');
        this.soundSwitch.replaceChildren(
            h('span', { class: 'sound-label' }, COPY.sounds),
            h('span', { class: 'sound-track', 'aria-hidden': 'true' }, h('span', { class: 'sound-thumb' })),
            h('span', { class: 'sound-state', 'aria-hidden': 'true' }, on ? COPY.on : COPY.off),
        );
    }

    private renderThreads(): void {
        const currentId = this.conversation ? String(this.conversation.id) : null;
        const previous = this.conversations.filter((thread) => String(thread.id) !== currentId);

        this.panelList.replaceChildren();

        if (previous.length === 0) {
            this.panelList.append(h('div', { class: 'threads-empty' }, COPY.noConversations));

            return;
        }

        for (const thread of previous) {
            const count = thread.message_count === 1 ? '1 message' : `${thread.message_count} messages`;
            const meta = [dayLabel(thread.last_activity_at), count].filter(Boolean).join(' · ');
            const button = h(
                'button',
                { class: 'thread', type: 'button', 'data-conversation': String(thread.id) },
                h('div', { class: 'thread-title' }, thread.title?.trim() || COPY.untitled),
                h('div', { class: 'thread-meta' }, meta),
            );
            button.addEventListener('click', () => void this.openConversation(thread));
            this.panelList.append(button);
        }
    }

    private async newConversation(rated = false): Promise<void> {
        if (this.busy) {
            return;
        }

        if (!rated && this.offerRating(() => void this.newConversation(true))) {
            this.setPanelOpen(false);

            return;
        }

        this.setBusy(true);

        try {
            const bootstrap = await this.api.clear();
            this.setBusy(false);
            this.clearStoredTour();
            this.applyBootstrap(bootstrap);
            this.reopenedBar.hidden = true;
            this.setPanelOpen(false);
            this.input.focus();
        } catch (error) {
            this.setBusy(false);
            this.appendNotice(this.errorText(error));
        }
    }

    private async openConversation(thread: ConversationSummary): Promise<void> {
        if (this.busy) {
            return;
        }

        this.setBusy(true);

        try {
            const bootstrap = await this.api.bootstrap(thread.id);
            this.setBusy(false);

            if (!bootstrap) {
                return;
            }

            this.applyBootstrap(bootstrap);
            this.reopenedBar.replaceChildren(svg(icons.clock, 14), `${COPY.reopened}: ${bootstrap.conversation?.title?.trim() || thread.title?.trim() || COPY.untitled}`);
            this.reopenedBar.hidden = false;
            this.setPanelOpen(false);
            this.offerRating();
        } catch (error) {
            this.setBusy(false);
            this.appendNotice(this.errorText(error));
        }
    }

    /* Suggestions */

    private toggleIdeas(): void {
        this.ideasOpen = !this.ideasOpen;
        this.renderSuggestions();
        this.scrollToEnd();
    }

    private renderSuggestions(): void {
        this.suggestionsEl?.remove();
        this.suggestionsEl = null;

        const ideas = this.drawer.querySelector<HTMLButtonElement>('.link.ideas');
        const offerTeam = this.canTalkToTeam();
        const placedTeam = this.suggestions.some((suggestion) => suggestion.kind === 'team');
        const shown = this.suggestions.filter((suggestion) => suggestion.kind !== 'team' || offerTeam);
        const count = shown.length + (offerTeam && !placedTeam ? 1 : 0);
        const visible = count > 0 && !this.tourActive && (this.ideasOpen || this.messages.length === 0);

        if (ideas) {
            ideas.hidden = count === 0 || this.tourActive;
            ideas.setAttribute('aria-pressed', this.ideasOpen ? 'true' : 'false');
            ideas.textContent = this.ideasOpen ? COPY.hideIdeas : COPY.ideas;
        }

        if (!visible) {
            return;
        }

        const pills = shown.map((suggestion) => {
            if (suggestion.kind === 'team') {
                return this.teamPill(suggestion.label?.trim() || COPY.talkToTeam);
            }

            const pill = h('button', { class: 'pill', type: 'button' }, suggestion.label?.trim() || suggestion.prompt);
            pill.addEventListener('click', () => {
                this.ideasOpen = false;
                void this.send(suggestion.prompt);
            });

            return pill;
        });

        // Always offered while our team is behind the Assistant and does not have this thread yet: where SSO placed it, else last.
        if (offerTeam && !placedTeam) {
            pills.push(this.teamPill(COPY.talkToTeam));
        }

        this.suggestionsEl = h('div', { class: 'suggestions' }, h('div', { class: 'suggestions-title' }, COPY.thingsICanDo), h('div', { class: 'pills' }, ...pills));
        this.list.append(this.suggestionsEl);
    }

    private teamPill(label: string): HTMLElement {
        const pill = h('button', { class: 'pill team', type: 'button' }, label);
        pill.addEventListener('click', () => {
            this.ideasOpen = false;
            this.startTeamMessage();
        });

        return pill;
    }

    /* Reach */

    /** The person only asks questions here (SSO's `reach: none`). */
    private memberOnly(): boolean {
        return this.reach === 'none';
    }

    private copyFor(key: 'subtitle' | 'intro' | 'placeholder'): string {
        if (!this.memberOnly()) {
            return COPY[key];
        }

        if (key === 'placeholder') {
            return MEMBER_COPY.placeholder;
        }

        const team = this.support?.enabled === true;

        return key === 'subtitle' ? (team ? MEMBER_COPY.subtitle : MEMBER_COPY.subtitleNoTeam) : team ? MEMBER_COPY.intro : MEMBER_COPY.introNoTeam;
    }

    /** Header, composer and the file button follow the person's reach. */
    private applyReach(): void {
        this.subtitleEl.textContent = this.copyFor('subtitle');
        this.input.placeholder = this.copyFor('placeholder');
        this.input.setAttribute('aria-label', this.copyFor('placeholder'));
        this.syncAttach();
    }

    /**
     * Files are a list to set up for an agency administrator (any thread), and
     * something to show our team for everyone else: the button shows for them
     * only once our team has the conversation.
     */
    private syncAttach(): void {
        const admin = this.reach === null || this.reach === 'full';
        const withTeam = this.support?.enabled === true && this.support.status === 'needs_teammate';

        this.attachButton.hidden = !(admin || withTeam);
    }

    private renderTurn(message: Message): HTMLElement {
        if (message.role === 'system') {
            return h('div', { class: 'system-line', role: 'status', 'data-id': String(message.id) }, message.content);
        }

        if (message.role === 'staff') {
            return this.renderStaffTurn(message);
        }

        const turn = h('div', { class: `turn ${message.role}`, 'data-id': String(message.id) });

        if (message.role === 'assistant' && message.activity && message.activity.length > 0) {
            turn.append(h('div', { class: 'activity' }, ...message.activity.map((line) => h('div', {}, line))));
        }

        if (message.content) {
            const bubble = h('div', { class: `bubble${message.tone ? ` ${message.tone}` : ''}` });
            bubble.append(paragraphs(message.content, { links: message.role === 'assistant' }));
            turn.append(bubble);
        }

        const links = message.role === 'assistant' ? renderLinks(message.links) : null;

        if (links) {
            turn.append(links);
        }

        if (message.attachments && message.attachments.length > 0) {
            turn.append(renderAttachments(message.attachments));
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

    /** A reply from someone on our team: their initials, name and a small Unified tag. */
    private renderStaffTurn(message: Message): HTMLElement {
        const name = message.author?.trim() || COPY.teamTag;
        const turn = h(
            'div',
            { class: 'turn staff', 'data-id': String(message.id) },
            h('div', { class: 'staff-head' }, h('span', { class: 'avatar', 'aria-hidden': 'true' }, initials(name)), h('span', { class: 'staff-name' }, name), h('span', { class: 'tag' }, COPY.teamTag)),
        );

        if (message.content) {
            const bubble = h('div', { class: 'bubble' });
            bubble.append(paragraphs(message.content));
            turn.append(bubble);
        }

        if (message.attachments && message.attachments.length > 0) {
            turn.append(renderAttachments(message.attachments));
        }

        const time = timeLabel(message.at);

        if (time) {
            turn.append(h('div', { class: 'time' }, time));
        }

        this.seen.watch(turn);

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
        if (this.support?.enabled) {
            return this.renderTeamHandoff(handoff);
        }

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

    /**
     * With our team behind the Assistant the handoff goes to a person: the
     * conversation is handed over in place and their replies show up here.
     */
    private renderTeamHandoff(handoff: Handoff): HTMLElement {
        const card = h('div', { class: 'card' }, h('div', { class: 'card-title' }, COPY.teamHandoff));

        if (this.support?.status === 'needs_teammate') {
            return card;
        }

        const button = h('button', { class: 'btn primary', type: 'button' }, COPY.talkToTeam);
        button.addEventListener('click', () => void this.talkToTeam(handoff.question, button));
        card.append(h('div', { class: 'row-actions' }, button));

        return card;
    }

    private async talkToTeam(question: string, button: HTMLButtonElement): Promise<void> {
        if (this.busy) {
            return;
        }

        button.disabled = true;
        this.setBusy(true);

        try {
            const bootstrap = await this.api.escalate(this.conversation?.id ?? null, question);
            this.setBusy(false);
            this.applyBootstrap(bootstrap);
        } catch (error) {
            this.setBusy(false);
            button.disabled = false;
            this.appendNotice(this.errorText(error));
        }
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
            const waiting = pendingChoices(plan);
            const approve = h('button', { class: 'btn primary', type: 'button', disabled: plan.changes.length === 0 || waiting > 0 }, COPY.approve);
            const setAside = h('button', { class: 'btn secondary', type: 'button' }, COPY.setAside);

            approve.addEventListener('click', () => {
                const confirmed = [...careBoxes.entries()].filter(([, box]) => box.checked).map(([index]) => index);
                void this.reviewAction(plan, 'approve', { plan_hash: plan.plan_hash, confirmed_danger: confirmed }, card, COPY.applying);
            });
            setAside.addEventListener('click', () => {
                void this.reviewAction(plan, 'set-aside', {}, card, COPY.setAsideDone);
            });

            card.append(h('div', { class: 'row-actions' }, approve, setAside));

            if (waiting > 0) {
                const line = h('div', { class: 'hint still-need', id: `ua-still-need-${plan.id}` }, stillNeedText(waiting));
                approve.setAttribute('aria-describedby', line.id);
                card.append(line);
            }
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

        if (change.choice && !resolved) {
            const row = h('div', { class: 'change choosing' }, body);
            body.append(renderChoice(plan.id, change, (code) => void this.chooseAction(plan, change, code, row, card)));

            return row;
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

    /**
     * Sends the pick for one row. Busy and errors stay on that row; the answer
     * re-renders the list the same way a removal does.
     */
    private async chooseAction(plan: ReviewPlan, change: PlanChange, code: string | null, row: HTMLElement, card: HTMLElement): Promise<void> {
        const others = [...card.querySelectorAll('button')].filter((button) => !row.contains(button) && !button.disabled);
        others.forEach((button) => (button.disabled = true));
        row.querySelector('.row-error')?.remove();
        setRowBusy(row, true);

        try {
            const result = await this.api.choose(plan.id, plan.plan_hash, change.index, code);

            if (result.error) {
                throw new ApiError(200, result.error);
            }

            this.applyReviewResult(plan, 'choose', result, card, null);
        } catch (error) {
            setRowBusy(row, false);
            row.querySelectorAll('[role="radio"]').forEach((option) => option.setAttribute('aria-checked', 'false'));
            others.forEach((button) => (button.disabled = false));
            showRowError(row, this.errorText(error));
        }
    }

    private async reviewAction(plan: ReviewPlan, verb: 'approve' | 'remove' | 'set-aside', body: Record<string, unknown>, card: HTMLElement, doneText: string | null): Promise<void> {
        const enabled = [...card.querySelectorAll('button')].filter((button) => !button.disabled);
        enabled.forEach((button) => (button.disabled = true));

        try {
            const result = await this.api.review(plan.id, verb, body);

            if (result.error) {
                throw new ApiError(200, result.error);
            }

            this.applyReviewResult(plan, verb, result, card, doneText);
        } catch (error) {
            enabled.forEach((button) => (button.disabled = false));
            this.appendNotice(this.errorText(error));
        }
    }

    private applyReviewResult(plan: ReviewPlan, verb: ReviewVerb, result: ReviewResponse, card: HTMLElement, doneText: string | null): void {
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
            card.querySelectorAll('.row-actions, .remove, .care, .choice-block, .still-need').forEach((el) => el.remove());
            card.append(h('div', { class: 'hint' }, doneText ?? COPY.applying));
        }

        if (verb === 'approve') {
            // Approving the list is the change an action step waited for.
            this.advanceTour({ review: result.review ?? { ...plan, status: 'approved' }, turn_state: 'complete' });

            if (!result.review || APPLIED_PLAN_STATUSES.includes(result.review.status)) {
                this.offerFeedback(null);
            }
        }

        if (result.reply) {
            this.push({ id: `r-${Date.now()}`, role: 'assistant', content: result.reply, at: new Date().toISOString() });
        }

        if (result.notice) {
            this.appendNotice(result.notice);
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
        const files = this.pendingFiles.slice();
        const typed = (preset ?? this.input.value).trim();
        const text = typed || (files.length > 0 ? `${COPY.fileAdded}: ${files.map((file) => file.name).join(', ')}` : '');

        if (!text || this.busy) {
            return;
        }

        if (preset === undefined) {
            this.input.value = '';
            this.autosize();
        }

        if (this.teamMode && this.canTalkToTeam()) {
            await this.sendToTeam(text, files);

            return;
        }

        this.dropFeedbackCard();
        this.dropRatingCard();
        this.dropFailedTurn();
        const userId = `u-${Date.now()}`;
        const draftKey = this.draftKey();
        this.push({
            id: userId,
            role: 'user',
            content: text,
            at: new Date().toISOString(),
            attachments: files.length > 0 ? files.map((file) => ({ id: file.id, name: file.name, size: file.size })) : null,
        });
        this.dismissIntro();
        this.setBusy(true);
        this.slowTimer.start((label) => this.setWaitingLabel(label));

        try {
            const turn = await this.api.send(text, this.setupApp, this.conversation?.id ?? null, files.map((file) => file.id));
            const failure = failedTurnError(turn);

            if (failure !== null) {
                this.showFailedTurn(text, userId, failure, false);

                return;
            }

            this.pendingFiles = this.pendingFiles.filter((file) => !files.some((sentFile) => sentFile.id === file.id));
            this.renderPendingFiles();

            clearDraftIfSent(draftKey, text);
            this.acceptTurn(turn);
            this.advanceTour(turn);
        } catch (error) {
            this.showFailedTurn(text, userId, this.errorText(error), isBusy(error));
        } finally {
            this.slowTimer.stop();
            this.setBusy(false);
        }
    }

    /* A message that got no answer */

    private draftKey(): string {
        return this.storageKey(draftName(this.conversation?.id ?? null));
    }

    /**
     * Keeps the admin's text: back in the composer (and the stored draft) so a
     * refresh loses nothing, with Try again and Dismiss under the error. A busy
     * thread is watched first and only offers Try again after a minute.
     */
    private showFailedTurn(text: string, userId: string, error: string, busy: boolean): void {
        if (this.input.value.trim() === '') {
            this.input.value = text;
            this.autosize();
        }

        saveDraft(this.draftKey(), this.input.value);

        const handlers: FailedTurnHandlers = {
            onRetry: () => {
                this.dropFailedTurn(userId);

                if (this.input.value.trim() === text) {
                    this.input.value = '';
                    this.autosize();
                }

                void this.send(text);
            },
            onDismiss: () => {
                this.dropFailedTurn(userId);
                this.input.focus();
            },
        };

        this.failedRow = renderFailedTurn(error, handlers, busy);
        this.failedRow.dataset.retry = userId;
        this.retryHandlers = handlers;
        this.list.append(this.failedRow);
        this.scrollToEnd();

        if (busy) {
            this.busyPoller.start();
        }
    }

    private busyGaveUp(): void {
        if (this.failedRow && this.retryHandlers) {
            showRetryButtons(this.failedRow, this.retryHandlers);
        }
    }

    /** Removes the error bubble and, when given, the unanswered message above it. */
    private dropFailedTurn(userId?: string): void {
        this.busyPoller.stop();
        this.failedRow?.remove();
        this.failedRow = null;
        this.retryHandlers = null;

        if (userId !== undefined) {
            this.messages = this.messages.filter((message) => message.id !== userId);
            this.list.querySelector(`[data-id="${userId}"]`)?.remove();
        }
    }

    private acceptTurn(turn: TurnResponse): void {
        const actions = realActions(turn.actions);
        const wasWaiting = this.support?.status === 'needs_teammate';

        if (turn.support) {
            this.support = turn.support;
            this.supportChanged();
        }

        const message: Message = {
            id: turn.message_id ?? `a-${Date.now()}`,
            role: 'assistant',
            content: turn.reply ?? '',
            at: new Date().toISOString(),
            activity: turn.activity ?? null,
            actions,
            review: turn.review ?? null,
            handoff: turn.handoff ?? null,
            links: turn.links ?? null,
        };

        if (turn.error) {
            message.content = message.content || turn.error;
            message.tone = 'error';
        }

        if (turn.review) {
            this.pendingReview = turn.review;
        }

        if (message.content || message.actions?.length || message.review || message.handoff || message.links?.length) {
            this.push(message);
        }

        if (turn.notice) {
            this.appendNotice(turn.notice);
        }

        if (actions.length > 0) {
            announceActions(actions);
        }

        const applied = turn.review !== null && turn.review !== undefined && APPLIED_PLAN_STATUSES.includes(turn.review.status);

        if ((turn.turn_state ?? 'complete') === 'complete' && (actions.length > 0 || applied)) {
            this.offerFeedback(typeof turn.message_id === 'number' ? turn.message_id : null);
        }

        this.armRatingIdle();

        // Handed to our team by this turn: fetch the "Handed to our team" line now.
        this.syncPolling(!wasWaiting && this.support?.status === 'needs_teammate');
    }

    /* Our team's replies */

    /**
     * Polls while a teammate has the conversation: every 5 s while the drawer
     * is open and the page is showing, every 20 s otherwise. Stops once the
     * conversation is resolved or back with the Assistant, and starts over
     * with every thread change (applyBootstrap).
     */
    private syncPolling(now = false): void {
        const waiting = this.booted && this.support?.enabled === true && this.support.status === 'needs_teammate' && this.conversation !== null;

        this.syncRealtime(waiting);

        if (!waiting) {
            this.stopPolling();

            return;
        }

        if (now || this.pollTimer === null) {
            this.schedulePoll(now ? 0 : this.pollDelay());
        }
    }

    private reschedulePoll(): void {
        if (this.pollTimer !== null) {
            this.schedulePoll(this.pollDelay());
        }
    }

    private pollDelay(): number {
        if (this.socket.isLive()) {
            return TEAM_POLL_LIVE_MS;
        }

        return this.open && document.visibilityState !== 'hidden' ? TEAM_POLL_OPEN_MS : TEAM_POLL_HIDDEN_MS;
    }

    private schedulePoll(delay: number): void {
        this.stopPolling();
        this.pollTimer = window.setTimeout(() => void this.pollTeam(), delay);
    }

    private stopPolling(): void {
        if (this.pollTimer !== null) {
            window.clearTimeout(this.pollTimer);
            this.pollTimer = null;
        }
    }

    private async pollTeam(): Promise<void> {
        this.pollTimer = null;
        const conversationId = this.conversation?.id ?? null;

        if (conversationId === null) {
            return;
        }

        try {
            const updates = await this.api.updates(conversationId, this.lastSeenId());

            if (String(this.conversation?.id ?? '') !== String(conversationId)) {
                return;
            }

            for (const message of updates.messages ?? []) {
                const known = this.findKnown(message);

                if (known) {
                    this.confirmLive(known, message);
                } else {
                    this.push(message);
                    this.badgeFor(message);
                    this.chimeFor(message);
                }
            }

            if (updates.support?.realtime !== undefined && this.support) {
                this.support = { ...this.support, realtime: updates.support.realtime };
            }

            if (updates.status && this.support && updates.status !== this.support.status) {
                this.support = { ...this.support, status: updates.status };
                this.supportChanged();
            }
        } catch {
            /* asked again on the next round */
        }

        if (this.pollTimer === null) {
            this.syncPolling();
        }
    }

    /** The newest message id the widget has from SSO (its own sends carry local ids; lines heard live wait for a poll). */
    private lastSeenId(): number {
        const ids = this.messages.map((message) => (typeof message.id === 'number' && !message.live ? message.id : Number.NaN)).filter((id) => Number.isFinite(id));

        return ids.length > 0 ? Math.max(...ids) : (this.support?.last_message_id ?? 0);
    }

    /** A message the widget already shows: the same id, or the same teammate's reply by its CRM id. */
    private findKnown(message: Message): Message | undefined {
        return this.messages.find(
            (known) => String(known.id) === String(message.id) || (typeof message.crm_message_id === 'number' && known.crm_message_id === message.crm_message_id),
        );
    }

    /** A line first heard on the socket, now given by SSO: it takes SSO's id so the next poll starts after it. */
    private confirmLive(known: Message, message: Message): void {
        if (!known.live) {
            return;
        }

        const row = Array.from(this.list.querySelectorAll<HTMLElement>('[data-id]')).find((el) => el.dataset.id === String(known.id));
        known.id = message.id;
        known.live = false;
        row?.setAttribute('data-id', String(message.id));
        this.seen.check();
    }

    /* Read receipts */

    /** The thread is in front of the person: drawer open, page showing, the Conversations menu not over it. */
    private threadVisible(): boolean {
        return this.open && this.panelOpen === false && document.visibilityState !== 'hidden';
    }

    private sendSeen(messageId: number): Promise<unknown> {
        const conversationId = this.conversation?.id ?? null;

        if (conversationId === null || !this.api.canSendSeen()) {
            return Promise.resolve();
        }

        return this.api.seen(conversationId, messageId);
    }

    private badgeFor(message: Message): void {
        if (!this.open && message.role === 'staff') {
            this.badge.textContent = '1';
            this.badge.hidden = false;
        }
    }

    /* Our team's replies, live */

    /**
     * Listens on the CRM's socket while our team has the conversation and
     * SSO gave a channel; leaves it on a new conversation, a resolved one, or
     * one handed back to the Assistant.
     */
    private syncRealtime(waiting: boolean): void {
        const realtime = this.support?.realtime ?? null;

        if (waiting && realtime) {
            this.socket.connect(realtime);

            return;
        }

        this.socket.close();
        this.hideTyping();
    }

    /** Connected: poll once now for anything missed, then only every 60 s. Dropped: back to every 5 s. */
    private onLive(live: boolean): void {
        if (live) {
            this.syncPolling(true);
        } else {
            this.reschedulePoll();
        }
    }

    private onLiveMessage(live: LiveMessage): void {
        const message = liveToMessage(live);

        if (!message || (message.role === 'staff' && !message.content && !message.attachments) || this.findKnown(message)) {
            return;
        }

        if (message.role === 'staff') {
            this.hideTyping();
        }

        this.push(message);
        this.badgeFor(message);
        this.chimeFor(message);
    }

    /** Only a reply from our team the widget did not have yet; never history, the person's own lines, the Assistant or status lines. */
    private chimeFor(message: Message): void {
        if (message.role === 'staff') {
            this.sound.play();
        }
    }

    private onLiveConversation(update: LiveConversation): void {
        if (!this.support) {
            return;
        }

        const status = update.support_status;
        const assignee = update.assignee_name?.trim() || null;

        if (assignee && assignee !== this.assignee && (status ?? this.support.status) === 'needs_teammate') {
            this.pushLocalLine(`${assignee} ${COPY.assigned}`);
        }

        this.assignee = assignee;

        if (status && ['assistant', 'needs_teammate', 'resolved'].includes(status) && status !== this.support.status) {
            this.support = { ...this.support, status };

            if (status === 'resolved') {
                this.pushLocalLine(COPY.resolved);
            }

            this.supportChanged();
            this.syncPolling();
        }
    }

    private onLiveTyping(typing: LiveTyping): void {
        const name = typing.name?.trim() || COPY.teamTag;
        this.typingEl.textContent = `${name} ${COPY.isTyping}`;
        this.typingEl.hidden = false;
        this.list.append(this.typingEl);
        this.scrollToEnd();

        if (this.typingTimer !== null) {
            window.clearTimeout(this.typingTimer);
        }

        this.typingTimer = window.setTimeout(() => this.hideTyping(), TYPING_SHOWN_MS);
    }

    private hideTyping(): void {
        if (this.typingTimer !== null) {
            window.clearTimeout(this.typingTimer);
            this.typingTimer = null;
        }

        this.typingEl.hidden = true;
        this.typingEl.remove();
    }

    /** A status line from the socket: shown now, never persisted (SSO keeps its own lines). */
    private pushLocalLine(text: string): void {
        this.push({ id: `status-${Date.now()}-${this.messages.length}`, role: 'system', content: text, live: true });
    }

    /* "Talk to our team" at any time */

    /** Our team is behind the Assistant and does not have this thread yet. */
    private canTalkToTeam(): boolean {
        return this.support?.enabled === true && this.support.status !== 'needs_teammate';
    }

    /**
     * Nothing is handed over yet: an empty thread is never escalated. The
     * drawer asks what they need and the next message goes to our team.
     */
    private startTeamMessage(): void {
        if (!this.canTalkToTeam() || this.busy) {
            return;
        }

        if (this.panelOpen) {
            this.setPanelOpen(false);
        }

        if (!this.teamMode) {
            this.teamMode = true;
            this.dropFeedbackCard();
            this.push({ id: `team-prompt-${Date.now()}`, role: 'assistant', content: COPY.teamPrompt });
            this.renderTeamMode();
        }

        this.input.focus();
    }

    private leaveTeamMode(): void {
        this.teamMode = false;
        this.renderTeamMode();
        this.input.focus();
    }

    /**
     * The message goes to our team with the conversation. A newer component
     * sends it in the escalate call (SSO stores it, hands over and syncs once);
     * an older one only forwards the question, so the conversation is handed
     * over first and the text follows as an ordinary message, which SSO keeps
     * for our team.
     */
    private async sendToTeam(text: string, files: Array<{ id: number; name: string; size: number }>): Promise<void> {
        this.dropFeedbackCard();
        this.dropRatingCard();
        this.dropFailedTurn();
        const userId = `u-${Date.now()}`;
        const draftKey = this.draftKey();
        this.push({
            id: userId,
            role: 'user',
            content: text,
            at: new Date().toISOString(),
            attachments: files.length > 0 ? files.map((file) => ({ id: file.id, name: file.name, size: file.size })) : null,
        });
        this.dismissIntro();
        this.setBusy(true);

        const fileIds = files.map((file) => file.id);
        const withMessage = this.api.escalatesWithMessage();

        try {
            const bootstrap = await this.api.escalate(this.conversation?.id ?? null, text, withMessage ? text : null, withMessage ? fileIds : []);
            clearDraftIfSent(draftKey, text);
            this.setBusy(false);
            this.applyBootstrap(bootstrap);

            if (withMessage) {
                return;
            }

            this.pendingFiles = files;
            this.renderPendingFiles();
            await this.send(text);
        } catch (error) {
            this.setBusy(false);
            this.showFailedTurn(text, userId, this.errorText(error), isBusy(error));
        }
    }

    /** The chip above the composer while the next message goes to our team. */
    private renderTeamMode(): void {
        if (!this.teamMode) {
            this.teamChipEl.replaceChildren();
            this.teamChipEl.hidden = true;

            return;
        }

        const remove = h('button', { class: 'remove', type: 'button', 'aria-label': `${COPY.removeFile} ${COPY.toOurTeam}`, title: COPY.removeFile, onclick: () => this.leaveTeamMode() }, svg(icons.close, 14));
        this.teamChipEl.replaceChildren(h('span', { class: 'file-chip team-chip' }, svg(icons.send, 14), h('span', { class: 'name' }, COPY.toOurTeam), remove));
        this.teamChipEl.hidden = false;
    }

    /** "Talk to our team" next to New conversation, or "Our team has this conversation" once they do. */
    private renderTeamEntry(): void {
        this.teamEntryEl.replaceChildren();
        this.teamEntryEl.hidden = this.support?.enabled !== true;

        if (this.support?.enabled !== true) {
            return;
        }

        if (this.support.status === 'needs_teammate') {
            this.teamEntryEl.append(h('div', { class: 'team-status', role: 'status' }, COPY.teamHasConversation));

            return;
        }

        this.teamEntryEl.append(h('button', { class: 'btn secondary talk-to-team', type: 'button', onclick: () => this.startTeamMessage() }, svg(icons.send, 14), COPY.talkToTeam));
    }

    /** The menu item, the pill and the chip follow who has the conversation. */
    private supportChanged(): void {
        if (this.support?.status === 'resolved') {
            this.offerRating();
        } else if (this.support?.status === 'needs_teammate') {
            this.dropRatingCard();
        }

        if (this.teamMode && !this.canTalkToTeam()) {
            this.teamMode = false;
            this.renderTeamMode();
        }

        this.renderTeamEntry();
        this.syncAttach();
        this.renderSuggestions();
    }

    /* Files waiting to go with the next message */

    private renderPendingFiles(): void {
        this.pendingEl.replaceChildren(
            ...this.pendingFiles.map((file) => {
                const remove = h('button', { class: 'remove', type: 'button', 'aria-label': `${COPY.removeFile} ${file.name}`, title: COPY.removeFile }, svg(icons.close, 14));
                remove.addEventListener('click', () => {
                    this.pendingFiles = this.pendingFiles.filter((pending) => pending.id !== file.id);
                    this.renderPendingFiles();
                    this.input.focus();
                });

                return h('span', { class: 'file-chip' }, svg(icons.paperclip, 14), h('span', { class: 'name' }, file.name), h('span', { class: 'size' }, fileSize(file.size)), remove);
            }),
        );
        this.pendingEl.hidden = this.pendingFiles.length === 0;
    }

    /* The rating at the end of a conversation */

    /**
     * Whether the closing card may be shown: SSO and this app's component
     * both know the rating, the thread is unrated, someone (the Assistant or
     * our team) has answered in it, and our team does not have it open.
     */
    private ratingEligible(): boolean {
        const id = this.conversation?.id;

        if (!this.rating?.enabled || this.rating.score !== null || !this.api.canRate() || id === undefined || id === null) {
            return false;
        }

        if (this.support?.status === 'needs_teammate' || this.ratingSkipped()) {
            return false;
        }

        return this.messages.some((message) => (message.role === 'assistant' || message.role === 'staff') && typeof message.id === 'number' && (message.content ?? '').trim() !== '');
    }

    /**
     * Shows the card at the bottom of the thread. `then` runs once it is
     * answered or skipped (New conversation waits for it). False when no card
     * was shown: not eligible, or one is already there.
     */
    private offerRating(then: (() => void) | null = null): boolean {
        if (this.ratingCard !== null || !this.ratingEligible() || this.conversation === null) {
            return false;
        }

        const conversationId = this.conversation.id;
        this.stopRatingIdle();
        this.ratingDue = false;
        this.afterRating = then;
        this.dropFeedbackCard();

        const card = new RatingCard({
            rating: this.rating as Rating,
            callOffered: this.support?.enabled === true && this.api.canRequestCall(),
            rate: (score, note) => this.api.rate(conversationId, score, note),
            requestCall: (phone, bestTime, note) => this.api.requestCall(conversationId, phone, bestTime, note),
            finished: (outcome, rating) => this.ratingFinished(card, outcome, rating),
            called: (answer) => this.callRequested(answer),
            changed: () => this.scrollToEnd(),
        });
        this.ratingCard = card;
        this.list.append(card.element);
        this.scrollToEnd();

        return true;
    }

    private ratingFinished(card: RatingCard, outcome: 'skipped' | 'rated' | 'declined', rating: Rating | null): void {
        if (rating) {
            this.rating = rating;
        }

        if (outcome === 'skipped' && this.ratingCard === card) {
            this.markRatingSkipped();
            this.ratingCard = null;
        }

        const then = this.afterRating;
        this.afterRating = null;

        if (then) {
            window.setTimeout(then, outcome === 'rated' ? RATING_THEN_NEW_MS : 0);
        }
    }

    /** "Request a call" went through: the thread is our team's now and says so. */
    private callRequested(answer: CallbackResponse): void {
        this.afterRating = null;
        this.applyBootstrap(answer);

        if (answer.callback?.message) {
            this.push({ id: `callback-${Date.now()}`, role: 'assistant', content: answer.callback.message });
        }
    }

    /** An unanswered card goes when the conversation carries on. */
    private dropRatingCard(): void {
        if (this.ratingCard && !this.ratingCard.isSettled()) {
            this.ratingCard.element.remove();
            this.ratingCard = null;
            this.afterRating = null;
        }
    }

    /** Assistant-only: two quiet minutes after a reply bring the card. */
    private armRatingIdle(): void {
        this.stopRatingIdle();

        if (this.support?.status === 'needs_teammate' || this.support?.status === 'resolved' || !this.ratingEligible()) {
            return;
        }

        this.ratingIdleTimer = window.setTimeout(() => {
            this.ratingIdleTimer = null;

            if (this.busy) {
                return;
            }

            if (this.open) {
                this.offerRating();
            } else {
                this.ratingDue = true;
            }
        }, RATING_IDLE_MS);
    }

    private stopRatingIdle(): void {
        if (this.ratingIdleTimer !== null) {
            window.clearTimeout(this.ratingIdleTimer);
            this.ratingIdleTimer = null;
        }
    }

    private ratingSkipped(): boolean {
        try {
            return this.conversation !== null && sessionStorage.getItem(this.storageKey(`${RATING_SKIPPED_PREFIX}${String(this.conversation.id)}`)) === '1';
        } catch {
            return false;
        }
    }

    private markRatingSkipped(): void {
        try {
            if (this.conversation !== null) {
                sessionStorage.setItem(this.storageKey(`${RATING_SKIPPED_PREFIX}${String(this.conversation.id)}`), '1');
            }
        } catch {
            /* private mode: the card may come back on the next trigger */
        }
    }

    /* "Did I handle that correctly?" */

    /**
     * Asked once per completed task (a reply that made a change, or an
     * approved review list). A yes is recorded and thanked; a no is recorded,
     * sent to the team, and followed by one more question: do they want
     * someone to follow up. Any failure just removes the card: the question
     * must never get in the way of the work.
     */
    private offerFeedback(messageId: number | null): void {
        const conversationId = this.conversation?.id ?? null;

        if (!this.askFeedback || conversationId === null) {
            return;
        }

        this.dropFeedbackCard();

        const card = h('div', { class: 'card feedback-card' });
        const turn = h('div', { class: 'turn assistant feedback' }, card);
        this.feedbackCard = turn;

        const ask = (helpful: boolean) => {
            card.querySelectorAll('button').forEach((button) => (button.disabled = true));

            void this.api
                .feedback(conversationId, messageId, helpful)
                .then((result) => {
                    if (!result || result.ok === false) {
                        this.dropFeedbackCard();

                        return;
                    }

                    if (helpful) {
                        this.showFeedbackClosing(card, COPY.feedbackThanks, true);

                        return;
                    }

                    this.askFollowUp(card, typeof result.id === 'number' ? result.id : null);
                })
                .catch(() => this.dropFeedbackCard());
        };

        card.append(
            h('div', { class: 'question' }, COPY.feedbackQuestion),
            h(
                'div',
                { class: 'row-actions' },
                h('button', { class: 'btn secondary sm', type: 'button', onclick: () => ask(true) }, COPY.yes),
                h('button', { class: 'btn secondary sm', type: 'button', onclick: () => ask(false) }, COPY.no),
            ),
        );

        this.list.append(turn);
        this.scrollToEnd();
    }

    private askFollowUp(card: HTMLElement, feedbackId: number | null): void {
        if (feedbackId === null) {
            this.showFeedbackClosing(card, COPY.feedbackNoted, false);

            return;
        }

        const answer = (wantsFollowUp: boolean) => {
            card.querySelectorAll('button').forEach((button) => (button.disabled = true));

            void this.api
                .feedbackFollowUp(feedbackId, wantsFollowUp)
                .then(() => this.showFeedbackClosing(card, wantsFollowUp ? COPY.feedbackWillContact : COPY.feedbackNoted, false))
                .catch(() => this.showFeedbackClosing(card, COPY.feedbackNoted, false));
        };

        card.replaceChildren(
            h('div', { class: 'hint' }, COPY.feedbackSorry),
            h('div', { class: 'question' }, COPY.feedbackFollowUp),
            h(
                'div',
                { class: 'row-actions' },
                h('button', { class: 'btn primary sm', type: 'button', onclick: () => answer(true) }, COPY.followUpYes),
                h('button', { class: 'btn secondary sm', type: 'button', onclick: () => answer(false) }, COPY.followUpNo),
            ),
        );
        this.scrollToEnd();
    }

    private showFeedbackClosing(card: HTMLElement, text: string, fade: boolean): void {
        card.replaceChildren(h('div', { class: 'hint' }, text));
        card.classList.add('answered');

        if (fade) {
            window.setTimeout(() => {
                if (this.feedbackCard?.contains(card)) {
                    this.dropFeedbackCard();
                }
            }, FEEDBACK_THANKS_MS);
        }
    }

    private dropFeedbackCard(): void {
        this.feedbackCard?.remove();
        this.feedbackCard = null;
    }

    private async onFilePicked(): Promise<void> {
        const file = this.fileInput.files?.[0];
        this.fileInput.value = '';

        if (!file || this.busy) {
            return;
        }

        this.setBusy(true, COPY.uploading);

        try {
            const result = await this.api.upload(file, this.setupApp);

            if (result.ok === false || result.error) {
                throw new ApiError(200, result.error || 'That file did not go through.');
            }

            const upload = result.upload ?? null;

            if (upload && stillReading(upload)) {
                // The composer stays locked while SSO reads the file; the chip is the progress.
                this.setBusy(true, COPY.uploading, false);
                const ready = await this.waitForFile(upload.id, file.name);

                if (!ready) {
                    this.setBusy(false);

                    return;
                }
            }

            this.setBusy(false);

            // An SSO with our team behind it takes the file with the next message.
            if (this.support !== null && upload?.id) {
                this.pendingFiles = [...this.pendingFiles, { id: upload.id, name: file.name, size: file.size }];
                this.renderPendingFiles();
                this.input.focus();

                return;
            }

            // The model reads a file by its number; the name alone leaves it guessing.
            const label = upload?.id ? `${COPY.fileAdded} (file ${upload.id})` : COPY.fileAdded;
            await this.send(`${label}: ${file.name}`);
        } catch (error) {
            this.setBusy(false);
            this.push({ id: `e-${Date.now()}`, role: 'assistant', content: this.errorText(error), tone: 'error', at: new Date().toISOString() });
        }
    }

    /**
     * Shows the file as a chip reading "Reading file..." and asks SSO for its
     * status until it is read. A file that could not be read keeps the chip
     * with the reason and sends nothing; a read file's chip goes, because the
     * "I added a file" message takes its place.
     */
    private async waitForFile(uploadId: number, name: string): Promise<boolean> {
        const status = h('span', { class: 'status' }, UPLOAD_COPY.reading);
        const mark = h('span', { class: 'dots', 'aria-hidden': 'true' }, h('span'), h('span'), h('span'));
        const chip = h('div', { class: 'chip file reading', role: 'status' }, mark, h('div', { class: 'text' }, h('div', {}, name)), status);
        const row = h('div', { class: 'turn user file-turn' }, h('div', { class: 'chips' }, chip));

        this.list.append(row);
        this.scrollToEnd();

        const outcome = await waitUntilRead(() => this.api.uploadStatus(uploadId));

        if (outcome.status === 'ready') {
            row.remove();

            return true;
        }

        chip.classList.remove('reading');
        chip.classList.add('failed');
        chip.removeAttribute('role');
        mark.replaceWith(h('span', { class: 'mark' }, svg(icons.alert, 16)));
        status.remove();
        chip.querySelector('.text')?.append(h('div', { class: 'meta', role: 'alert' }, outcome.error));
        this.scrollToEnd();

        return false;
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

        this.avoidance.hold();
        this.setOpen(false, true, false);

        return askHelp(question);
    }

    /**
     * While the launchers are showing (widget enabled, drawer closed) the page's
     * scroll container (marked by BarAvoidance; the body only when the body
     * scrolls) gets bottom padding so its own buttons can scroll above the two
     * bubbles. The rule is !important because app shells set the container's
     * padding inline (safe-area insets); that value is carried in the variable.
     * The drawer hides the Intercom bubble, so an open drawer needs none; and
     * when the column has been lifted over a pinned bar the bar already keeps
     * the bubbles off the content, so the padding is dropped too ("lifted").
     */
    private syncDock(): void {
        const docked = this.booted && !this.open;
        const html = document.documentElement;

        if (docked) {
            html.setAttribute(DOCK_ATTRIBUTE, this.lifted ? 'lifted' : 'docked');
            ensureDockStyle();
        } else {
            html.removeAttribute(DOCK_ATTRIBUTE);
        }
    }

    /* Helpers */

    private push(message: Message): void {
        this.messages.push(message);

        if (this.messages.length === 1) {
            this.list.querySelector('[data-id="intro"]')?.remove();
        }

        this.list.append(this.renderTurn(message));

        if (!this.typingEl.hidden) {
            this.list.append(this.typingEl);
        }

        this.renderSuggestions();
        this.scrollToEnd();
    }

    private setBusy(busy: boolean, label: string = COPY.working, showWaiting: boolean = true): void {
        this.busy = busy;
        this.sendButton.disabled = busy;
        this.attachButton.disabled = busy;
        this.waitingRow?.remove();
        this.waitingRow = null;

        if (busy && showWaiting) {
            this.waitingRow = h('div', { class: 'turn assistant' }, h('div', { class: 'waiting', role: 'status' }, h('span', { class: 'dots' }, h('span'), h('span'), h('span')), h('span', { class: 'waiting-label' }, label)));
            this.list.append(this.waitingRow);
            this.scrollToEnd();
        }

        this.panel.querySelectorAll('button').forEach((button) => (button.disabled = busy));
    }

    private setWaitingLabel(label: string): void {
        const target = this.waitingRow?.querySelector('.waiting-label');

        if (target) {
            target.textContent = label;
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
            dismissIntro(): void;
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
        dismissIntro: () => widget.dismissIntro(),
    };

    const start = (): void => void widget.boot();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
})();
