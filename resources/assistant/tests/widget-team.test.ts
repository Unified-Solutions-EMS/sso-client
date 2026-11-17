import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

type Reply = { status: number; body: unknown };

const replies: Record<string, Reply[]> = { bootstrap: [], messages: [], conversation: [], escalate: [], updates: [] };
const sent: Array<Record<string, unknown>> = [];
const escalated: Array<Record<string, unknown>> = [];
const polled: string[] = [];

const TEAM_HAS_IT = 'Our team has your message. Replies show up here.';
const support = (status: string): unknown => ({ enabled: true, status, last_message_id: 0 });

const thread = (id: number, messages: unknown[], status = 'assistant', suggestions: unknown[] = [{ label: 'Add a person', prompt: 'Add a person to our roster' }]): unknown => ({
    enabled: true,
    viewer: 'v1',
    conversation: { id, title: null, messages },
    conversations: [],
    suggestions,
    support: support(status),
});

function respond(reply: Reply): Response {
    return new Response(JSON.stringify(reply.body), { status: reply.status, headers: { 'Content-Type': 'application/json' } });
}

function shadow(): ShadowRoot {
    return document.querySelector('unified-assistant')!.shadowRoot!;
}

function composer(): HTMLTextAreaElement {
    return shadow().querySelector('textarea')!;
}

function pillLabels(): string[] {
    return Array.from(shadow().querySelectorAll('.suggestions .pill')).map((pill) => pill.textContent ?? '');
}

async function flush(): Promise<void> {
    for (let i = 0; i < 10; i++) {
        await Promise.resolve();
    }
}

async function type(text: string): Promise<void> {
    composer().value = text;
    composer().dispatchEvent(new Event('input'));
    shadow().querySelector<HTMLButtonElement>('.send')!.click();
    await flush();
}

async function newConversation(body: unknown): Promise<void> {
    replies.conversation.push({ status: 200, body });
    shadow().querySelector<HTMLButtonElement>('.new-conversation')!.click();
    await flush();
}

beforeAll(async () => {
    vi.stubGlobal('fetch', async (url: string, init: RequestInit) => {
        const key = url.includes('bootstrap') ? 'bootstrap' : url.includes('/escalate') ? 'escalate' : url.includes('/updates') ? 'updates' : url.endsWith('/conversation') ? 'conversation' : 'messages';

        if (key === 'messages') {
            sent.push(JSON.parse(String(init.body)));
        }

        if (key === 'escalate') {
            escalated.push(JSON.parse(String(init.body)));
        }

        if (key === 'updates') {
            polled.push(url);
        }

        const reply = replies[key].shift();

        if (!reply) {
            throw new TypeError('Failed to fetch');
        }

        return respond(reply);
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
        escalate_message: true,
        csrf: 'token',
    });
    document.body.append(script);
    replies.bootstrap.push({ status: 200, body: thread(7, []) });

    await import('../src/index');
    await flush();
    window.UnifiedAssistant!.open();
});

afterEach(() => {
    vi.useRealTimers();
    sent.length = 0;
    escalated.length = 0;
    polled.length = 0;
});

describe('widget: Talk to our team at any time', () => {
    it('puts Talk to our team last in the pills and next to New conversation', () => {
        expect(pillLabels()).toEqual(['Add a person', 'Talk to our team']);

        const entry = shadow().querySelector<HTMLElement>('.team-entry')!;
        expect(entry.hidden).toBe(false);
        expect(entry.previousElementSibling!.classList.contains('new-conversation')).toBe(true);
        expect(entry.querySelector('.talk-to-team')!.textContent).toBe('Talk to our team');
    });

    it('asks what they need from the pill without escalating, and removing the chip sends to the Assistant again', async () => {
        shadow().querySelector<HTMLButtonElement>('.pill.team')!.click();
        await flush();

        expect(escalated).toHaveLength(0);
        expect(Array.from(shadow().querySelectorAll('.turn.assistant .bubble')).pop()!.textContent).toBe('Tell me what you need and I will pass it to our team.');
        const chip = shadow().querySelector<HTMLElement>('.team-mode')!;
        expect(chip.hidden).toBe(false);
        expect(chip.textContent).toContain('To our team');
        expect(pillLabels()).toEqual([]);

        chip.querySelector<HTMLButtonElement>('.remove')!.click();
        expect(chip.hidden).toBe(true);

        replies.messages.push({ status: 200, body: { reply: 'Done.', message_id: 3, support: support('assistant') } });
        await type('add Jane Doe');

        expect(escalated).toHaveLength(0);
        expect(sent).toEqual([expect.objectContaining({ message: 'add Jane Doe', conversation_id: 7 })]);
    });

    it('sends the next message to our team from the menu item, then shows who has the conversation', async () => {
        await newConversation(thread(8, []));

        shadow().querySelector<HTMLButtonElement>('.history')!.click();
        shadow().querySelector<HTMLButtonElement>('.talk-to-team')!.click();
        await flush();

        expect(shadow().querySelector<HTMLElement>('.panel')!.hidden).toBe(true);
        expect(shadow().querySelector<HTMLElement>('.team-mode')!.hidden).toBe(false);
        expect(escalated).toHaveLength(0);

        vi.useFakeTimers();
        replies.escalate.push({
            status: 200,
            body: thread(
                8,
                [
                    { id: 11, role: 'user', content: 'Our state export keeps failing' },
                    { id: 12, role: 'system', content: 'Handed to our team. Replies show up here. We will also email you at jane@agency.test if you have left.' },
                    { id: 13, role: 'assistant', content: TEAM_HAS_IT },
                ],
                'needs_teammate',
            ),
        });
        await type('Our state export keeps failing');

        expect(sent).toHaveLength(0);
        expect(escalated).toEqual([{ question: 'Our state export keeps failing', message: 'Our state export keeps failing', conversation_id: 8 }]);
        expect(shadow().querySelectorAll('.turn.user')).toHaveLength(1);
        expect(shadow().querySelector('.system-line')!.textContent).toContain('We will also email you at jane@agency.test');
        expect(Array.from(shadow().querySelectorAll('.turn.assistant .bubble')).pop()!.textContent).toBe(TEAM_HAS_IT);
        expect(shadow().querySelector<HTMLElement>('.team-mode')!.hidden).toBe(true);

        const entry = shadow().querySelector<HTMLElement>('.team-entry')!;
        expect(entry.querySelector('.talk-to-team')).toBeNull();
        expect(entry.querySelector('.team-status')!.textContent).toBe('Our team has this conversation');

        replies.updates.push({ status: 200, body: { status: 'needs_teammate', last_message_id: 13, messages: [] } });
        await vi.advanceTimersByTimeAsync(5000);
        await flush();
        expect(polled).toEqual(['/assistant/updates?conversation=8&after=13']);

        replies.updates.push({ status: 200, body: { status: 'resolved', last_message_id: 13, messages: [] } });
        await vi.advanceTimersByTimeAsync(5000);
        await flush();
        expect(entry.querySelector('.talk-to-team')).not.toBeNull();
    });

    it('shows the status line and no pill on a thread our team already has', async () => {
        await newConversation(thread(9, [], 'needs_teammate'));

        expect(pillLabels()).toEqual(['Add a person']);
        expect(shadow().querySelector('.team-entry .talk-to-team')).toBeNull();
        expect(shadow().querySelector('.team-entry .team-status')!.textContent).toBe('Our team has this conversation');
    });

    it('offers the pill even when SSO sends no other ideas', async () => {
        await newConversation(thread(10, [], 'assistant', []));

        expect(pillLabels()).toEqual(['Talk to our team']);
        expect(shadow().querySelector<HTMLElement>('.link.ideas')!.hidden).toBe(false);
    });

    it('keeps the text and the chip when the handover fails, and Try again sends it to our team', async () => {
        await newConversation(thread(12, []));
        shadow().querySelector<HTMLButtonElement>('.pill.team')!.click();
        await flush();

        replies.escalate.push({ status: 502, body: {} });
        await type('Please call me about billing');

        expect(escalated).toHaveLength(1);
        expect(composer().value).toBe('Please call me about billing');
        expect(shadow().querySelector<HTMLElement>('.team-mode')!.hidden).toBe(false);

        replies.escalate.push({ status: 200, body: thread(12, [{ id: 30, role: 'user', content: 'Please call me about billing' }, { id: 31, role: 'assistant', content: TEAM_HAS_IT }], 'needs_teammate') });
        replies.updates.push({ status: 200, body: { status: 'needs_teammate', last_message_id: 31, messages: [] } });
        shadow().querySelector<HTMLButtonElement>('.turn.failed .retry')!.click();
        await flush();

        expect(escalated).toHaveLength(2);
        expect(escalated[1]).toEqual(expect.objectContaining({ message: 'Please call me about billing' }));
        expect(sent).toHaveLength(0);
    });
});
