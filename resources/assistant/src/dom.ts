type Child = Node | string | null | undefined | false;

export function h<K extends keyof HTMLElementTagNameMap>(
    tag: K,
    attrs: Record<string, string | boolean | ((event: Event) => void) | null | undefined> = {},
    ...children: Child[]
): HTMLElementTagNameMap[K] {
    const el = document.createElement(tag);

    for (const [key, value] of Object.entries(attrs)) {
        if (value === null || value === undefined || value === false) {
            continue;
        }

        if (typeof value === 'function') {
            el.addEventListener(key.replace(/^on/, '').toLowerCase(), value);
        } else if (value === true) {
            el.setAttribute(key, '');
        } else if (key === 'class') {
            el.className = value;
        } else {
            el.setAttribute(key, value);
        }
    }

    for (const child of children) {
        if (child === null || child === undefined || child === false) {
            continue;
        }

        el.append(typeof child === 'string' ? document.createTextNode(child) : child);
    }

    return el;
}

export function svg(path: string, size = 16): SVGSVGElement {
    const ns = 'http://www.w3.org/2000/svg';
    const el = document.createElementNS(ns, 'svg');
    el.setAttribute('viewBox', '0 0 24 24');
    el.setAttribute('width', String(size));
    el.setAttribute('height', String(size));
    el.setAttribute('fill', 'none');
    el.setAttribute('stroke', 'currentColor');
    el.setAttribute('stroke-width', '1.8');
    el.setAttribute('stroke-linecap', 'round');
    el.setAttribute('stroke-linejoin', 'round');
    el.setAttribute('aria-hidden', 'true');

    const p = document.createElementNS(ns, 'path');
    p.setAttribute('d', path);
    el.append(p);

    return el;
}

export const icons = {
    sparkles:
        'M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3zM5 18l.7 1.8L7.5 20.5l-1.8.7L5 23l-.7-1.8-1.8-.7 1.8-.7L5 18zM19 2l.6 1.4L21 4l-1.4.6L19 6l-.6-1.4L17 4l1.4-.6L19 2z',
    close: 'M6 6l12 12M18 6L6 18',
    send: 'M5 12h14M13 6l6 6-6 6',
    paperclip: 'M21 12.5l-8.5 8.5a5.5 5.5 0 01-7.8-7.8l9.2-9.2a3.5 3.5 0 015 5l-9.2 9.2a1.5 1.5 0 01-2.1-2.1l8.5-8.5',
    copy: 'M9 9h10v12H9zM5 15V3h10',
    check: 'M5 12l5 5L20 7',
};

/** Plain text with line breaks kept; no markup is ever interpreted. */
export function paragraphs(text: string): DocumentFragment {
    const fragment = document.createDocumentFragment();
    const blocks = text.replace(/\r\n/g, '\n').split(/\n{2,}/);

    blocks.forEach((block) => {
        const p = document.createElement('p');
        const lines = block.split('\n');

        lines.forEach((line, index) => {
            if (index > 0) {
                p.append(document.createElement('br'));
            }

            p.append(document.createTextNode(line));
        });

        fragment.append(p);
    });

    return fragment;
}

export function timeLabel(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const sameDay = date.toDateString() === new Date().toDateString();
    const time = date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });

    return sameDay ? time : `${date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })} ${time}`;
}
