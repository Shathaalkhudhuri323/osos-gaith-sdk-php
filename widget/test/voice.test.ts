import '../src/index';
import { flush, json, routeFetch } from './helpers';

/** Stands in for the browser's SpeechRecognition; tests drive its events. */
class FakeRecognition {
  static instances: FakeRecognition[] = [];
  lang = '';
  continuous = false;
  interimResults = false;
  started = false;
  aborted = false;
  onresult: ((e: any) => void) | null = null;
  onerror: ((e: any) => void) | null = null;
  onend: (() => void) | null = null;

  constructor() { FakeRecognition.instances.push(this); }
  start(): void { this.started = true; }
  stop(): void { this.onend && this.onend(); }
  abort(): void { this.aborted = true; this.onend && this.onend(); }

  /** Everything recognised so far, one entry per phrase (the browser re-sends the full list). */
  hear(phrases: string[]): void {
    const results = phrases.map((text) => [{ transcript: text }]);
    this.onresult && this.onresult({ results });
  }
}

const shadow = (el: HTMLElement) => el.shadowRoot!;
const mic = (el: HTMLElement) => shadow(el).querySelector('.mic') as HTMLButtonElement;
const input = (el: HTMLElement) => shadow(el).querySelector('.input') as HTMLTextAreaElement;
const last = () => FakeRecognition.instances[FakeRecognition.instances.length - 1];

async function mount(config: Record<string, unknown> = { theme: {}, voice: true }, lang = 'en'): Promise<HTMLElement> {
  routeFetch({ '/config': () => json(config) });
  const el = document.createElement('gaith-chat');
  el.setAttribute('endpoint', 'api/gaith');
  el.setAttribute('lang', lang);
  document.body.appendChild(el);
  await flush(50);
  return el;
}

beforeEach(() => {
  FakeRecognition.instances = [];
  (window as any).SpeechRecognition = FakeRecognition;
});

afterEach(() => {
  delete (window as any).SpeechRecognition;
  document.body.innerHTML = '';
  sessionStorage.clear();
  vi.restoreAllMocks();
});

describe('voice input', () => {
  it('shows the mic when the browser can recognise speech and the product allows it', async () => {
    const el = await mount();

    expect(mic(el).hidden).toBe(false);
    expect(mic(el).getAttribute('aria-label')).toBe('Voice input');
  });

  it('hides the mic when the product turns voice off', async () => {
    const el = await mount({ theme: {}, voice: false });

    expect(mic(el).hidden).toBe(true);
  });

  it('hides the mic in browsers without speech recognition (Firefox)', async () => {
    delete (window as any).SpeechRecognition;
    const el = await mount();

    expect(mic(el).hidden).toBe(true);
  });

  it('streams the words into the input as they are spoken, after what was typed', async () => {
    const el = await mount();
    input(el).value = 'Hello';

    mic(el).click();
    const rec = last();
    expect(rec.started).toBe(true);
    expect(rec.continuous && rec.interimResults).toBe(true);
    expect(rec.lang).toBe('en-US');
    expect(mic(el).classList.contains('listening')).toBe(true);
    expect(input(el).placeholder).toBe('Listening...');

    rec.hear(['show my']);
    expect(input(el).value).toBe('Hello show my');
    rec.hear(['show my pending', ' approvals']);
    expect(input(el).value).toBe('Hello show my pending approvals');

    // Nothing is sent by itself: the user reviews and sends.
    expect((shadow(el).querySelector('.send') as HTMLButtonElement).disabled).toBe(false);
    expect(shadow(el).querySelectorAll('.message:not(.bot)')).toHaveLength(0);
  });

  it('drops words that arrive after the message was sent', async () => {
    routeFetch({ '/config': () => json({ theme: {}, voice: true }), '/stream': () => new Promise(() => {}) as any });
    const el = document.createElement('gaith-chat');
    el.setAttribute('endpoint', 'api/gaith');
    document.body.appendChild(el);
    await flush(50);
    mic(el).click();
    const rec = last();
    rec.hear(['hello']);

    (el as any).send();
    rec.hear(['hello there']);

    expect(rec.aborted).toBe(true);
    expect(input(el).value).toBe('');
  });

  it('tells the user and the host when the microphone is blocked', async () => {
    const el = await mount();
    const errors: string[] = [];
    el.addEventListener('gaith-chat:error', (e) => errors.push((e as CustomEvent).detail.code));
    mic(el).click();

    last().onerror!({ error: 'not-allowed' });
    last().onend!();

    expect(errors).toEqual(['voice_not-allowed']);
    expect(input(el).placeholder).toBe('Microphone access is blocked');
    expect(mic(el).classList.contains('listening')).toBe(false);
  });
});
