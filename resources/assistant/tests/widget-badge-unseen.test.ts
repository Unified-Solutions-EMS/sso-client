import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';

type Reply = { status: number; body: unknown };

const replies: Record<string, Reply[]> = { bootstrap: [], updates: [] };

const support = (unseen: boolean): unknown => ({ enabled: true, status: 'needs_teammate', last_message_id: 2, realtime: null, seen: null, unseen_from_team: unseen });

function shadow(): ShadowRoot {
    return document.querySelector('unified-assistant')!.shadowRoot!;
}

function badge(): HTMLElement {
    return shadow().querySelector<HTMLElement>('.badge')!;
}

async function flush(): Promise<void> {
    for (let i = 0; i < 40; i++) {
        await Promise.resolve();
    }
}

async function wait(ms: number): Promise<void> {
    await vi.advanceTimersByTimeAsync(ms);
    await flush();
}

function poll(messages: unknown[], unseen: boolean): void {
    replies.updates.push({ status: 200, body: { status: 'needs_teammate', last_message_id: 9, messages, support: support(unseen) } });
}

beforeAll(async () => {
    vi.useFakeTimers();
    vi.stubGlobal('fetch', async (url: string) => {
        const key = url.includes('bootstrap') ? 'bootstrap' : url.includes('/updates') ? 'updates' : 'other';
        const reply = replies[key]?.shift();

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
        body: {
            enabled: true,
            viewer: 'v1',
            conversation: {
                id: 7,
                title: null,
                messages: [
                    { id: 1, role: 'system', content: 'Our team started this conversation' },
                    { id: 2, role: 'staff', author: 'Val Ramos', content: 'Hi, we noticed your export failed. Want a hand?' },
                ],
            },
            conversations: [],
            suggestions: [],
            support: support(true),
        },
    });

    await import('../src/index');
    await flush();
});

afterAll(() => {
    vi.useRealTimers();
});

describe('widget: launcher badge for an unopened conversation from our team', () => {
    it('badges the launcher on load when our team wrote past what the person has seen', () => {
        expect(badge().hidden).toBe(false);
        expect(badge().textContent).toBe('1');
    });

    it('clears the badge when the thread is opened', async () => {
        window.UnifiedAssistant!.open();
        await flush();

        expect(badge().hidden).toBe(true);
    });

    it('stays clear after closing, even while SSO still reports the reply as unseen', async () => {
        window.UnifiedAssistant!.close();
        await flush();
        poll([], true);
        await wait(25000);

        expect(replies.updates).toHaveLength(0);
        expect(badge().hidden).toBe(true);
    });

    it('badges again for a new reply that arrives while the drawer is closed', async () => {
        poll([{ id: 3, role: 'staff', author: 'Val Ramos', content: 'Still here if you need us.' }], true);
        await wait(25000);

        expect(badge().hidden).toBe(false);
        expect(badge().textContent).toBe('1');
    });
});
