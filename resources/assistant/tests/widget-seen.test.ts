import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';

type Reply = { status: number; body: unknown };

const replies: Record<string, Reply[]> = { bootstrap: [], updates: [], seen: [] };
const receipts: Array<Record<string, unknown>> = [];

/** A stand-in IntersectionObserver the test drives by hand: `show(id)` brings a reply into view. */
class FakeObserver {
    static instances: FakeObserver[] = [];
    targets = new Set<Element>();

    constructor(
        private callback: IntersectionObserverCallback,
        public options: IntersectionObserverInit,
    ) {
        FakeObserver.instances.push(this);
    }

    observe(target: Element): void {
        this.targets.add(target);
    }

    unobserve(target: Element): void {
        this.targets.delete(target);
    }

    disconnect(): void {
        this.targets.clear();
    }

    takeRecords(): IntersectionObserverEntry[] {
        return [];
    }

    fire(target: Element, showing: boolean): void {
        const entry = {
            target,
            isIntersecting: showing,
            intersectionRatio: showing ? 1 : 0,
            intersectionRect: { height: showing ? 80 : 0 },
            rootBounds: { height: 400 },
        } as unknown as IntersectionObserverEntry;
        this.callback([entry], this as unknown as IntersectionObserver);
    }
}

const support = (status: string, seen: unknown = null): unknown => ({ enabled: true, status, last_message_id: 5, realtime: null, seen });

const thread = (messages: unknown[], seen: unknown = null): unknown => ({
    enabled: true,
    viewer: 'v1',
    conversation: { id: 7, title: null, messages },
    conversations: [],
    suggestions: [],
    support: support('needs_teammate', seen),
});

let visibility: DocumentVisibilityState = 'visible';

function shadow(): ShadowRoot {
    return document.querySelector('unified-assistant')!.shadowRoot!;
}

function observer(): FakeObserver {
    return FakeObserver.instances[FakeObserver.instances.length - 1];
}

function row(id: number | string): HTMLElement {
    return shadow().querySelector<HTMLElement>(`.turn.staff[data-id="${id}"]`)!;
}

function show(id: number | string, showing = true): void {
    const target = row(id);
    expect(observer().targets.has(target)).toBe(true);
    observer().fire(target, showing);
}

async function flush(): Promise<void> {
    for (let i = 0; i < 10; i++) {
        await Promise.resolve();
    }
}

async function wait(ms: number): Promise<void> {
    await vi.advanceTimersByTimeAsync(ms);
    await flush();
}

function poll(messages: unknown[]): void {
    replies.updates.push({ status: 200, body: { status: 'needs_teammate', last_message_id: 99, messages, support: support('needs_teammate') } });
}

beforeAll(async () => {
    vi.useFakeTimers();
    vi.stubGlobal('IntersectionObserver', FakeObserver);
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => visibility });
    vi.stubGlobal('fetch', async (url: string, init: RequestInit) => {
        const key = url.includes('bootstrap') ? 'bootstrap' : url.includes('/updates') ? 'updates' : url.includes('/seen') ? 'seen' : 'other';

        if (key === 'seen') {
            receipts.push(JSON.parse(String(init.body)));
        }

        const reply = replies[key]?.shift() ?? (key === 'seen' ? { status: 200, body: { ok: true } } : undefined);

        if (!reply) {
            throw new TypeError('Failed to fetch');
        }

        return new Response(JSON.stringify(reply.body), { status: reply.status, headers: { 'Content-Type': 'application/json' } });
    });

    const script = document.createElement('script');
    script.dataset.unifiedAssistant = JSON.stringify({
        bootstrap: '/assistant/bootstrap',
        messages: '/assistant/messages',
        uploads: '/assistant/uploads',
        undo: '/assistant/actions/{action}/undo',
        review: '/assistant/review/{plan}/{verb}',
        conversation: '/assistant/conversation',
        escalate: '/assistant/escalate',
        updates: '/assistant/updates',
        seen: '/assistant/seen',
        csrf: 'token',
    });
    document.body.append(script);
    replies.bootstrap.push({
        status: 200,
        body: thread(
            [
                { id: 1, role: 'user', content: 'The export failed' },
                { id: 2, role: 'system', content: 'Handed to our team' },
                { id: 4, role: 'staff', author: 'Val Ramos', content: 'Looking now.' },
                { id: 5, role: 'staff', author: 'Val Ramos', content: 'Fixed, try again.' },
            ],
            { message_id: 4, at: '2026-11-17T15:00:00Z' },
        ),
    });

    await import('../src/index');
    await flush();
});

afterAll(() => {
    vi.useRealTimers();
});

describe('widget: read receipts for our team', () => {
    it('watches our team replies inside the thread', () => {
        expect(observer().options.root).toBe(shadow().querySelector('.list'));
        expect(observer().targets.size).toBe(2);
    });

    it('sends nothing while the drawer is closed', async () => {
        show(5);
        await wait(3000);

        expect(receipts).toHaveLength(0);
    });

    it('reports the newest reply on screen 1 s after the drawer opens, once', async () => {
        window.UnifiedAssistant!.open();
        await flush();
        poll([]);
        await wait(900);
        expect(receipts).toHaveLength(0);

        await wait(200);
        expect(receipts).toEqual([{ conversation_id: 7, last_message_id: 5 }]);

        show(5, false);
        show(5);
        show(4);
        await wait(3000);
        expect(receipts).toHaveLength(1);
    });

    it('debounces replies that come on screen close together into one receipt', async () => {
        receipts.length = 0;
        poll([
            { id: 6, role: 'staff', author: 'Val Ramos', content: 'One more thing.' },
            { id: 7, role: 'staff', author: 'Val Ramos', content: 'And another.' },
        ]);
        await wait(10000);

        show(6);
        await wait(500);
        show(7);
        await wait(900);
        expect(receipts).toHaveLength(0);

        await wait(200);
        expect(receipts).toEqual([{ conversation_id: 7, last_message_id: 7 }]);
    });

    it('waits while the page is hidden and reports when it shows again', async () => {
        receipts.length = 0;
        visibility = 'hidden';
        document.dispatchEvent(new Event('visibilitychange'));
        poll([{ id: 8, role: 'staff', author: 'Val Ramos', content: 'Are you there?' }]);
        await wait(20000);

        show(8);
        await wait(3000);
        expect(receipts).toHaveLength(0);

        visibility = 'visible';
        document.dispatchEvent(new Event('visibilitychange'));
        await wait(1100);
        expect(receipts).toEqual([{ conversation_id: 7, last_message_id: 8 }]);
    });

    it('sends the same reply again after a receipt that did not go through', async () => {
        receipts.length = 0;
        poll([{ id: 9, role: 'staff', author: 'Val Ramos', content: 'Done.' }]);
        await wait(10000);

        replies.seen.push({ status: 500, body: {} });
        show(9);
        await wait(1100);
        expect(receipts).toEqual([{ conversation_id: 7, last_message_id: 9 }]);

        show(9, false);
        show(9);
        await wait(1100);
        expect(receipts).toHaveLength(2);
    });
});
