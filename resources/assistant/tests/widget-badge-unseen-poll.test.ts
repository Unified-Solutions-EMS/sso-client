import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';

type Reply = { status: number; body: unknown };

const replies: Record<string, Reply[]> = { bootstrap: [], updates: [] };

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
        csrf: 'token',
    });
    document.body.append(script);
    replies.bootstrap.push({
        status: 200,
        body: {
            enabled: true,
            viewer: 'v1',
            conversation: { id: 7, title: null, messages: [{ id: 1, role: 'user', content: 'The export failed' }] },
            conversations: [],
            suggestions: [],
            support: { enabled: true, status: 'needs_teammate', last_message_id: 1, realtime: null },
        },
    });

    await import('../src/index');
    await flush();
});

afterAll(() => {
    vi.useRealTimers();
});

describe('widget: launcher badge from an updates poll', () => {
    it('shows no badge when SSO does not say our team wrote anything unseen', () => {
        expect(badge().hidden).toBe(true);
    });

    it('badges when a poll reports an unseen reply from our team, even without a new message in it', async () => {
        replies.updates.push({
            status: 200,
            body: { status: 'needs_teammate', last_message_id: 2, messages: [], support: { enabled: true, status: 'needs_teammate', last_message_id: 2, realtime: null, unseen_from_team: true } },
        });
        await wait(25000);

        expect(replies.updates).toHaveLength(0);
        expect(badge().hidden).toBe(false);
        expect(badge().textContent).toBe('1');
    });

    it('clears it when the thread is opened', async () => {
        window.UnifiedAssistant!.open();
        await flush();

        expect(badge().hidden).toBe(true);
    });
});
