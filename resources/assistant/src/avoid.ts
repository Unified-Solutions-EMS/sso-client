import { intercomSettings, onIntercomHide, updateIntercom } from './intercom';

/** Intercom's default bubble: 60px square, 20px from the corner. */
const INTERCOM_SIZE = 60;
const DEFAULT_PADDING = 20;
/** Space kept between the bubble and the top of a pinned bar. */
const GAP = 12;
/** Probe column: from the viewport bottom up to this many px above it. */
const PROBE_DEPTH = 160;
const PROBE_STEP = 40;
/** An element only counts as a bottom bar when its box reaches this band. */
const BAR_BAND = 200;
/** A lift this large is a full-screen layer, not a bar: leave the defaults. */
const MAX_LIFT_RATIO = 0.4;
const RESIZE_MS = 150;
const SCROLL_MS = 150;
const MUTATION_MS = 250;

const INTERCOM_SELECTOR = '#intercom-container, .intercom-lightweight-app, [id^="intercom-"], [class*="intercom-"]';

function isIntercomElement(element: Element): boolean {
    if (element.closest(INTERCOM_SELECTOR)) {
        return true;
    }

    return element instanceof HTMLIFrameElement && element.src.includes('intercom');
}

function isPinned(element: Element): boolean {
    const position = getComputedStyle(element).position;

    return position === 'fixed' || position === 'sticky';
}

/**
 * Keeps the launcher column (Assistant launcher above the Intercom bubble)
 * clear of bars pinned to the bottom of the viewport: fixed or sticky form
 * action bars, Filament / Livewire footers, modal footers. The page's own
 * bottom padding only helps content that scrolls; a pinned bar needs the
 * column lifted above it.
 *
 * The column moves as one: Intercom gets `vertical_padding` so its bubble
 * sits GAP above the bar, and the launcher (plus the first-run callout above
 * it) follows through `--ua-lift`, set on the host so every child inherits it.
 */
export class BarAvoidance {
    private readonly basePadding: number;

    private padding: number;

    private lifted = false;

    private active = false;

    private held = false;

    private timers: Record<'resize' | 'scroll' | 'mutation', number | null> = { resize: null, scroll: null, mutation: null };

    private observer: MutationObserver | null = null;

    constructor(
        private readonly host: HTMLElement,
        private readonly onLift: (lifted: boolean) => void,
    ) {
        const settings = intercomSettings();
        const configured = Number(settings.vertical_padding);
        this.basePadding = Number.isFinite(configured) && configured >= DEFAULT_PADDING ? configured : DEFAULT_PADDING;
        this.padding = this.basePadding;
    }

    start(): void {
        this.active = true;

        window.addEventListener('resize', () => this.schedule('resize', RESIZE_MS), { passive: true });
        window.addEventListener('scroll', () => this.schedule('scroll', SCROLL_MS), { passive: true, capture: true });

        this.observer = new MutationObserver((records) => {
            if (records.some((record) => this.isForeignMutation(record))) {
                this.schedule('mutation', MUTATION_MS);
            }
        });
        this.observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style', 'hidden'] });

        onIntercomHide(() => {
            this.held = false;
            this.schedule('mutation', MUTATION_MS);
        });

        this.measure();

        if (document.readyState !== 'complete') {
            window.addEventListener('load', () => this.schedule('mutation', MUTATION_MS), { once: true });
        }
    }

    /** The drawer hides the Intercom bubble and the launcher: nothing to lift while it is open. */
    setActive(active: boolean): void {
        this.active = active;

        if (active) {
            this.measure();
        } else {
            this.cancelAll();
        }
    }

    /**
     * After the Help handoff the Messenger holds a prefilled question that any
     * `Intercom('update')` would wipe, so the column stays put until Help closes.
     */
    hold(): void {
        this.held = true;
        this.cancelAll();
    }

    measure(): void {
        if (!this.active || this.held) {
            return;
        }

        const viewportWidth = document.documentElement.clientWidth;
        const viewportHeight = window.innerHeight;
        const barTop = this.findBarTop(viewportWidth, viewportHeight);
        let padding = this.basePadding;

        if (barTop !== null) {
            const wanted = Math.max(this.basePadding, Math.round(viewportHeight - barTop + GAP));

            if (wanted <= viewportHeight * MAX_LIFT_RATIO) {
                padding = wanted;
            }
        }

        this.apply(padding);
    }

    private apply(padding: number): void {
        if (padding !== this.padding) {
            this.padding = padding;
            updateIntercom({ vertical_padding: padding });
        }

        const lift = padding - this.basePadding;
        this.host.style.setProperty('--ua-lift', `${lift}px`);

        const lifted = lift > 0;

        if (lifted !== this.lifted) {
            this.lifted = lifted;
            this.onLift(lifted);
        }
    }

    private findBarTop(viewportWidth: number, viewportHeight: number): number | null {
        const settings = intercomSettings();
        const horizontal = Number(settings.horizontal_padding);
        const inset = Number.isFinite(horizontal) && horizontal >= 0 ? horizontal : DEFAULT_PADDING;
        const fromLeft = settings.alignment === 'left';
        const xs = [inset, inset + INTERCOM_SIZE / 2, inset + INTERCOM_SIZE].map((offset) => (fromLeft ? offset : viewportWidth - offset));
        const ys: number[] = [];

        for (let depth = 1; depth <= PROBE_DEPTH; depth += PROBE_STEP) {
            ys.push(viewportHeight - depth);
        }

        const seen = new Set<Element>();
        let top: number | null = null;

        for (const x of xs) {
            for (const y of ys) {
                if (x < 0 || y < 0 || x >= viewportWidth || y >= viewportHeight) {
                    continue;
                }

                for (const element of document.elementsFromPoint(x, y)) {
                    if (seen.has(element)) {
                        continue;
                    }

                    seen.add(element);

                    if (!this.isForeign(element)) {
                        continue;
                    }

                    const barRect = this.pinnedBarRect(element, viewportHeight);

                    if (barRect && (top === null || barRect.top < top)) {
                        top = barRect.top;
                    }
                }
            }
        }

        return top;
    }

    /**
     * Walks up to the nearest fixed / sticky ancestor. The bar is the outermost
     * element on that path that reads as a bottom bar (reaches the bottom band
     * and is not taller than the lift limit); a full-screen fixed layer with no
     * bar-like child yields its own rect, which the lift limit then rejects.
     */
    private pinnedBarRect(element: Element, viewportHeight: number): DOMRect | null {
        const chain: Element[] = [];
        let node: Element | null = element;

        while (node && node !== document.body && node !== document.documentElement) {
            chain.push(node);

            if (isPinned(node)) {
                for (let i = chain.length - 1; i >= 0; i--) {
                    const rect = chain[i].getBoundingClientRect();

                    if (rect.height > 0 && rect.bottom >= viewportHeight - BAR_BAND && rect.top < viewportHeight && rect.height <= viewportHeight * MAX_LIFT_RATIO) {
                        return rect;
                    }
                }

                const rootRect = node.getBoundingClientRect();

                return rootRect.height > 0 && rootRect.bottom >= viewportHeight - BAR_BAND ? rootRect : null;
            }

            node = node.parentElement;
        }

        return null;
    }

    /** A change to the page itself, not to our host or Intercom's containers. */
    private isForeignMutation(record: MutationRecord): boolean {
        if (record.type === 'childList') {
            const nodes = [...record.addedNodes, ...record.removedNodes];

            return nodes.some((node) => node instanceof Element && node !== this.host && !isIntercomElement(node)) || this.isForeign(record.target);
        }

        return this.isForeign(record.target);
    }

    /** Not ours, not Intercom's, not the document itself. */
    private isForeign(node: Node): boolean {
        const element = node instanceof Element ? node : node.parentElement;

        if (!element || element === document.documentElement || element === document.body) {
            return false;
        }

        if (element === this.host || this.host.contains(element)) {
            return false;
        }

        return !isIntercomElement(element);
    }

    private schedule(kind: 'resize' | 'scroll' | 'mutation', delay: number): void {
        if (!this.active || this.held) {
            return;
        }

        const pending = this.timers[kind];

        if (pending !== null) {
            window.clearTimeout(pending);
        }

        this.timers[kind] = window.setTimeout(() => {
            this.timers[kind] = null;
            this.measure();
        }, delay);
    }

    private cancelAll(): void {
        for (const kind of Object.keys(this.timers) as Array<keyof typeof this.timers>) {
            const pending = this.timers[kind];

            if (pending !== null) {
                window.clearTimeout(pending);
                this.timers[kind] = null;
            }
        }
    }
}
