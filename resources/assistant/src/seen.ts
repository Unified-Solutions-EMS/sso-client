/** Receipts wait this long after the last reply came on screen, so scrolling through several sends one. */
export const SEEN_DEBOUNCE_MS = 1000;

/** At least this much of a reply has to be showing for it to count as read. */
const SEEN_RATIO = 0.5;

export interface SeenOptions {
    /** Sends the receipt; a rejection lets the same reply be reported again. */
    send: (messageId: number) => Promise<unknown>;
    /** Whether the person can see the thread right now (drawer open, page showing, no menu over it). */
    visible: () => boolean;
}

/**
 * Read receipts for our team. Watches our team's replies in the thread and,
 * once one has been on screen while the drawer is open and the page is
 * showing, reports the newest such reply's id, 1 s after the last one came
 * into view and only when it is newer than anything already reported. The
 * person never sees the receipt; our team sees "Seen" in the inbox.
 */
export class SeenTracker {
    private observer: IntersectionObserver | null = null;

    private readonly onScreen = new Set<HTMLElement>();

    /** The newest id reported (or that SSO already had). */
    private reported = 0;

    /** The newest id on screen, waiting for the debounce. */
    private waiting = 0;

    private timer: number | null = null;

    constructor(
        root: HTMLElement,
        private readonly options: SeenOptions,
    ) {
        if (typeof IntersectionObserver !== 'undefined') {
            this.observer = new IntersectionObserver((entries) => this.observed(entries), { root, threshold: [0, SEEN_RATIO] });
        }
    }

    /** A new thread (or the same one redrawn): forgets what was on screen and starts from what SSO already has. */
    reset(known: number | null | undefined): void {
        this.forget();
        this.reported = typeof known === 'number' && Number.isFinite(known) ? known : 0;
        this.waiting = 0;
    }

    /** The thread was redrawn: the old rows are gone. What was reported stays. */
    forget(): void {
        this.observer?.disconnect();
        this.onScreen.clear();
        this.cancel();
    }

    /** One of our team's replies was rendered. Its id is read from `data-id` when it counts, so a reply heard live that later gets SSO's id is covered. */
    watch(element: HTMLElement): void {
        if (this.observer) {
            this.observer.observe(element);

            return;
        }

        this.onScreen.add(element);
        queueMicrotask(() => this.check());
    }

    /** Something about whether the person can see the thread changed (drawer, page, menu, an id given). */
    check(): void {
        if (!this.options.visible()) {
            this.cancel();

            return;
        }

        const newest = this.newestOnScreen();

        if (newest <= this.reported || newest <= this.waiting) {
            return;
        }

        this.stopTimer();
        this.waiting = newest;
        this.timer = window.setTimeout(() => this.flush(), SEEN_DEBOUNCE_MS);
    }

    private observed(entries: IntersectionObserverEntry[]): void {
        for (const entry of entries) {
            const target = entry.target as HTMLElement;
            const rootHeight = entry.rootBounds?.height ?? 0;
            const showing = entry.isIntersecting && (entry.intersectionRatio >= SEEN_RATIO || (rootHeight > 0 && entry.intersectionRect.height >= rootHeight * SEEN_RATIO));

            if (showing) {
                this.onScreen.add(target);
            } else {
                this.onScreen.delete(target);
            }
        }

        this.check();
    }

    private flush(): void {
        this.timer = null;
        const id = this.waiting;
        this.waiting = 0;

        if (!this.options.visible() || id <= this.reported) {
            return;
        }

        const before = this.reported;
        this.reported = id;

        this.options.send(id).catch(() => {
            if (this.reported === id) {
                this.reported = before;
            }
        });
    }

    private cancel(): void {
        this.stopTimer();
        this.waiting = 0;
    }

    private stopTimer(): void {
        if (this.timer !== null) {
            window.clearTimeout(this.timer);
            this.timer = null;
        }
    }

    private newestOnScreen(): number {
        let newest = 0;

        for (const element of this.onScreen) {
            if (!element.isConnected) {
                continue;
            }

            const id = Number(element.dataset.id);

            if (Number.isInteger(id) && id > newest) {
                newest = id;
            }
        }

        return newest;
    }
}
