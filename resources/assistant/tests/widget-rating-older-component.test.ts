import { beforeAll, describe, expect, it, vi } from 'vitest';

/** An app on an older sso-client sends no `rating` route: the card never shows. */
beforeAll(async () => {
    vi.stubGlobal('fetch', async () =>
        new Response(
            JSON.stringify({
                enabled: true,
                viewer: 'v1',
                conversation: { id: 7, title: null, messages: [{ id: 1, role: 'user', content: 'Hi' }, { id: 2, role: 'assistant', content: 'Hello.' }] },
                conversations: [],
                suggestions: [],
                support: { enabled: true, status: 'resolved', last_message_id: 2 },
                rating: { enabled: true, score: null, note: null, rated_at: null, phone: null },
            }),
            { status: 200, headers: { 'Content-Type': 'application/json' } },
        ),
    );

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

    await import('../src/index');
    for (let i = 0; i < 10; i++) {
        await Promise.resolve();
    }
    window.UnifiedAssistant!.open();
});

describe('widget: rating with an older component', () => {
    it('never shows the card without the rating route', () => {
        window.UnifiedAssistant!.close();
        window.UnifiedAssistant!.open();

        expect(document.querySelector('unified-assistant')!.shadowRoot!.querySelector('.rating-card')).toBeNull();
    });
});
