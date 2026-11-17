import { beforeAll, describe, expect, it, vi } from 'vitest';

type Reply = { status: number; body: unknown };

const replies: Record<string, Reply[]> = { bootstrap: [], messages: [] };

const LINK = { label: 'Open in Analytics', url: 'https://unifiedreporting.unified-apps.com/dashboard?analyst=95' };

function shadow(): ShadowRoot {
    return document.querySelector('unified-assistant')!.shadowRoot!;
}

async function flush(): Promise<void> {
    for (let i = 0; i < 10; i++) {
        await Promise.resolve();
    }
}

async function type(text: string): Promise<void> {
    const composer = shadow().querySelector('textarea')!;
    composer.value = text;
    composer.dispatchEvent(new Event('input'));
    shadow().querySelector<HTMLButtonElement>('.send')!.click();
    await flush();
}

function lastAssistant(): HTMLElement {
    const turns = shadow().querySelectorAll<HTMLElement>('.turn.assistant');

    return turns[turns.length - 1];
}

beforeAll(async () => {
    vi.stubGlobal('fetch', async (url: string) => {
        const reply = replies[url.includes('bootstrap') ? 'bootstrap' : 'messages'].shift();

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
                title: 'Calls',
                messages: [
                    { id: 1, role: 'user', content: 'Where is https://example.com/mine?' },
                    { id: 2, role: 'assistant', content: 'You ran 40 calls last week.', links: [LINK] },
                ],
            },
            conversations: [],
            suggestions: [],
        },
    });

    await import('../src/index');
    await flush();
    window.UnifiedAssistant!.open();
});

describe('widget: links under a reply', () => {
    it('shows the links of a reopened thread as chips and leaves the person\'s own text alone', () => {
        const chip = lastAssistant().querySelector<HTMLAnchorElement>('.links a.link-chip')!;

        expect(chip.textContent).toBe('Open in Analytics');
        expect(chip.getAttribute('href')).toBe(LINK.url);
        expect(chip.target).toBe('_blank');
        expect(chip.rel).toBe('noopener');
        expect(shadow().querySelector('.turn.user a')).toBeNull();
    });

    it('renders a turn\'s links as chips and auto-links an address left in the text', async () => {
        replies.messages.push({
            status: 200,
            body: {
                reply: 'You ran 42 calls this week. Details: https://unifiedreporting.unified-apps.com/dashboard?analyst=96',
                message_id: 4,
                links: [{ ...LINK, url: 'https://unifiedreporting.unified-apps.com/dashboard?analyst=96' }, { label: 'Bad', url: 'javascript:alert(1)' }],
            },
        });
        await type('how many calls did we run this week');

        const turn = lastAssistant();
        const inText = turn.querySelector<HTMLAnchorElement>('.bubble a')!;
        expect(inText.textContent).toBe('unifiedreporting.unified-apps.com/dashboard');
        expect(turn.querySelector('.bubble')!.textContent).not.toContain('https://');

        const chips = turn.querySelectorAll<HTMLAnchorElement>('.links a.link-chip');
        expect(chips).toHaveLength(1);
        expect(chips[0].textContent).toBe('Open in Analytics');
        expect(chips[0].getAttribute('href')).toBe('https://unifiedreporting.unified-apps.com/dashboard?analyst=96');
    });

    it('shows a reply that is only a link', async () => {
        replies.messages.push({ status: 200, body: { reply: '', message_id: 5, links: [LINK] } });
        await type('open it');

        expect(lastAssistant().querySelector('.bubble')).toBeNull();
        expect(lastAssistant().querySelector('.links a')!.textContent).toBe('Open in Analytics');
    });
});
