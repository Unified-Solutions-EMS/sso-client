import { beforeAll, describe, expect, it, vi } from 'vitest';

/** An older component (no `visitor` setting): SSO's `reach: visitor` alone switches the drawer. */

function shadow(): ShadowRoot {
    return document.querySelector('unified-assistant')!.shadowRoot!;
}

async function flush(): Promise<void> {
    for (let i = 0; i < 10; i++) {
        await Promise.resolve();
    }
}

beforeAll(async () => {
    vi.stubGlobal('fetch', async () =>
        new Response(
            JSON.stringify({
                enabled: true,
                viewer: 'visitor-hash-2',
                conversation: { id: 41, title: null, messages: [] },
                conversations: [],
                suggestions: [],
                support: { enabled: true, status: 'assistant', last_message_id: 0 },
                reach: 'visitor',
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
    await flush();
    window.UnifiedAssistant!.open();
    await flush();
});

describe('widget: reach visitor from SSO', () => {
    it('uses the visitor wording', () => {
        expect(shadow().querySelector('.header .subtitle')!.textContent).toBe('Ask about our products, pricing, or a test drive.');
        expect(shadow().querySelector('textarea')!.placeholder).toBe('Ask a question');
    });
});
