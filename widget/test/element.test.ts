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

describe('<gaith-chat>', () => {
  it('shows the configured welcome text and starter options before the first message', () => {
    routeFetch({});
    const el = mount() as any;
    el.options = { welcomeMessage: { en: 'Hello!' }, quickOptions: { en: ['Show approvals'] } };

    expect(bubbles(el)).toEqual(['Hello!']);
    expect(shadow(el).querySelector('.option')!.textContent).toBe('Show approvals');
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
    expect((shadow(el).querySelector('.input') as HTMLTextAreaElement).disabled).toBe(true);

    el.remove();
    document.body.appendChild(el);

    expect(shadow(el).querySelector('.typing')).toBeNull();
    expect((shadow(el).querySelector('.input') as HTMLTextAreaElement).disabled).toBe(false);
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

    it('sends the upload to the host with its headers, as a form', async () => {
      const fetchMock = routeFetch({ '/config': configRoute, '/attachments': uploaded('art-1', 'report.pdf', 'application/pdf') });
      const seen: any[] = [];
      const el = mount() as any;
      el.getHeaders = (req: any) => { seen.push(req); return { Authorization: 'Bearer abc' }; };
      await flush(30);

      el.attachFiles([pdf()]);
      await flush(30);

      const call = fetchMock.mock.calls.find((c) => String(c[0]).endsWith('/attachments'))!;
      expect(String(call[0])).toBe('api/gaith/attachments');
      expect((call[1] as RequestInit).body).toBeInstanceOf(FormData);
      expect(((call[1] as RequestInit).headers as Record<string, string>).Authorization).toBe('Bearer abc');
      expect(seen.find((r) => r.path === 'api/gaith/attachments').form).toBeInstanceOf(FormData);
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

    it('lets the user remove an attached file before sending', async () => {
      routeFetch({ '/config': configRoute, '/attachments': uploaded('art-1', 'report.pdf', 'application/pdf') });
      const el = mount() as any;
      await flush(30);
      el.attachFiles([pdf()]);
      await flush(30);

      (shadow(el).querySelector('.chip-remove') as HTMLButtonElement).click();

      expect(chips(el)).toEqual([]);
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
    expect((shadow(el).querySelector('.input') as HTMLTextAreaElement).placeholder).toBe('اكتب رسالتك');
  });

  it('reset starts a new conversation', async () => {
    routeFetch({ '/stream': () => sseResponse(HAPPY_PATH) });
    const el = mount() as any;
    el.send('hello');
    await flush(50);

    el.reset();

    expect(bubbles(el)).toEqual([]);
    expect(sessionStorage.getItem('gaith-chat:conversation:api/gaith')).toBeNull();
  });
});
