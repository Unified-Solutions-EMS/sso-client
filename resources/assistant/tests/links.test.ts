import { describe, expect, it } from 'vitest';
import { paragraphs, shortUrl } from '../src/dom';

function render(text: string, links = true): HTMLElement {
    const div = document.createElement('div');
    div.append(paragraphs(text, { links }));

    return div;
}

describe('links in the Assistant text', () => {
    it('shows a bare address as host and path, never the raw address', () => {
        const div = render('Open in Analytics: https://unifiedreporting.unified-apps.com/dashboard?analyst=95');
        const a = div.querySelector('a')!;

        expect(a.textContent).toBe('unifiedreporting.unified-apps.com/dashboard');
        expect(a.getAttribute('href')).toBe('https://unifiedreporting.unified-apps.com/dashboard?analyst=95');
        expect(a.target).toBe('_blank');
        expect(a.rel).toBe('noopener');
        expect(div.textContent).toBe('Open in Analytics: unifiedreporting.unified-apps.com/dashboard');
    });

    it('keeps the sentence punctuation and an unmatched parenthesis outside the link', () => {
        const div = render('See it here (https://example.com/reports/7). Then come back.');
        const a = div.querySelector('a')!;

        expect(a.getAttribute('href')).toBe('https://example.com/reports/7');
        expect(div.textContent).toBe('See it here (example.com/reports/7). Then come back.');
    });

    it('renders [label](url) as a link with the label', () => {
        const div = render('It is ready. [Open in CheckSheets](https://checksheets.example.com/templates/12/builder) to adjust it.');
        const a = div.querySelector('a')!;

        expect(a.textContent).toBe('Open in CheckSheets');
        expect(a.getAttribute('href')).toBe('https://checksheets.example.com/templates/12/builder');
        expect(div.textContent).toBe('It is ready. Open in CheckSheets to adjust it.');
    });

    it('never links anything but http and https', () => {
        const div = render('[Click](javascript:alert(1)) and ftp://files.example.com/x');

        expect(div.querySelector('a')).toBeNull();
        expect(div.textContent).toBe('[Click](javascript:alert(1)) and ftp://files.example.com/x');
    });

    it('links inside bold and keeps line breaks', () => {
        const div = render('**Report: https://example.com/r**\nNext line');

        expect(div.querySelector('strong a')!.textContent).toBe('example.com/r');
        expect(div.querySelector('br')).not.toBeNull();
    });

    it('leaves addresses as text when links are off (the person\'s own messages)', () => {
        const div = render('https://example.com/x', false);

        expect(div.querySelector('a')).toBeNull();
        expect(div.textContent).toBe('https://example.com/x');
    });

    it('cuts a long path short', () => {
        expect(shortUrl(new URL('https://www.example.com/a/very/long/path/that/goes/on/and/on/forever?x=1'))).toBe('example.com/a/very/long/path/that/goes/on/\u2026');
        expect(shortUrl(new URL('https://example.com/'))).toBe('example.com');
    });
});
