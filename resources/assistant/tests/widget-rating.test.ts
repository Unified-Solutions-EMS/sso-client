import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { formatUsPhone, usPhoneDigits } from '../src/rating';

type Reply = { status: number; body: unknown };

const replies: Record<string, Reply[]> = { bootstrap: [], messages: [], rating: [], callback: [], clear: [], updates: [] };
const bodies: Record<string, Array<Record<string, unknown>>> = { rating: [], callback: [], clear: [], messages: [] };

const rating = (score: number | null = null): unknown => ({ enabled: true, score, note: null, rated_at: null, phone: '(814) 555-0100' });

const thread = (id: number, messages: unknown[], status = 'assistant', score: number | null = null): unknown => ({
    enabled: true,
    viewer: 'v1',
    conversation: { id, title: null, messages },
    conversations: [],
    suggestions: [],
    support: { enabled: true, status, last_message_id: 2 },
    rating: rating(score),
});

const answered = [
    { id: 1, role: 'user', content: 'Add Medic 1' },
    { id: 2, role: 'assistant', content: 'Medic 1 is added.' },
];

function shadow(): ShadowRoot {
    return document.querySelector('unified-assistant')!.shadowRoot!;
}

function card(): HTMLElement | null {
    return shadow().querySelector('.rating-card');
}

function button(selector: string): HTMLButtonElement {
    return shadow().querySelector<HTMLButtonElement>(selector)!;
}

async function flush(): Promise<void> {
    for (let i = 0; i < 12; i++) {
        await Promise.resolve();
    }
}

async function newConversation(): Promise<void> {
    button('.new-conversation').click();
    await flush();
}

function fill(input: HTMLInputElement, value: string): void {
    input.value = value;
    input.dispatchEvent(new Event('input'));
}

beforeAll(async () => {
    vi.stubGlobal('fetch', async (url: string, init: RequestInit) => {
        const key = url.includes('bootstrap')
            ? 'bootstrap'
            : url.endsWith('/rating')
              ? 'rating'
              : url.endsWith('/callback')
                ? 'callback'
                : url.includes('/updates')
                  ? 'updates'
                  : url.endsWith('/conversation')
                    ? 'clear'
                    : 'messages';

        if (key in bodies && init.body) {
            bodies[key].push(JSON.parse(String(init.body)));
        } else if (key === 'clear') {
            bodies.clear.push({});
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
        rating: '/assistant/rating',
        callback: '/assistant/callback',
        csrf: 'token',
    });
    document.body.append(script);
    replies.bootstrap.push({ status: 200, body: thread(7, [...answered, { id: 3, role: 'staff', author: 'Val', content: 'All set.' }], 'resolved') });

    await import('../src/index');
    await flush();
    window.UnifiedAssistant!.open();
});

afterEach(() => {
    vi.useRealTimers();
    Object.values(bodies).forEach((list) => (list.length = 0));
});

describe('widget: rating at the end of a conversation', () => {
    it('asks for a rating on a conversation our team resolved, with five labeled faces', () => {
        const shown = card()!;
        expect(shown).not.toBeNull();
        expect(shown.textContent).toContain('Thanks for talking with us. If you can, rate this conversation and leave a note.');
        expect(shown.querySelector('.question')!.textContent).not.toContain('from 1 to 5');

        const faces = Array.from(shown.querySelectorAll<HTMLButtonElement>('.face'));
        expect(faces.map((face) => face.getAttribute('aria-label'))).toEqual(['1 of 5, very poor', '2 of 5, poor', '3 of 5, okay', '4 of 5, good', '5 of 5, great']);
        expect(faces.map((face) => face.querySelector('.emoji')!.textContent)).toEqual(['\u{1F61E}', '\u{1F615}', '\u{1F610}', '\u{1F642}', '\u{1F604}']);
        expect(faces.map((face) => face.querySelector('.face-number')!.textContent)).toEqual(['1', '2', '3', '4', '5']);
        expect(faces.every((face) => face.lastElementChild === face.querySelector('.face-number'))).toBe(true);
        expect(shown.querySelector('[role=radiogroup]')!.getAttribute('aria-label')).toBe('Rate this conversation from 1 to 5');
        expect(shown.querySelector<HTMLInputElement>('.rating-note')!.placeholder).toBe('Anything you want to tell us? (optional)');
        expect(button('.rating-send').disabled).toBe(true);
        expect(shown.textContent).not.toMatch(/—|\bAI\b|\bbot\b|ticket/);
    });

    it('moves between faces with the arrow keys and sends a 5 with its note', async () => {
        const faces = Array.from(card()!.querySelectorAll<HTMLButtonElement>('.face'));
        faces[0].click();
        faces[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
        expect(faces[1].getAttribute('aria-checked')).toBe('true');
        expect(faces[0].getAttribute('aria-checked')).toBe('false');

        faces[4].click();
        fill(card()!.querySelector<HTMLInputElement>('.rating-note')!, 'Quick and right');
        replies.rating.push({ status: 200, body: { ok: true, rating: { ...(rating(5) as object), note: 'Quick and right' } } });
        button('.rating-send').click();
        await flush();

        expect(bodies.rating).toEqual([{ conversation_id: 7, score: 5, note: 'Quick and right' }]);
        expect(card()!.textContent).toContain('Thanks, glad it helped.');
        expect(card()!.textContent).toContain('You rated this 5 of 5.');
        expect(card()!.querySelector('.call-yes')).toBeNull();
    });

    it('does not ask again once rated: New conversation goes straight on', async () => {
        replies.clear.push({ status: 200, body: thread(8, []) });
        await newConversation();

        expect(bodies.clear).toHaveLength(1);
        expect(card()).toBeNull();
    });

    it('asks after two quiet minutes following a reply, and 3 or less offers a call', async () => {
        vi.useFakeTimers();
        replies.messages.push({ status: 200, body: { reply: 'Medic 1 is added.', message_id: 12, support: { enabled: true, status: 'assistant', last_message_id: 12 } } });
        const input = shadow().querySelector('textarea')!;
        input.value = 'Add Medic 1';
        input.dispatchEvent(new Event('input'));
        button('.send').click();
        await flush();
        expect(card()).toBeNull();

        vi.advanceTimersByTime(119_000);
        expect(card()).toBeNull();
        vi.advanceTimersByTime(2_000);
        expect(card()).not.toBeNull();
        vi.useRealTimers();

        card()!.querySelectorAll<HTMLButtonElement>('.face')[1].click();
        fill(card()!.querySelector<HTMLInputElement>('.rating-note')!, 'Wrong unit');
        replies.rating.push({ status: 200, body: { ok: true, rating: { ...(rating(2) as object), note: 'Wrong unit' } } });
        button('.rating-send').click();
        await flush();

        expect(bodies.rating).toEqual([{ conversation_id: 8, score: 2, note: 'Wrong unit' }]);
        expect(card()!.textContent).toContain('Sorry about that. Want someone from our team to call you?');
        expect(button('.call-yes').textContent).toBe('Yes, call me');
        expect(button('.call-no').textContent).toBe('No thanks');
    });

    it('checks the number, then requests the call and says when our team will call', async () => {
        button('.call-yes').click();
        await flush();

        const phone = card()!.querySelector<HTMLInputElement>('.call-phone')!;
        expect(phone.value).toBe('(814) 555-0100');
        expect(card()!.textContent).toContain('Phone number');
        expect(card()!.textContent).toContain('Best time');
        expect(Array.from(card()!.querySelectorAll('.best-time')).map((b) => b.textContent)).toEqual(['Now', 'This morning', 'This afternoon', 'Tomorrow morning']);

        fill(phone, '555-0100');
        button('.call-submit').click();
        await flush();
        expect(bodies.callback).toHaveLength(0);
        expect(card()!.querySelector('.row-error')!.textContent).toBe('Enter a US phone number, like (814) 555-0100.');
        expect(card()!.querySelector('.call-phone')!.getAttribute('aria-invalid')).toBe('true');

        fill(card()!.querySelector<HTMLInputElement>('.call-phone')!, '814.555.0100');
        card()!.querySelectorAll<HTMLButtonElement>('.best-time')[2].click();
        replies.callback.push({
            status: 200,
            body: {
                ...(thread(8, [...answered, { id: 13, role: 'system', content: 'Handed to our team' }, { id: 14, role: 'system', content: 'Call requested: (814) 555-0100, this afternoon' }], 'needs_teammate', 2) as object),
                callback: { phone: '+18145550100', phone_display: '(814) 555-0100', best_time: 'this_afternoon', best_time_label: 'This afternoon', message: 'Our team will call you at (814) 555-0100 this afternoon. Replies also show up here.' },
            },
        });
        button('.call-submit').click();
        await flush();

        expect(bodies.callback).toEqual([{ conversation_id: 8, phone: '(814) 555-0100', best_time: 'this_afternoon', note: 'Wrong unit' }]);
        expect(card()).toBeNull();
        const text = shadow().querySelector('.list')!.textContent!;
        expect(text).toContain('Call requested: (814) 555-0100, this afternoon');
        expect(text).toContain('Our team will call you at (814) 555-0100 this afternoon. Replies also show up here.');
    });

    it('never asks while our team has the conversation', () => {
        window.UnifiedAssistant!.close();
        window.UnifiedAssistant!.open();

        expect(card()).toBeNull();
    });

    it('asks before a new conversation, and Skip goes on to it', async () => {
        replies.clear.push({ status: 200, body: thread(9, answered) });
        await newConversation();
        expect(bodies.clear).toHaveLength(1);
        expect(card()).toBeNull();

        await newConversation();
        expect(bodies.clear).toHaveLength(1);
        expect(card()).not.toBeNull();

        replies.clear.push({ status: 200, body: thread(10, []) });
        button('.rating-skip').click();
        await flush();
        await new Promise((resolve) => setTimeout(resolve, 0));
        await flush();

        expect(bodies.rating).toHaveLength(0);
        expect(bodies.clear).toHaveLength(2);
    });

    it('asks when the drawer is closed on an answered conversation, and the card waits there', async () => {
        replies.clear.push({ status: 200, body: thread(11, answered) });
        await newConversation();
        expect(card()).toBeNull();

        window.UnifiedAssistant!.close();
        window.UnifiedAssistant!.open();
        expect(card()).not.toBeNull();

        replies.rating.push({ status: 200, body: { ok: true, rating: rating(3) } });
        card()!.querySelectorAll<HTMLButtonElement>('.face')[2].click();
        button('.rating-send').click();
        await flush();
        button('.call-no').click();
        await flush();

        expect(bodies.rating).toEqual([{ conversation_id: 11, score: 3 }]);
        expect(card()!.textContent).toBe('You rated this 3 of 5.');
    });
});

describe('US phone numbers', () => {
    it('accepts the common ways of writing one and refuses the rest', () => {
        expect(usPhoneDigits('(814) 555-0100')).toBe('8145550100');
        expect(usPhoneDigits('+1 814 555 0100')).toBe('8145550100');
        expect(usPhoneDigits('1-814-555-0100')).toBe('8145550100');
        expect(usPhoneDigits('555-0100')).toBeNull();
        expect(usPhoneDigits('(114) 555-0100')).toBeNull();
        expect(usPhoneDigits('+44 20 7946 0958')).toBeNull();
        expect(usPhoneDigits('call me')).toBeNull();
        expect(formatUsPhone('8145550100')).toBe('(814) 555-0100');
    });
});
