import { beforeAll, describe, expect, it, vi } from 'vitest';

function shadow(): ShadowRoot {
    return document.querySelector('unified-assistant')!.shadowRoot!;
}

async function flush(): Promise<void> {
    for (let i = 0; i < 40; i++) {
        await Promise.resolve();
    }
}

beforeAll(async () => {
    sessionStorage.setItem('unified-assistant:v1:open', '1');

    vi.stubGlobal('fetch', async (url: string) => {
        if (!url.includes('bootstrap')) {
            throw new TypeError('Failed to fetch');
        }

        const body = {
            enabled: true,
            viewer: 'v1',
            conversation: { id: 7, title: null, messages: [{ id: 2, role: 'staff', author: 'Val Ramos', content: 'Want a hand?' }] },
            conversations: [],
            suggestions: [],
            support: { enabled: true, status: 'needs_teammate', last_message_id: 2, realtime: null, seen: null, unseen_from_team: true },
        };

        return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
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

    await import('../src/index');
    await flush();
});

describe('widget: launcher badge when the drawer comes back open', () => {
    it('does not badge a thread that is already in front of the person', () => {
        expect(shadow().querySelector('.drawer')!.classList.contains('open')).toBe(true);
        expect(shadow().querySelector<HTMLElement>('.badge')!.hidden).toBe(true);
    });
});
