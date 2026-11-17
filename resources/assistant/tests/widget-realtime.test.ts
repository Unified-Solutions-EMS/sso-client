import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';

type Handler = (data: unknown) => void;

const fake = vi.hoisted(() => {
    class FakeChannel {
        handlers = new Map<string, Handler[]>();
        triggered: Array<[string, unknown]> = [];

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

        trigger(event: string, data: unknown): boolean {
            this.triggered.push([event, data]);

            return true;
        }
    }

    class FakePusher {
        static instances: FakePusher[] = [];
        channels = new Map<string, FakeChannel>();
        disconnected = false;
        stateHandlers: Handler[] = [];
        connection = {
            bind: (event: string, handler: Handler) => {
                if (event === 'state_change') {
                    this.stateHandlers.push(handler);
                }
            },
        };

        constructor(
            public key: string,
            public options: Record<string, any>,
        ) {
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

        disconnect(): void {
            this.disconnected = true;
        }

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

const replies: Record<string, Reply[]> = { bootstrap: [], messages: [], updates: [], conversation: [] };
const polled: string[] = [];

const realtime = (token = 'token-1', channel = 'support.conversation.77'): unknown => ({
    host: 'ws-test-reverb.laravel.cloud',
    port: 443,
    scheme: 'https',
    key: 'reverb-key',
    channel,
    auth_url: 'https://crm.test/broadcasting/auth/customer',
    token,
    name: 'Jane Doe',
});

const support = (status: string, live: unknown = realtime()): unknown => ({ enabled: true, status, last_message_id: 3, realtime: live });

const thread = (id: number, messages: unknown[], status: string, live: unknown = realtime()): unknown => ({
    enabled: true,
    viewer: 'v1',
    conversation: { id, title: null, messages },
    conversations: [],
    suggestions: [],
    support: support(status, live),
});

const waiting = [
    { id: 1, role: 'user', content: 'export last month' },
    { id: 2, role: 'assistant', content: "I can't see the state export from here." },
    { id: 3, role: 'system', content: 'Handed to our team' },
];

function shadow(): ShadowRoot {
    return document.querySelector('unified-assistant')!.shadowRoot!;
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

function pusher(): InstanceType<typeof fake.FakePusher> {
    return fake.FakePusher.instances[fake.FakePusher.instances.length - 1];
}

function goLive(): void {
    pusher().state('connected');
    pusher().only().emit('pusher:subscription_succeeded');
}

function quiet(live: unknown = realtime()): void {
    replies.updates.push({ status: 200, body: { status: 'needs_teammate', last_message_id: 3, messages: [], support: support('needs_teammate', live) } });
}

beforeAll(async () => {
    vi.useFakeTimers();
    vi.stubGlobal('fetch', async (url: string) => {
        const key = url.includes('bootstrap') ? 'bootstrap' : url.includes('/updates') ? 'updates' : url.endsWith('/conversation') ? 'conversation' : 'messages';

        if (key === 'updates') {
            polled.push(url);
        }

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
        csrf: 'token',
    });
    document.body.append(script);
    replies.bootstrap.push({ status: 200, body: thread(7, waiting, 'needs_teammate') });

    await import('../src/index');
    await flush();
    window.UnifiedAssistant!.open();
    await flush();
});

afterAll(() => {
    vi.useRealTimers();
});

describe('widget: our team live over the CRM socket', () => {
    it('connects to the channel SSO names, with the token on the authorization request', () => {
        expect(fake.FakePusher.instances).toHaveLength(1);
        const options = pusher().options;

        expect(pusher().key).toBe('reverb-key');
        expect(options).toMatchObject({ wsHost: 'ws-test-reverb.laravel.cloud', wsPort: 443, wssPort: 443, forceTLS: true, enabledTransports: ['ws', 'wss'] });
        expect(options.channelAuthorization).toMatchObject({ endpoint: 'https://crm.test/broadcasting/auth/customer', transport: 'ajax' });
        expect(options.channelAuthorization.headersProvider()).toEqual({ 'X-Support-Token': 'token-1' });
        expect(pusher().only().name).toBe('private-support.conversation.77');
    });

    it('polls once on connecting, then only every 60 seconds while connected', async () => {
        quiet();
        goLive();
        await wait(0);
        expect(polled).toEqual(['/assistant/updates?conversation=7&after=3']);

        quiet();
        await wait(30000);
        expect(polled).toHaveLength(1);
        await wait(30000);
        expect(polled).toHaveLength(2);
    });

    it('shows a teammate reply the moment it is broadcast and does not repeat it when the poll brings it', async () => {
        pusher().only().emit('client-typing', { who: 'staff', name: 'Val Ramos' });
        const typing = shadow().querySelector<HTMLElement>('.typing')!;
        expect(typing.hidden).toBe(false);
        expect(typing.textContent).toBe('Val Ramos is typing');
        expect(shadow().querySelector('.list')!.lastElementChild).toBe(typing);

        pusher().only().emit('message.created', {
            message: { id: 555, author_type: 'staff', author_name: 'Val Ramos', text: 'I sent it for you.', text_html: '<p>I sent it for you.</p>', attachments: [], created_at: '2026-11-16T15:00:00Z', sso_message_id: null },
        });
        await flush();

        const staff = shadow().querySelectorAll('.turn.staff');
        expect(staff).toHaveLength(1);
        expect(staff[0].querySelector('.staff-name')!.textContent).toBe('Val Ramos');
        expect(staff[0].getAttribute('data-id')).toBe('crm-555');
        expect(typing.hidden).toBe(true);

        replies.updates.push({
            status: 200,
            body: { status: 'needs_teammate', last_message_id: 12, messages: [{ id: 12, role: 'staff', author: 'Val Ramos', content: 'I sent it for you.', crm_message_id: 555 }], support: support('needs_teammate') },
        });
        await wait(60000);
        expect(polled[polled.length - 1]).toBe('/assistant/updates?conversation=7&after=3');
        expect(shadow().querySelectorAll('.turn.staff')).toHaveLength(1);
        expect(shadow().querySelector('.turn.staff')!.getAttribute('data-id')).toBe('12');

        quiet();
        await wait(60000);
        expect(polled[polled.length - 1]).toBe('/assistant/updates?conversation=7&after=12');
    });

    it('ignores the customer’s own messages and lines it already has', async () => {
        pusher().only().emit('message.created', { message: { id: 556, author_type: 'customer', author_name: 'Jane Doe', text: 'Thanks', sso_message_id: 13 } });
        pusher().only().emit('message.created', { message: { id: 501, author_type: 'system', text: 'Handed to our team', sso_message_id: 3 } });
        pusher().only().emit('message.created', { message: { id: 555, author_type: 'staff', author_name: 'Val Ramos', text: 'I sent it for you.', sso_message_id: 12 } });
        await flush();

        expect(shadow().querySelectorAll('.turn.staff')).toHaveLength(1);
        expect(shadow().querySelectorAll('.system-line')).toHaveLength(1);
        expect(shadow().querySelectorAll('.turn.user')).toHaveLength(1);
    });

    it('tells our team the person is typing at most once every 3 seconds', async () => {
        const input = shadow().querySelector('textarea')!;
        const type = (text: string): void => {
            input.value = text;
            input.dispatchEvent(new Event('input'));
        };

        type('H');
        type('He');
        type('Hel');
        expect(pusher().only().triggered).toEqual([['client-typing', { who: 'customer', name: 'Jane Doe' }]]);

        await wait(3000);
        type('Hell');
        expect(pusher().only().triggered).toHaveLength(2);
        type('');
    });

    it('takes the fresher token from each poll for the next authorization', async () => {
        replies.updates.push({ status: 200, body: { status: 'needs_teammate', last_message_id: 12, messages: [], support: support('needs_teammate', realtime('token-2')) } });
        await wait(60000);

        expect(fake.FakePusher.instances).toHaveLength(1);
        expect(pusher().options.channelAuthorization.headersProvider()).toEqual({ 'X-Support-Token': 'token-2' });
    });

    it('goes back to polling every 5 seconds when the connection drops', async () => {
        const before = polled.length;
        pusher().state('unavailable');
        quiet();
        await wait(5000);
        expect(polled).toHaveLength(before + 1);

        quiet();
        goLive();
        await wait(0);
        expect(polled).toHaveLength(before + 2);
    });

    it('shows who has the conversation, then stops listening once our team resolves it', async () => {
        pusher().only().emit('conversation.updated', { status: 'open', support_status: 'needs_teammate', assigned_user_id: 4, assignee_name: 'Val Ramos' });
        await flush();
        expect(Array.from(shadow().querySelectorAll('.system-line')).pop()!.textContent).toBe('Val Ramos from our team has your conversation');

        pusher().only().emit('conversation.updated', { status: 'closed', support_status: 'resolved', assigned_user_id: 4, assignee_name: 'Val Ramos' });
        await flush();
        expect(Array.from(shadow().querySelectorAll('.system-line')).pop()!.textContent).toBe('Our team marked this resolved.');
        expect(pusher().disconnected).toBe(true);

        const before = polled.length;
        await wait(120000);
        expect(polled).toHaveLength(before);
    });

    it('listens on the new thread after New conversation, and falls back to polling when the channel is refused', async () => {
        replies.conversation.push({ status: 200, body: thread(8, waiting, 'needs_teammate', realtime('token-3', 'support.conversation.78')) });
        shadow().querySelector<HTMLButtonElement>('.new-conversation')!.click();
        await flush();

        expect(fake.FakePusher.instances).toHaveLength(2);
        expect(pusher().only().name).toBe('private-support.conversation.78');

        pusher().state('connected');
        pusher().only().emit('pusher:subscription_error', { status: 403 });
        expect(pusher().disconnected).toBe(true);

        const before = polled.length;
        quiet(realtime('token-3b', 'support.conversation.78'));
        await wait(5000);
        expect(polled).toHaveLength(before + 1);
        expect(polled[polled.length - 1]).toBe('/assistant/updates?conversation=8&after=3');

        quiet(realtime('token-3c', 'support.conversation.78'));
        await wait(5000);
        expect(polled).toHaveLength(before + 2);
        expect(fake.FakePusher.instances).toHaveLength(2);
    });

    it('leaves the channel for a thread the Assistant has', async () => {
        replies.conversation.push({ status: 200, body: thread(9, [], 'needs_teammate', realtime('token-4', 'support.conversation.79')) });
        shadow().querySelector<HTMLButtonElement>('.new-conversation')!.click();
        await flush();
        const listening = pusher();

        replies.conversation.push({ status: 200, body: thread(10, [], 'assistant', realtime('token-5', 'support.conversation.80')) });
        shadow().querySelector<HTMLButtonElement>('.new-conversation')!.click();
        await flush();

        expect(listening.disconnected).toBe(true);
        expect(fake.FakePusher.instances).toHaveLength(3);
    });
});
