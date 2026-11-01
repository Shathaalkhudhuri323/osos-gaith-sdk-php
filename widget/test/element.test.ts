import { GaithChatElement } from '../src/index';
import { flush, HAPPY_PATH, json, routeFetch, sseResponse } from './helpers';

function mount(attrs: Record<string, string> = {}): HTMLElement {
  const el = document.createElement('gaith-chat');
  el.setAttribute('endpoint', 'api/gaith');
  Object.entries(attrs).forEach(([k, v]) => el.setAttribute(k, v));
  document.body.appendChild(el);
  return el;
}

const shadow = (el: HTMLElement) => el.shadowRoot!;
const container = (el: HTMLElement) => shadow(el).querySelector('.root') as HTMLElement;
const bubbles = (el: HTMLElement) =>
  Array.from(shadow(el).querySelectorAll('.bubble')).map((b) => (b.textContent || '').trim());
const urls = (mock: ReturnType<typeof routeFetch>) => mock.mock.calls.map((c) => String(c[0]));

afterEach(() => {
  document.body.innerHTML = '';
  sessionStorage.clear();
  vi.restoreAllMocks();
});

describe('<gaith-chat> connecting an account', () => {
  const CONNECT_REPLY = [
    'event: meta\ndata: {"conversation_id":"c1"}\n\n',
    'event: token\ndata: {"content":"I need access to QHSE first."}\n\n',
    'event: connect\ndata: {"server":"QHSE","url":"https://qhse-mcp.example.com/authorize?state=abc"}\n\n',
    'event: done\ndata: {"message_id":"m1","conversation_id":"c1","finish_reason":"stop"}\n\n',
  ];

  it('offers a Connect button that opens the link, and Try again resends the question', async () => {
    const opened = vi.spyOn(window, 'open').mockReturnValue(null);
    const fetchMock = routeFetch({ '/stream': () => sseResponse(CONNECT_REPLY) });
    const el = mount() as any;
    el.send('show my incidents');
    await flush(50);

    const buttons = Array.from(shadow(el).querySelectorAll('.connect button')) as HTMLButtonElement[];
    expect(buttons.map((b) => b.textContent)).toEqual(['Connect to QHSE', 'Try again']);

    buttons[0].click();
    expect(opened).toHaveBeenCalledWith('https://qhse-mcp.example.com/authorize?state=abc', '_blank', 'noopener,noreferrer');

    buttons[1].click();
    await flush(50);
    const sent = fetchMock.mock.calls.filter((c) => String(c[0]).endsWith('/stream'));
    expect(sent).toHaveLength(2);
    expect(JSON.parse(String((sent[1][1] as RequestInit).body)).message).toBe('show my incidents');
  });
});

describe('<gaith-chat> switched on or off per customer', () => {
  const isShown = (el: HTMLElement) => !container(el).classList.contains('off');

  it('shows itself when the chatbot is on for this customer', async () => {
    routeFetch({ '/status': () => json({ enabled: true }) });
    const el = mount();
    await flush(30);

    expect(isShown(el)).toBe(true);
  });

  it.each([
    ['the chatbot is off for this customer', () => json({ enabled: false })],
    ['the check fails', () => json({ code: 'gaith_error' }, 502)],
    ['the answer is not a clear yes', () => json({ enabled: 'yes' })],
  ])('stays hidden when %s, even after the slow-backend timer', async (_case, answer) => {
    routeFetch({ '/status': answer });
    const el = mount();
    await flush(1600);

    expect(isShown(el)).toBe(false);
  });
});

describe('<gaith-chat> tag settings (no host JavaScript)', () => {
  afterEach(() => localStorage.clear());

  afterEach(() => document.documentElement.removeAttribute('lang'));

  // QHSE switches language without reloading: it saves the new language and changes <html lang>.
  const switchPageTo = (lang: string) => {
    localStorage.setItem('lang', lang);
    document.documentElement.setAttribute('lang', lang);
  };

  it('switches language as soon as the page does', async () => {
    localStorage.setItem('lang', 'en');
    routeFetch({ '/config': () => json({}) });
    const el = mount({ 'lang-storage-key': 'lang' });
    expect(container(el).getAttribute('dir')).toBe('ltr');

    switchPageTo('ar');
    await flush(10);

    expect(container(el).getAttribute('dir')).toBe('rtl');
  });

  it('lets a reply that is being written finish first, then switches', async () => {
    localStorage.setItem('lang', 'en');
    const encoder = new TextEncoder();
    let finish: () => void = () => {};
    const slowReply = () => new Response(new ReadableStream<Uint8Array>({
      start(controller) {
        controller.enqueue(encoder.encode('event: token\ndata: {"content":"Hello"}\n\n'));
        finish = () => {
          controller.enqueue(encoder.encode('event: done\ndata: {"message_id":"m1","conversation_id":"c1","finish_reason":"stop"}\n\n'));
          controller.close();
        };
      },
    }), { status: 200, headers: { 'Content-Type': 'text/event-stream' } });
    routeFetch({ '/config': () => json({}), '/stream': slowReply });
    const el = mount({ 'lang-storage-key': 'lang' }) as any;
    el.send('hi');
    await flush(20);

    switchPageTo('ar');
    await flush(10);
    expect(container(el).getAttribute('dir')).toBe('ltr'); // still writing the English reply

    finish();
    await flush(20);
    expect(container(el).getAttribute('dir')).toBe('rtl');
  });

  const authOf = (mock: ReturnType<typeof routeFetch>, suffix: string) => {
    const call = mock.mock.calls.filter((c) => String(c[0]).endsWith(suffix)).pop()!;
    return ((call[1] as RequestInit).headers as Record<string, string>).Authorization;
  };

  it('sends the token the page keeps under token-storage-key as a bearer token', async () => {
    localStorage.setItem('access_token', 'tok-1');
    const fetchMock = routeFetch({ '/config': () => json({}), '/stream': () => sseResponse(HAPPY_PATH) });
    const el = mount({ 'token-storage-key': 'access_token' }) as any;
    await flush(20);
    el.send('hello');
    await flush(50);

    expect(authOf(fetchMock, '/config')).toBe('Bearer tok-1');
    expect(authOf(fetchMock, '/stream')).toBe('Bearer tok-1');
  });

  it('reads the token again for every request, so a refreshed token is used at once', async () => {
    localStorage.setItem('access_token', 'old');
    const fetchMock = routeFetch({ '/config': () => json({}), '/stream': () => sseResponse(HAPPY_PATH) });
    const el = mount({ 'token-storage-key': 'access_token' }) as any;
    await flush(20);
    localStorage.setItem('access_token', 'new');
    el.send('hello');
    await flush(50);

    expect(authOf(fetchMock, '/stream')).toBe('Bearer new');
  });

  it('lets a host getHeaders win over the tag setting', async () => {
    localStorage.setItem('access_token', 'from-storage');
    const fetchMock = routeFetch({ '/config': () => json({}), '/stream': () => sseResponse(HAPPY_PATH) });
    const el = mount({ 'token-storage-key': 'access_token' }) as any;
    el.getHeaders = () => ({ Authorization: 'Bearer from-host' });
    el.send('hello');
    await flush(50);

    expect(authOf(fetchMock, '/stream')).toBe('Bearer from-host');
  });

  it('takes the language from lang-storage-key when there is no lang attribute', () => {
    localStorage.setItem('lang', 'ar');
    routeFetch({ '/config': () => json({}) });
    const el = mount({ 'lang-storage-key': 'lang' });

    expect(container(el).getAttribute('dir')).toBe('rtl');
  });
});

describe('<gaith-chat>', () => {
  it('starts with the built-in greeting alongside the panel controls', () => {
    routeFetch({});
    const el = mount();

    expect(bubbles(el)).toEqual(['How can I help you today?']);
    expect(shadow(el).querySelector('.message.welcome')).not.toBeNull();
    expect(shadow(el).querySelectorAll('.header .action')).toHaveLength(4);
  });

  it('shows the configured welcome text and starter options before the first message', () => {
    routeFetch({});
    const el = mount() as any;
    el.options = { welcomeMessage: { en: 'Hello!' }, quickOptions: { en: ['Show approvals'] } };

    expect(bubbles(el)).toEqual(['Hello!']);
    expect(shadow(el).querySelector('.option')!.textContent).toBe('Show approvals');
  });

  it('puts the chat history menu first in the header and tells the host when it is clicked', () => {
    routeFetch({});
    const el = mount();
    const clicks: string[] = [];
    el.addEventListener('gaith-chat:history', (e) => clicks.push(e.type));

    const menu = shadow(el).querySelector('.header .action') as HTMLButtonElement;
    expect(menu.classList.contains('menu')).toBe(true);
    expect(menu.getAttribute('aria-label')).toBe('Chat history');
    menu.click();

    expect(clicks).toEqual(['gaith-chat:history']);
  });

  it('streams a reply into a bot bubble and remembers the conversation', async () => {
    routeFetch({ '/stream': () => sseResponse(HAPPY_PATH) });
    const el = mount() as any;

    el.send('hello');
    await flush(50);

    expect(bubbles(el)).toEqual(['hello', 'Hello']);
    expect(sessionStorage.getItem('gaith-chat:conversation:api/gaith')).toBe('c1');
    expect(shadow(el).querySelector('.typing')).toBeNull();
  });

  it('unlocks the composer when a streaming element is detached and reattached', async () => {
    const hangingStream = () => new Response(new ReadableStream<Uint8Array>({
      start() { /* remains open until the widget aborts it */ },
    }), { status: 200, headers: { 'Content-Type': 'text/event-stream' } });
    routeFetch({ '/stream': hangingStream });
    const el = mount() as any;

    el.send('hello');
    await flush(20);
    const input = shadow(el).querySelector('.input') as HTMLTextAreaElement;
    const send = shadow(el).querySelector('.send') as HTMLButtonElement;
    input.value = 'next question';
    input.dispatchEvent(new Event('input'));
    expect(send.disabled).toBe(true);

    el.remove();
    document.body.appendChild(el);

    expect(shadow(el).querySelector('.typing')).toBeNull();
    expect(send.disabled).toBe(false);
  });

  it('lets the user type the next message while a reply streams, but not send it yet', async () => {
    const hangingStream = () => new Response(new ReadableStream<Uint8Array>({
      start() { /* remains open */ },
    }), { status: 200, headers: { 'Content-Type': 'text/event-stream' } });
    const fetchMock = routeFetch({ '/stream': hangingStream });
    const el = mount() as any;
    el.send('hello');
    await flush(20);

    const input = shadow(el).querySelector('.input') as HTMLTextAreaElement;
    expect(input.disabled).toBe(false);
    input.value = 'and tomorrow?';
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));

    expect(input.value).toBe('and tomorrow?'); // kept, not sent
    expect(urls(fetchMock).filter((u) => u.endsWith('/stream'))).toHaveLength(1);
  });

  it('uses getHeaders even when the host set it before the element was defined', async () => {
    // Angular may assign the property before the widget script has run: the tag is
    // then still a plain HTMLElement and is upgraded later.
    const fetchMock = routeFetch({ '/stream': () => sseResponse(HAPPY_PATH) });
    const el = document.createElement('gaith-chat-late') as any;
    el.setAttribute('endpoint', 'api/gaith');
    el.getHeaders = () => ({ Authorization: 'Bearer abc' });
    document.body.appendChild(el);

    customElements.define('gaith-chat-late', class extends GaithChatElement {});
    expect(typeof el.send).toBe('function'); // upgraded

    el.send('hello');
    await flush(50);

    const streamCall = fetchMock.mock.calls.find((c) => String(c[0]).endsWith('/stream'))!;
    expect(((streamCall[1] as RequestInit).headers as Record<string, string>).Authorization).toBe('Bearer abc');
  });

  it('never renders user text as HTML', () => {
    routeFetch({ '/stream': () => sseResponse(HAPPY_PATH) });
    const el = mount() as any;

    el.send('<img src=x onerror=alert(1)>');

    expect(shadow(el).querySelector('.bubble img')).toBeNull();
    expect(bubbles(el)[0]).toBe('<img src=x onerror=alert(1)>');
  });

  it('shows the host error text when a turn fails before any reply arrives', async () => {
    routeFetch({ '/stream': () => new Response('', { status: 500 }) });
    const el = mount() as any;
    el.options = { errorMessage: { en: 'Try later' } };
    const errors: string[] = [];
    el.addEventListener('gaith-chat:error', (e: CustomEvent) => errors.push(e.detail.code));

    el.send('hello');
    await flush(50);

    expect(bubbles(el)).toEqual(['hello', 'Try later']);
    expect(errors).toEqual(['http_500']);
  });

  it('restores history from the backend after a reload', async () => {
    sessionStorage.setItem('gaith-chat:conversation:api/gaith', 'c1');
    const fetchMock = routeFetch({
      '/conversations/c1/messages': () => json({
        messages: [
          { role: 'user', content: 'earlier question', created_at: '2026-01-01T10:00:00Z' },
          { role: 'assistant', content: 'earlier answer', created_at: '2026-01-01T10:00:05Z' },
        ],
      }),
    });

    const el = mount();
    await flush(50);

    expect(urls(fetchMock)).toContain('api/gaith/conversations/c1/messages');
    expect(bubbles(el)).toEqual(['earlier question', 'earlier answer']);
  });

  it('forgets a conversation the backend no longer knows', async () => {
    sessionStorage.setItem('gaith-chat:conversation:api/gaith', 'gone');
    routeFetch({ '/conversations/gone/messages': () => json({}, 404) });

    mount();
    await flush(50);

    expect(sessionStorage.getItem('gaith-chat:conversation:api/gaith')).toBeNull();
  });

  it('applies the product colours from the backend config and then shows itself', async () => {
    routeFetch({ '/config': () => json({ theme: { primary_color: '#E33A33', text_color: '#ffffff' } }) });

    const el = mount();
    expect(container(el).classList.contains('pending')).toBe(true); // no flash of the default colour
    await flush(50);

    expect(container(el).style.getPropertyValue('--gaith-chat-theme-primary')).toBe('#E33A33');
    expect(container(el).style.getPropertyValue('--gaith-chat-theme-on-primary')).toBe('#ffffff');
    expect(container(el).classList.contains('pending')).toBe(false);
  });

  it('says GAITH data protection applies, linking to the product policy from the backend config', async () => {
    routeFetch({ '/config': () => json({ theme: {}, privacy_url: 'https://osos.om/privacy' }) });

    const el = mount();
    await flush(50);

    const tip = shadow(el).querySelector('.header .protect-tip')!;
    expect(tip.textContent).toBe('GAITH data protection applies to this chat.');
    const link = tip.querySelector('a') as HTMLAnchorElement;
    expect(link.textContent).toBe('GAITH data protection');
    expect(link.getAttribute('href')).toBe('https://osos.om/privacy');
    expect(link.target).toBe('_blank');
    expect(link.rel).toContain('noopener');
    expect(shadow(el).querySelector('.header .privacy')!.getAttribute('aria-label'))
      .toBe('GAITH data protection applies to this chat.');
  });

  it('shows the data protection note without a link when the policy URL is missing or not a web page', async () => {
    routeFetch({ '/config': () => json({ theme: {}, privacy_url: 'javascript:alert(1)' }) });

    const el = mount();
    await flush(50);

    const tip = shadow(el).querySelector('.header .protect-tip')!;
    expect(tip.querySelector('a')).toBeNull();
    expect(tip.textContent).toBe('GAITH data protection applies to this chat.');
  });

  it('still shows itself with the default colours when the config request fails', async () => {
    routeFetch({ '/config': () => new Response('', { status: 500 }) });

    const el = mount();
    await flush(50);

    expect(container(el).style.getPropertyValue('--gaith-chat-theme-primary')).toBe('');
    expect(container(el).classList.contains('pending')).toBe(false);
  });

  it('retries the initial load once the host provides auth headers', async () => {
    // A host that signs in asynchronously sets getHeaders after the first attempt.
    let authorized = false;
    routeFetch({
      '/config': () => (authorized ? json({ theme: { primary_color: '#E33A33' } }) : new Response('', { status: 401 })),
    });
    const el = mount() as any;
    await flush(50);
    expect(container(el).style.getPropertyValue('--gaith-chat-theme-primary')).toBe('');

    authorized = true;
    el.getHeaders = () => ({ Authorization: 'Bearer late' });
    await flush(50);

    expect(container(el).style.getPropertyValue('--gaith-chat-theme-primary')).toBe('#E33A33');
  });

  describe('files', () => {
    const PDF_EVENT = 'event: file\ndata: {"artifact_id":"pdf-1","filename":"onboarding-checklist.pdf","media_type":"application/pdf","size_bytes":36979}\n\n';
    const PNG_EVENT = 'event: file\ndata: {"artifact_id":"png-1","filename":"site.png","media_type":"image/png","size_bytes":2048}\n\n';
    const DONE = 'event: done\ndata: {"message_id":"m1","conversation_id":"c1"}\n\n';

    beforeEach(() => {
      // jsdom has no object URLs; the widget only needs a stable string per blob.
      let n = 0;
      (URL as any).createObjectURL = vi.fn(() => 'blob:test/' + (++n));
      (URL as any).revokeObjectURL = vi.fn();
    });

    it('shows a card for a PDF a tool produced (the missing link from the GAITH test)', async () => {
      routeFetch({ '/stream': () => sseResponse(['event: token\ndata: {"content":"Here it is."}\n\n', PDF_EVENT, DONE]) });
      const el = mount() as any;

      el.send('make a pdf');
      await flush(50);

      const card = shadow(el).querySelector('.file-card')!;
      expect(card.querySelector('.file-name')!.textContent).toBe('onboarding-checklist.pdf');
      expect(card.querySelector('.file-icon svg')).not.toBeNull(); // same standard icon for every type
      expect(card.querySelector('.file-meta')!.textContent).toBe('PDF · 36 KB');
      expect(card.getAttribute('aria-label')).toBe('Open: onboarding-checklist.pdf');
    });

    it('shows an image a tool produced inline, fetched with the host headers', async () => {
      const fetchMock = routeFetch({
        '/stream': () => sseResponse([PNG_EVENT, DONE]),
        '/files/png-1': () => new Response('png-bytes', { status: 200 }),
      });
      const el = mount() as any;
      el.getHeaders = () => ({ Authorization: 'Bearer abc' });

      el.send('show the site photo');
      await flush(80);

      const img = shadow(el).querySelector('.preview img') as HTMLImageElement;
      expect(img.getAttribute('src')).toMatch(/^blob:test\//);
      // A placeholder frame shows until the picture has loaded.
      const frame = shadow(el).querySelector('.preview')!;
      expect(frame.classList.contains('loading')).toBe(true);
      img.dispatchEvent(new Event('load'));
      expect(frame.classList.contains('loading')).toBe(false);
      const fileCall = fetchMock.mock.calls.find((c) => String(c[0]).endsWith('/files/png-1'))!;
      expect(((fileCall[1] as RequestInit).headers as Record<string, string>).Authorization).toBe('Bearer abc');
    });

    it('opens a PDF in a tab opened during the click, so popup blockers allow it', async () => {
      routeFetch({ '/stream': () => sseResponse([PDF_EVENT, DONE]), '/files/pdf-1': () => new Response('%PDF', { status: 200 }) });
      const tab = { location: { href: '' }, opener: {}, close: vi.fn() };
      const openSpy = vi.spyOn(window, 'open').mockReturnValue(tab as any);
      const el = mount() as any;
      el.send('make a pdf');
      await flush(50);

      (shadow(el).querySelector('.file-card') as HTMLButtonElement).click();
      expect(openSpy).toHaveBeenCalledTimes(1); // synchronously, inside the click
      await flush(30);

      expect(tab.location.href).toMatch(/^blob:test\//);
      expect(tab.opener).toBeNull();
    });

    it('downloads, never opens, a type that could run script as the host page', async () => {
      routeFetch({
        '/stream': () => sseResponse(['event: file\ndata: {"artifact_id":"svg-1","filename":"x.svg","media_type":"image/svg+xml","size_bytes":10}\n\n', DONE]),
        '/files/svg-1': () => new Response('<svg/>', { status: 200 }),
      });
      const openSpy = vi.spyOn(window, 'open');
      const clicked: string[] = [];
      vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
        clicked.push(this.download);
      });
      const el = mount() as any;
      el.send('svg please');
      await flush(50);

      expect(shadow(el).querySelector('.preview')).toBeNull(); // no inline preview for SVG
      (shadow(el).querySelector('.file-card') as HTMLButtonElement).click();
      await flush(30);

      expect(openSpy).not.toHaveBeenCalled();
      expect(clicked).toEqual(['x.svg']);
    });

    it('marks an expired file instead of failing silently', async () => {
      routeFetch({ '/stream': () => sseResponse([PDF_EVENT, DONE]), '/files/pdf-1': () => json({ code: 'file_expired' }, 410) });
      vi.spyOn(window, 'open').mockReturnValue({ location: { href: '' }, opener: {}, close: vi.fn() } as any);
      const el = mount() as any;
      el.send('make a pdf');
      await flush(50);

      (shadow(el).querySelector('.file-card') as HTMLButtonElement).click();
      await flush(50);

      const card = shadow(el).querySelector('.file-card') as HTMLButtonElement;
      expect(card.disabled).toBe(true);
      expect(card.querySelector('.file-meta')!.textContent).toBe('This file has expired');
    });

    it('restores files with the history after a reload', async () => {
      sessionStorage.setItem('gaith-chat:conversation:api/gaith', 'c1');
      routeFetch({
        '/conversations/c1/messages': () => json({ messages: [
          { role: 'user', content: 'make a pdf', created_at: '2026-01-01T10:00:00Z', files: [] },
          { role: 'assistant', content: 'Done.', created_at: '2026-01-01T10:00:05Z', files: [
            { artifact_id: 'pdf-1', filename: 'onboarding-checklist.pdf', media_type: 'application/pdf', size_bytes: 36979 },
          ] },
        ] }),
      });

      const el = mount();
      await flush(50);

      expect(shadow(el).querySelector('.file-name')!.textContent).toBe('onboarding-checklist.pdf');
    });

    it('does not rebuild an inline image while later text streams in (no flicker)', async () => {
      routeFetch({});
      const el = mount() as any;
      // Simulate a reply whose image arrives before more text.
      (el as any).messages.push({ author: 'bot', text: '![p](/demo/p.svg)', sentAt: null, files: [] });
      (el as any).status = 'streaming';
      (el as any).renderMessages();
      const first = shadow(el).querySelector('.bubble img');

      (el as any).messages[0].text += '\n\nmore text';
      (el as any).renderMessages();

      expect(shadow(el).querySelector('.bubble img')).toBe(first); // same node: no reload
      expect(bubbles(el)[0]).toContain('more text');
    });
  });

  describe('clarifying questions', () => {
    const QUESTION = [
      'event: meta\ndata: {"conversation_id":"c1"}\n\n',
      'event: token\ndata: {"content":"Which month?"}\n\n',
      'event: question\ndata: {"question":"Which month?","options":["October","November"]}\n\n',
      'event: done\ndata: {"message_id":"m1","conversation_id":"c1","finish_reason":"question"}\n\n',
    ];
    const answers = (el: HTMLElement) =>
      Array.from(shadow(el).querySelectorAll('.answers .option')).map((b) => b.textContent);

    it('shows the options under the question and sends the clicked one as the next message', async () => {
      let turn = 0;
      const fetchMock = routeFetch({ '/stream': () => sseResponse(++turn === 1 ? QUESTION : HAPPY_PATH) });
      const el = mount() as any;

      el.send('show my leave');
      await flush(50);
      expect(bubbles(el)).toEqual(['show my leave', 'Which month?']);
      expect(answers(el)).toEqual(['October', 'November']);

      (shadow(el).querySelector('.answers .option') as HTMLButtonElement).click();
      await flush(50);

      const sent = fetchMock.mock.calls.filter((c) => String(c[0]).endsWith('/stream'))
        .map((c) => JSON.parse((c[1] as RequestInit).body as string).message);
      expect(sent).toEqual(['show my leave', 'October']);
      expect(answers(el)).toEqual([]); // settled once answered
    });

    it('drops the options when the user types an answer instead', async () => {
      let turn = 0;
      routeFetch({ '/stream': () => sseResponse(++turn === 1 ? QUESTION : HAPPY_PATH) });
      const el = mount() as any;
      el.send('show my leave');
      await flush(50);

      el.send('December actually');
      await flush(50);

      expect(answers(el)).toEqual([]);
    });

    it('brings the options of an unanswered question back after a reload', async () => {
      sessionStorage.setItem('gaith-chat:conversation:api/gaith', 'c1');
      routeFetch({
        '/conversations/c1/messages': () => json({ messages: [
          { role: 'user', content: 'show my leave', created_at: '2026-01-01T10:00:00Z', files: [], options: [] },
          { role: 'assistant', content: 'Which month?', created_at: '2026-01-01T10:00:05Z', files: [], options: ['October', 'November'] },
        ] }),
      });

      const el = mount();
      await flush(50);

      expect(answers(el)).toEqual(['October', 'November']);
    });
  });

  describe('attachments', () => {
    const RULES = {
      enabled: true,
      max_files: 8,
      max_bytes: 1024,
      allowed_types: ['application/pdf', 'image/png', 'image/jpeg'],
    };
    const configRoute = () => json({ theme: {}, attachments: RULES });
    const uploaded = (id: string, name: string, type: string) =>
      () => json({ artifact_id: id, filename: name, media_type: type, size_bytes: 10 }, 201);
    const chips = (el: HTMLElement) => Array.from(shadow(el).querySelectorAll('.chip')).map((c) =>
      c.querySelector('.chip-name')!.textContent + ' | ' + c.querySelector('.chip-meta')!.textContent);
    const sendButton = (el: HTMLElement) => shadow(el).querySelector('.send') as HTMLButtonElement;
    const pdf = (name = 'report.pdf', size = 10) => new File(['x'.repeat(size)], name, { type: 'application/pdf' });

    beforeEach(() => {
      let n = 0;
      (URL as any).createObjectURL = vi.fn(() => 'blob:test/' + (++n));
      (URL as any).revokeObjectURL = vi.fn();
    });

    it('shows the attach button only when the backend accepts attachments', async () => {
      routeFetch({});
      const plain = mount();
      await flush(30);
      expect((shadow(plain).querySelector('.attach') as HTMLElement).hidden).toBe(true);

      document.body.innerHTML = '';
      routeFetch({ '/config': configRoute });
      const el = mount();
      await flush(30);
      expect((shadow(el).querySelector('.attach') as HTMLElement).hidden).toBe(false);
    });

    it('judges a file by its extension, so a CSV is a CSV even when Windows calls it Excel', async () => {
      const csvRules = { ...RULES, allowed_types: ['text/csv', 'text/markdown'] };
      routeFetch({
        '/config': () => json({ theme: {}, attachments: csvRules }),
        '/attachments': uploaded('art-1', 'leave.csv', 'text/csv'),
      });
      const el = mount() as any;
      await flush(30);

      el.attachFiles([
        new File(['a,b'], 'leave.csv', { type: 'application/vnd.ms-excel' }),
        new File(['# hi'], 'notes.md', { type: '' }),
        new File(['x'], 'budget.xlsx', { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }),
      ]);
      await flush(30);

      expect(chips(el)).toEqual(['leave.csv | 1 KB', 'notes.md | 1 KB', 'budget.xlsx | Type not supported']);
    });

    it('uploads a picked file and sends its id with the message', async () => {
      const fetchMock = routeFetch({
        '/config': configRoute,
        '/attachments': uploaded('art-1', 'report.pdf', 'application/pdf'),
        '/stream': () => sseResponse(HAPPY_PATH),
      });
      const el = mount() as any;
      await flush(30);

      el.attachFiles([pdf()]);
      expect(chips(el)).toEqual(['report.pdf | Uploading...']);
      await flush(30);
      expect(chips(el)).toEqual(['report.pdf | 1 KB']);

      el.send('summarise this');
      await flush(50);

      const streamCall = fetchMock.mock.calls.find((c) => String(c[0]).endsWith('/stream'))!;
      expect(JSON.parse((streamCall[1] as RequestInit).body as string)).toEqual({
        message: 'summarise this', attachments: ['art-1'],
      });
      expect(chips(el)).toEqual([]); // cleared after sending
      // The user's message shows the file it carried.
      const userRow = shadow(el).querySelector('.message:not(.bot)')!;
      expect(userRow.querySelector('.file-name')!.textContent).toBe('report.pdf');
    });

    it('refuses an unsupported type or an oversized file without uploading it', async () => {
      const fetchMock = routeFetch({ '/config': configRoute });
      const el = mount() as any;
      await flush(30);

      el.attachFiles([
        new File(['x'], 'notes.txt', { type: 'text/plain' }),
        pdf('huge.pdf', 5000),
      ]);
      await flush(20);

      expect(chips(el)).toEqual(['notes.txt | Type not supported', 'huge.pdf | Too large']);
      expect(fetchMock.mock.calls.some((c) => String(c[0]).endsWith('/attachments'))).toBe(false);
      expect(sendButton(el).disabled).toBe(true); // nothing sendable
    });

    it('waits for uploads to finish before a message can be sent', async () => {
      let finish!: (r: Response) => void;
      vi.spyOn(globalThis, 'fetch').mockImplementation(async (input: any) => {
        const url = String(input);
        if (url.endsWith('/config')) { return configRoute(); }
        if (url.endsWith('/attachments')) { return new Promise<Response>((resolve) => { finish = resolve; }); }
        return sseResponse(HAPPY_PATH);
      });
      const el = mount() as any;
      await flush(30);
      (shadow(el).querySelector('.input') as HTMLTextAreaElement).value = 'here you go';

      el.attachFiles([pdf()]);
      el.renderComposer();
      await flush(10); // the upload request is now in flight
      expect(sendButton(el).disabled).toBe(true);
      el.send();
      expect(shadow(el).querySelectorAll('.message:not(.bot)').length).toBe(0); // send() refused

      finish(uploaded('art-1', 'report.pdf', 'application/pdf')());
      await flush(30);
      expect(sendButton(el).disabled).toBe(false);
    });

    it('sends files alone with a short default request, since GAITH needs some text', async () => {
      const fetchMock = routeFetch({
        '/config': configRoute,
        '/attachments': uploaded('art-1', 'site.png', 'image/png'),
        '/stream': () => sseResponse(HAPPY_PATH),
      });
      const el = mount() as any;
      await flush(30);

      el.attachFiles([new File(['png'], 'site.png', { type: 'image/png' })]);
      await flush(30);
      el.send();
      await flush(50);

      const streamCall = fetchMock.mock.calls.find((c) => String(c[0]).endsWith('/stream'))!;
      expect(JSON.parse((streamCall[1] as RequestInit).body as string).message)
        .toBe('Please take a look at the attached file(s).');
      // The image shows from the local copy - never downloaded back.
      const preview = shadow(el).querySelector('.message:not(.bot) .preview img') as HTMLImageElement;
      expect(preview.getAttribute('src')).toMatch(/^blob:test\//);
      expect(fetchMock.mock.calls.some((c) => String(c[0]).indexOf('/files/') !== -1)).toBe(false);
    });

    it('shows why the host refused an upload', async () => {
      routeFetch({ '/config': configRoute, '/attachments': () => json({ code: 'file_rejected' }, 422) });
      const el = mount() as any;
      await flush(30);

      el.attachFiles([pdf()]);
      await flush(30);

      // A file GAITH could not use (corrupt, locked, wrong content) is not a "type" problem.
      expect(chips(el)).toEqual(["report.pdf | Couldn't read this file"]);
    });
  });

  it('switches to RTL and Arabic labels for lang="ar"', () => {
    routeFetch({});
    const el = mount({ lang: 'ar' });

    expect(container(el).getAttribute('dir')).toBe('rtl');
    expect((shadow(el).querySelector('.input') as HTMLTextAreaElement).placeholder).toBe('مثال: «اعرض الموافقات المعلقة»');
    expect(shadow(el).querySelector('.powered')!.textContent).toBe('مدعوم من GAITH');
  });

  it('reset starts a new conversation', async () => {
    routeFetch({ '/stream': () => sseResponse(HAPPY_PATH) });
    const el = mount() as any;
    el.send('hello');
    await flush(50);

    el.reset();

    expect(bubbles(el)).toEqual(['How can I help you today?']);
    expect(sessionStorage.getItem('gaith-chat:conversation:api/gaith')).toBeNull();
  });
});
