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
    alert: 'M12 8v5M12 16v.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    clock: 'M12 7v5l3 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    plus: 'M12 5v14M5 12h14',
    external: 'M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h5',
};

/** How text is turned into nodes: always text nodes and elements, never innerHTML. */
export interface TextOptions {
    /** Turn web addresses and [label](https://...) into links that open in a new tab. */
    links?: boolean;
}

const URL_TAIL = /[.,;:!?'"\u2019\u201D]+$/;

/** Only http(s) addresses become links; anything else stays text. */
function safeUrl(raw: string): URL | null {
    try {
        const url = new URL(raw);

        return url.protocol === 'https:' || url.protocol === 'http:' ? url : null;
    } catch {
        return null;
    }
}

/**
 * A web address as a person reads it: the host and path, no scheme, no query,
 * a long path cut short ("unifiedreporting.unified-apps.com/dashboard").
 */
export function shortUrl(url: URL): string {
    const path = url.pathname.replace(/\/+$/, '');
    const shown = path.length > 32 ? `${path.slice(0, 31)}\u2026` : path;

    return `${url.hostname.replace(/^www\./, '')}${shown}`;
}

export function link(url: URL, label: string, className?: string): HTMLAnchorElement {
    const a = document.createElement('a');
    a.href = url.href;
    a.target = '_blank';
    a.rel = 'noopener';
    a.textContent = label;

    if (className) {
        a.className = className;
    }

    return a;
}

/**
 * A bare address keeps the sentence's closing punctuation (and an unmatched
 * closing parenthesis) outside the link.
 */
function bareUrl(raw: string): { url: URL; rest: string } | null {
    let candidate = raw;
    let rest = '';
    const tail = candidate.match(URL_TAIL);

    if (tail) {
        rest = tail[0];
        candidate = candidate.slice(0, -rest.length);
    }

    while (candidate.endsWith(')') && (candidate.match(/\(/g) ?? []).length < (candidate.match(/\)/g) ?? []).length) {
        candidate = candidate.slice(0, -1);
        rest = `)${rest}`;
    }

    const url = safeUrl(candidate);

    return url ? { url, rest } : null;
}

/** Links inside one run of text: [label](https://...) and bare https:// addresses. */
function linked(text: string): Node[] {
    const nodes: Node[] = [];
    const pattern = /\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)|https?:\/\/[^\s<>]+/gi;
    let last = 0;
    let match: RegExpExecArray | null;

    while ((match = pattern.exec(text)) !== null) {
        const before = text.slice(last, match.index);
        let node: Node | null = null;
        let rest = '';

        if (match[1] !== undefined) {
            const url = safeUrl(match[2]);
            node = url ? link(url, match[1].trim() || shortUrl(url)) : null;
        } else {
            const found = bareUrl(match[0]);

            if (found) {
                node = link(found.url, shortUrl(found.url));
                rest = found.rest;
            }
        }

        if (!node) {
            continue;
        }

        if (before) {
            nodes.push(document.createTextNode(before));
        }

        nodes.push(node);

        if (rest) {
            nodes.push(document.createTextNode(rest));
        }

        last = match.index + match[0].length;
    }

    if (last < text.length) {
        nodes.push(document.createTextNode(text.slice(last)));
    }

    return nodes;
}

/**
 * The model is asked for plain text, but **bold** still slips through; render
 * it rather than show the asterisks. Text nodes only, never innerHTML.
 */
function inline(line: string, options: TextOptions): Node[] {
    const nodes: Node[] = [];
    const bold = /\*\*([^*\n]+?)\*\*/g;
    const text = (part: string): Node[] => (options.links ? linked(part) : [document.createTextNode(part)]);
    let last = 0;
    let match: RegExpExecArray | null;

    while ((match = bold.exec(line)) !== null) {
        if (match.index > last) {
            nodes.push(...text(line.slice(last, match.index)));
        }

        const strong = document.createElement('strong');
        strong.append(...text(match[1]));
        nodes.push(strong);
        last = match.index + match[0].length;
    }

    if (last < line.length) {
        nodes.push(...text(line.slice(last)));
    }

    return nodes;
}

export function paragraphs(text: string, options: TextOptions = {}): DocumentFragment {
    const fragment = document.createDocumentFragment();
    const blocks = text.replace(/\r\n/g, '\n').split(/\n{2,}/);

    blocks.forEach((block) => {
        const p = document.createElement('p');
        const lines = block.split('\n');

        lines.forEach((line, index) => {
            if (index > 0) {
                p.append(document.createElement('br'));
            }

            p.append(...inline(line, options));
        });

        fragment.append(p);
    });

    return fragment;
}

/** "Today", "Yesterday", "Oct 28", or "Oct 28, 2025" when it is another year. */
export function dayLabel(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const now = new Date();
    const startOf = (d: Date): number => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
    const days = Math.round((startOf(now) - startOf(date)) / 86400000);

    if (days === 0) {
        return 'Today';
    }

    if (days === 1) {
        return 'Yesterday';
    }

    const options: Intl.DateTimeFormatOptions = { month: 'short', day: 'numeric' };

    if (date.getFullYear() !== now.getFullYear()) {
        options.year = 'numeric';
    }

    return date.toLocaleDateString(undefined, options);
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

/** A file's size the way a person reads it: 512 B, 18 KB, 2.4 MB. */
export function fileSize(bytes: number | null | undefined): string {
    if (typeof bytes !== 'number' || !Number.isFinite(bytes) || bytes < 0) {
        return '';
    }

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
