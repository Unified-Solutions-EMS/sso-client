import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';

type Handler = (data: unknown) => void;

const fake = vi.hoisted(() => {
    class FakeChannel {
        handlers = new Map<string, Handler[]>();

        constructor(public name: string) {}

        bind(event: string, handler: Handler): this {
            this.handlers.set(event, [...(this.handlers.get(event) ?? []), handler]);

            return this;
        }

        emit(event: string, data: unknown = {}): void {
            for (const handler of this.handlers.get(event) ?? []) {
                handler(data);
            }
        }

        trigger(): boolean {
            return true;
        }
    }

    class FakePusher {
        static instances: FakePusher[] = [];
        channels = new Map<string, FakeChannel>();
        stateHandlers: Handler[] = [];
        connection = {
            bind: (event: string, handler: Handler) => {
                if (event === 'state_change') {
                    this.stateHandlers.push(handler);
                }
            },
        };

        constructor() {
            FakePusher.instances.push(this);
        }

        subscribe(name: string): FakeChannel {
            const channel = new FakeChannel(name);
            this.channels.set(name, channel);

            return channel;
        }

        channel(name: string): FakeChannel | undefined {
            return this.channels.get(name);
        }

        disconnect(): void {}

        state(current: string): void {
            for (const handler of this.stateHandlers) {
                handler({ previous: 'connecting', current });
            }
        }

        only(): FakeChannel {
            return Array.from(this.channels.values())[0];
        }
    }

    return { FakePusher };
});

vi.mock('pusher-js', () => ({ default: fake.FakePusher }));

type Reply = { status: number; body: unknown };

const replies: Record<string, Reply[]> = { bootstrap: [], feedback: [], updates: [], rating: [], messages: [] };
const calls: Array<{ key: string; url: string; body: Record<string, unknown> | null }> = [];

const TEXT = 'Hi Jane,\n\nWe fixed the crew list so it prints every member again.\n\nIs it working for you now?';

const rating = (score: number | null = null): unknown => ({ enabled: true, score, note: null, rated_at: null, phone: null });

const realtime = {
    host: 'ws-test-reverb.laravel.cloud',
    port: 443,
    scheme: 'https',
    key: 'reverb-key',
    channel: 'support.conversation.77',
    auth_url: 'https://crm.test/broadcasting/auth/customer',
    token: 'token-1',
    name: 'Jane Doe',
};

const update = (state: string | null, extra: Record<string, unknown> = {}): Record<string, unknown> => ({
    id: 501,
    role: 'staff',
    author: 'Halligan',
    crm_message_id: 88140,
    content: TEXT,
    kind: 'automated',
    confirm: state === null ? null : { state, answered_at: state === 'pending' ? null : '2026-11-21T14:05:00+00:00' },
    ...extra,
});

const history = [
    { id: 1, role: 'user', content: 'The crew list prints blank.' },
    { id: 2, role: 'assistant', content: 'I passed this to our team.' },
    { id: 3, role: 'staff', author: 'Val Ramos', crm_message_id: 88001, content: 'We are looking at it.' },
];

const thread = (messages: unknown[], status = 'resolved', live: unknown = null, score: number | null = null): unknown => ({
    enabled: true,
    viewer: 'v1',
    conversation: { id: 7, title: null, messages },
    conversations: [{ id: 9, title: 'Earlier', last_activity_at: null, message_count: 2 }],
    suggestions: [],
    feedback: { ask: true },
    support: { enabled: true, status, last_message_id: 501, realtime: live },
    rating: rating(score),
});

function shadow(): ShadowRoot {
    return document.querySelector('unified-assistant')!.shadowRoot!;
}

function automated(): HTMLElement {
    return shadow().querySelector<HTMLElement>('.turn.staff.automated')!;
}

function buttons(): HTMLButtonElement[] {
    return Array.from(shadow().querySelectorAll<HTMLButtonElement>('.turn.staff.automated .confirm button'));
}

async function flush(): Promise<void> {
    for (let i = 0; i < 14; i++) {
        await Promise.resolve();
    }
}

async function wait(ms: number): Promise<void> {
    await vi.advanceTimersByTimeAsync(ms);
    await flush();
}

/** Applies a thread the way a reload does: SSO's bootstrap is the only source. */
async function load(body: unknown): Promise<void> {
    replies.bootstrap.push({ status: 200, body });
    shadow().querySelector<HTMLButtonElement>('.threads .thread')!.click();
    await flush();
}

function pusher(): InstanceType<typeof fake.FakePusher> {
    return fake.FakePusher.instances[fake.FakePusher.instances.length - 1];
}

function feedbackCalls(): Array<Record<string, unknown> | null> {
    return calls.filter((call) => call.key === 'feedback').map((call) => call.body);
}

beforeAll(async () => {
    vi.useFakeTimers();
    vi.stubGlobal('fetch', async (url: string, init: RequestInit = {}) => {
        const key = url.includes('bootstrap') ? 'bootstrap' : url.endsWith('/feedback') ? 'feedback' : url.includes('/updates') ? 'updates' : url.endsWith('/rating') ? 'rating' : 'messages';

        calls.push({ key, url, body: init.body ? JSON.parse(String(init.body)) : null });

        const reply = replies[key].shift();

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
        feedback: '/assistant/feedback',
        feedback_follow_up: '/assistant/feedback/{feedback}/follow-up',
        rating: '/assistant/rating',
        csrf: 'token',
    });
    document.body.append(script);
    replies.bootstrap.push({ status: 200, body: thread([...history, update('pending')]) });

    await import('../src/index');
    await flush();
    window.UnifiedAssistant!.open();
    await flush();
});

afterAll(() => {
    vi.useRealTimers();
});

describe('widget: an automated update from our team', () => {
    it('shows the sender by name with the AI agent tag, in its own style', () => {
        const turn = automated();

        expect(turn.querySelector('.staff-name')!.textContent).toBe('Halligan');
        expect(turn.querySelector('.tag')!.textContent).toBe('AI agent');
        expect(turn.querySelector('.avatar')!.textContent).toBe('H');
        expect(turn.querySelector('.bubble')!.textContent).toContain('Is it working for you now?');
        expect(shadow().textContent).not.toContain('Halligan Unified');
    });

    it('leaves an ordinary teammate reply as it was', () => {
        const teammate = shadow().querySelector<HTMLElement>('.turn.staff[data-id="3"]')!;

        expect(teammate.classList.contains('automated')).toBe(false);
        expect(teammate.querySelector('.tag')!.textContent).toBe('Unified');
        expect(teammate.querySelector('.avatar')!.textContent).toBe('VR');
        expect(teammate.querySelector('.confirm')).toBeNull();
    });

    it('asks with two buttons and holds the rating and the task question back', () => {
        expect(buttons().map((button) => button.textContent)).toEqual(["Yes, it's fixed", 'Not yet']);
        expect(buttons().every((button) => !button.disabled)).toBe(true);
        expect(shadow().querySelector('.rating-card')).toBeNull();
        expect(shadow().querySelector('.feedback-card')).toBeNull();
    });

    it('brings the buttons back with a line when the answer does not go through', async () => {
        replies.feedback.push({ status: 503, body: { ok: false, error: 'That did not go through. Try again.' } });
        buttons()[0].click();
        expect(buttons().every((button) => button.disabled)).toBe(true);
        await flush();

        expect(buttons().every((button) => !button.disabled)).toBe(true);
        expect(automated().querySelector('.confirm-error')!.textContent).toBe('That did not go through. Try again.');
        expect(automated().querySelector('.confirm-said')).toBeNull();
        expect(shadow().querySelector('.rating-card')).toBeNull();
    });

    it('sends "Yes, it\'s fixed" over the feedback route, shows the answer and then the rating card', async () => {
        calls.length = 0;
        replies.feedback.push({ status: 200, body: { ok: true, confirm: { state: 'fixed', answered_at: '2026-11-21T14:05:00+00:00' }, status: 'resolved', rating: rating() } });
        buttons()[0].click();
        expect(automated().querySelector('.confirm-error')).toBeNull();
        expect(buttons().every((button) => button.disabled)).toBe(true);
        await flush();

        expect(feedbackCalls()).toEqual([{ conversation_id: 7, message_id: 501, helpful: true }]);
        expect(buttons()).toHaveLength(0);
        expect(automated().querySelector('.confirm-said')!.textContent).toBe("You said: Yes, it's fixed. Thanks for letting us know.");
        expect(shadow().querySelector('.rating-card')).not.toBeNull();
        expect(shadow().querySelector('.feedback-card')).toBeNull();
    });

    it('shows the recorded answer after a reload, with no buttons', async () => {
        await load(thread([...history, update('fixed')], 'resolved', null, 4));

        expect(buttons()).toHaveLength(0);
        expect(automated().querySelector('.confirm-said')!.textContent).toBe("You said: Yes, it's fixed. Thanks for letting us know.");
        expect(automated().querySelector('.tag')!.textContent).toBe('AI agent');
    });

    it('sends "Not yet", says a person will follow up and offers no rating', async () => {
        await load(thread([...history, update('pending')]));
        calls.length = 0;
        replies.feedback.push({ status: 200, body: { ok: true, confirm: { state: 'not_fixed', answered_at: '2026-11-21T14:06:00+00:00' }, status: 'needs_teammate', rating: rating() } });
        buttons()[1].click();
        await flush();

        expect(feedbackCalls()).toEqual([{ conversation_id: 7, message_id: 501, helpful: false }]);
        expect(buttons()).toHaveLength(0);
        expect(automated().querySelector('.confirm-said')!.textContent).toBe('You said: Not yet. A person from our team will follow up with you here.');
        expect(shadow().querySelector('.rating-card')).toBeNull();
        expect(shadow().querySelector('.team-status')!.textContent).toBe('Our team has this conversation');
    });

    it('renders an unknown kind as a teammate reply and an unknown answer state with no buttons', async () => {
        await load(thread([...history, update('pending', { kind: 'broadcast' })], 'resolved', null, 4));

        expect(shadow().querySelector('.turn.staff.automated')).toBeNull();
        const turn = shadow().querySelector<HTMLElement>('.turn.staff[data-id="501"]')!;
        expect(turn.querySelector('.staff-name')!.textContent).toBe('Halligan');
        expect(turn.querySelector('.tag')!.textContent).toBe('Unified');
        expect(turn.querySelector('.confirm')).toBeNull();

        await load(thread([...history, update('withdrawn')], 'resolved', null, 4));
        expect(automated().querySelector('.tag')!.textContent).toBe('AI agent');
        expect(automated().querySelector('.confirm')).toBeNull();

        await load(thread([...history, update(null)], 'resolved', null, 4));
        expect(automated().querySelector('.confirm')).toBeNull();
    });

    it('offers the rating as before once the person has written after an unanswered update', async () => {
        await load(thread([...history, update('pending'), { id: 502, role: 'user', content: 'Thanks' }, { id: 503, role: 'assistant', content: 'Glad to help.' }]));

        expect(buttons()).toHaveLength(2);
        expect(shadow().querySelector('.rating-card')).not.toBeNull();
    });

    it('keeps the style and buttons of an update heard live once the poll confirms it', async () => {
        await load(thread(history, 'needs_teammate', realtime));
        replies.updates.push({ status: 200, body: { status: 'needs_teammate', last_message_id: 3, messages: [] } });
        pusher().state('connected');
        pusher().only().emit('pusher:subscription_succeeded');
        await wait(0);

        pusher().only().emit('message.created', {
            message: { id: 900, author_type: 'staff', author_name: 'Halligan', text: TEXT, attachments: [], created_at: '2026-11-21T14:00:00Z', sso_message_id: null, kind: 'automated', confirm: { state: 'pending', answered_at: null } },
        });
        await flush();

        expect(automated().getAttribute('data-id')).toBe('crm-900');
        expect(buttons()).toHaveLength(2);

        replies.updates.push({ status: 200, body: { status: 'needs_teammate', last_message_id: 610, messages: [update('pending', { id: 610, crm_message_id: 900 })] } });
        await wait(60000);

        expect(shadow().querySelectorAll('.turn.staff.automated')).toHaveLength(1);
        expect(automated().getAttribute('data-id')).toBe('610');
        expect(automated().querySelector('.tag')!.textContent).toBe('AI agent');
        expect(buttons()).toHaveLength(2);
    });

    it('applies an answer given somewhere else when the socket reports it', async () => {
        pusher().only().emit('message.updated', { message: { id: 900, delivery_status: 'delivered', delivery_error: null } });
        pusher().only().emit('message.updated', { message: { id: 12345, delivery_status: 'delivered', delivery_error: null, confirm: { state: 'fixed', answered_at: '2026-11-21T14:05:00+00:00' } } });
        await flush();
        expect(buttons()).toHaveLength(2);

        pusher().only().emit('message.updated', { message: { id: 900, delivery_status: 'delivered', delivery_error: null, confirm: { state: 'not_fixed', answered_at: '2026-11-21T14:05:00+00:00' } } });
        await flush();

        expect(buttons()).toHaveLength(0);
        expect(automated().querySelector('.confirm-said')!.textContent).toBe('You said: Not yet. A person from our team will follow up with you here.');

        pusher().only().emit('message.updated', { message: { id: 900, confirm: { state: 'pending', answered_at: null } } });
        await flush();
        expect(buttons()).toHaveLength(0);
    });

    it('gains the style and buttons when only the poll carries the new fields', async () => {
        await load(thread(history, 'needs_teammate', realtime));
        replies.updates.push({ status: 200, body: { status: 'needs_teammate', last_message_id: 3, messages: [] } });
        pusher().state('connected');
        pusher().only().emit('pusher:subscription_succeeded');
        await wait(0);

        pusher().only().emit('message.created', { message: { id: 901, author_type: 'staff', author_name: 'Halligan', text: TEXT, sso_message_id: null } });
        await flush();

        const plain = shadow().querySelector<HTMLElement>('.turn.staff[data-id="crm-901"]')!;
        expect(plain.classList.contains('automated')).toBe(false);
        expect(plain.querySelector('.tag')!.textContent).toBe('Unified');

        replies.updates.push({ status: 200, body: { status: 'needs_teammate', last_message_id: 611, messages: [update('pending', { id: 611, crm_message_id: 901 })] } });
        await wait(60000);

        expect(automated()).toBe(plain);
        expect(automated().getAttribute('data-id')).toBe('611');
        expect(automated().querySelector('.tag')!.textContent).toBe('AI agent');
        expect(buttons()).toHaveLength(2);
    });

    it('asks SSO for the message first when an update heard live is answered before any poll', async () => {
        await load(thread(history, 'needs_teammate', realtime));
        replies.updates.push({ status: 200, body: { status: 'needs_teammate', last_message_id: 3, messages: [] } });
        pusher().state('connected');
        pusher().only().emit('pusher:subscription_succeeded');
        await wait(0);

        pusher().only().emit('message.created', {
            message: { id: 902, author_type: 'staff', author_name: 'Halligan', text: TEXT, sso_message_id: null, kind: 'automated', confirm: { state: 'pending', answered_at: null } },
        });
        await flush();

        calls.length = 0;
        replies.updates.push({ status: 200, body: { status: 'needs_teammate', last_message_id: 612, messages: [update('pending', { id: 612, crm_message_id: 902 })] } });
        replies.feedback.push({ status: 200, body: { ok: true, confirm: { state: 'fixed', answered_at: '2026-11-21T14:05:00+00:00' }, status: 'resolved', rating: rating() } });
        buttons()[0].click();
        await flush();
        await flush();

        expect(calls.map((call) => call.key)).toEqual(['updates', 'feedback']);
        expect(feedbackCalls()).toEqual([{ conversation_id: 7, message_id: 612, helpful: true }]);
        expect(automated().querySelector('.confirm-said')!.textContent).toBe("You said: Yes, it's fixed. Thanks for letting us know.");
        expect(shadow().querySelector('.rating-card')).not.toBeNull();
    });
});
