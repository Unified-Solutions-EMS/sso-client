import { beforeAll, describe, expect, it, vi } from 'vitest';

type Reply = { status: number; body: unknown };

const replies: Record<string, Reply[]> = { bootstrap: [], messages: [], escalate: [], updates: [] };
const sent: Array<Record<string, unknown>> = [];
const escalated: Array<Record<string, unknown>> = [];

const TEAM_HAS_IT = 'Our team has your message. Replies show up here.';
const support = (status: string): unknown => ({ enabled: true, status, last_message_id: 0 });

const thread = (messages: unknown[], status = 'assistant'): unknown => ({
    enabled: true,
    viewer: 'v1',
    conversation: { id: 7, title: null, messages },
    conversations: [],
    suggestions: [],
    support: support(status),
});

function shadow(): ShadowRoot {
    return document.querySelector('unified-assistant')!.shadowRoot!;
}

async function flush(): Promise<void> {
    for (let i = 0; i < 40; i++) {
        await Promise.resolve();
    }
}

beforeAll(async () => {
    vi.stubGlobal('fetch', async (url: string, init: RequestInit) => {
        const key = url.includes('bootstrap') ? 'bootstrap' : url.includes('/escalate') ? 'escalate' : url.includes('/updates') ? 'updates' : 'messages';

        if (key === 'messages') {
            sent.push(JSON.parse(String(init.body)));
        }

        if (key === 'escalate') {
            escalated.push(JSON.parse(String(init.body)));
        }

        const reply = replies[key].shift();

        if (!reply) {
            throw new TypeError('Failed to fetch');
        }

        return new Response(JSON.stringify(reply.body), { status: reply.status, headers: { 'Content-Type': 'application/json' } });
    });

    // An app on an older sso-client: its proxy drops `message` on escalate and its component sends no escalate_message.
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
    replies.bootstrap.push({ status: 200, body: thread([]) });

    await import('../src/index');
    await flush();
    window.UnifiedAssistant!.open();
});

describe('widget: Talk to our team through an older component', () => {
    it('hands the conversation over, then sends the text as a message our team keeps', async () => {
        shadow().querySelector<HTMLButtonElement>('.pill.team')!.click();
        await flush();

        replies.escalate.push({ status: 200, body: thread([{ id: 3, role: 'system', content: 'Handed to our team' }], 'needs_teammate') });
        replies.messages.push({ status: 200, body: { reply: TEAM_HAS_IT, message_id: 5, support: support('needs_teammate') } });

        const composer = shadow().querySelector('textarea')!;
        composer.value = 'Our state export keeps failing';
        composer.dispatchEvent(new Event('input'));
        shadow().querySelector<HTMLButtonElement>('.send')!.click();
        await flush();

        expect(escalated).toEqual([{ question: 'Our state export keeps failing', conversation_id: 7 }]);
        expect(sent).toEqual([expect.objectContaining({ message: 'Our state export keeps failing', conversation_id: 7 })]);
        expect(Array.from(shadow().querySelectorAll('.turn.user .bubble')).map((bubble) => bubble.textContent)).toEqual(['Our state export keeps failing']);
        expect(Array.from(shadow().querySelectorAll('.turn.assistant .bubble')).pop()!.textContent).toBe(TEAM_HAS_IT);
        expect(shadow().querySelector('.team-entry .team-status')!.textContent).toBe('Our team has this conversation');
    });
});
