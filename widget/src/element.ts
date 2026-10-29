import {
  AttachmentRules, ChatFile, fetchConfig, fetchFile, fetchHistory, HeaderProvider, NO_ATTACHMENTS, streamTurn,
  toChatFile, Turn, uploadFile,
} from './client';
import { Lang, normalizeLang, pick, strings } from './i18n';
import { ImageGate, imageGate, renderMarkdown } from './render';
import { STYLES } from './styles';

/** Host-supplied texts; each is per language, falling back to English. */
export interface GaithChatOptions {
  welcomeMessage?: Partial<Record<Lang, string>>;
  errorMessage?: Partial<Record<Lang, string>>;
  quickOptions?: Partial<Record<Lang, string[]>>;
}

interface Message {
  author: 'user' | 'bot';
  text: string;
  /** When it was sent, or when the reply finished; null while still streaming. */
  sentAt: Date | null;
  notice?: string;
  /** Files on this message: the user's uploads, or files a tool produced. */
  files: ChatFile[];
}

type Status = 'idle' | 'streaming' | 'error';

/** A file the user attached to the message they are writing. */
interface PendingFile {
  key: number;
  file: File;
  status: 'uploading' | 'ready' | 'error';
  /** Error code from validation or the host, shown as a short reason. */
  error?: string;
  /** Set once uploaded: the GAITH file to send with the message. */
  uploaded?: ChatFile;
  /** Local preview of an image, reused for the sent message so it never re-downloads. */
  previewUrl?: string;
}

/** Types by extension, for files whose type the browser does not report. */
const TYPE_BY_EXTENSION: Record<string, string> = {
  pdf: 'application/pdf',
  png: 'image/png',
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  gif: 'image/gif',
  webp: 'image/webp',
  docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
};

function typeOf(file: File): string {
  if (file.type) { return file.type.toLowerCase(); }
  return TYPE_BY_EXTENSION[(file.name.split('.').pop() || '').toLowerCase()] || '';
}

/** Shown as a picture in the chat; every other file is a card. */
const PREVIEW_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
/**
 * Opened in a new tab on click. A blob URL carries the host page's origin, so a
 * scriptable type (HTML, SVG) opened that way would run as the host app: those
 * are only ever downloaded.
 */
const OPEN_TYPES = PREVIEW_TYPES.concat(['application/pdf']);

const ICONS = {
  chat: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9.4 9.4 0 0 1-4-.9L3 20l1.3-4A8.2 8.2 0 0 1 3 11.5 8.6 8.6 0 0 1 12 3a8.6 8.6 0 0 1 9 8.5z"/><path d="M8 11.5h.01M12 11.5h.01M16 11.5h.01" stroke-width="2.6"/></svg>',
  bot: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l1.9 5.6L19.5 9.5l-5.6 1.9L12 17l-1.9-5.6L4.5 9.5l5.6-1.9z"/><path d="M19 14l.9 2.6 2.6.9-2.6.9L19 21l-.9-2.6-2.6-.9 2.6-.9z" opacity=".8"/></svg>',
  close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
  reset: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>',
  send: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M2 21l21-9L2 3v7l15 2-15 2z"/></svg>',
  attach: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.4 11.1l-8.5 8.5a5.5 5.5 0 0 1-7.8-7.8l8.5-8.5a3.7 3.7 0 0 1 5.2 5.2l-8.5 8.5a1.8 1.8 0 0 1-2.6-2.6l7.8-7.8"/></svg>',
  remove: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M7 7l10 10M17 7L7 17"/></svg>',
  open: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>',
  download: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11"/><path d="M7 10l5 5 5-5"/><path d="M5 20h14"/></svg>',
  file: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6"/><path d="M9 17h4"/></svg>',
  expand: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 4h5v5"/><path d="M9 20H4v-5"/><path d="M20 4l-6 6"/><path d="M4 20l6-6"/></svg>',
};

/** The file's type as a short word for the card's meta line, e.g. "PDF". */
function fileTypeLabel(file: ChatFile): string {
  const ext = (file.filename.split('.').pop() || '').toLowerCase();
  if ((file.media_type || '').indexOf('pdf') !== -1) { return 'PDF'; }
  return ext && ext.length <= 4 && ext !== file.filename.toLowerCase() ? ext.toUpperCase() : '';
}

function formatSize(bytes: number): string {
  if (!bytes) { return ''; }
  return bytes < 1024 * 1024 ? Math.max(1, Math.round(bytes / 1024)) + ' KB' : (bytes / 1048576).toFixed(1) + ' MB';
}

function pad(n: number): string {
  return n < 10 ? '0' + n : String(n);
}

/**
 * <gaith-chat endpoint="api/gaith" lang="en" bot-name="GAITH"></gaith-chat>
 *
 * Talks only to the host's own backend routes (osos/gaith-sdk-php Laravel bridge),
 * never to GAITH. The host signs requests by setting `getHeaders`.
 */
export class GaithChatElement extends HTMLElement {
  static get observedAttributes(): string[] {
    return ['lang', 'bot-name', 'launcher-icon'];
  }

  // Accessors, not plain fields: a field initializer would run on upgrade and wipe
  // a value the host assigned before this script defined the element.
  private _getHeaders: HeaderProvider | null = null;
  private _options: GaithChatOptions = {};
  private readonly root: ShadowRoot;
  private messages: Message[] = [];
  private status: Status = 'idle';
  private conversationId: string | null = null;
  private turn: Turn | null = null;
  private isOpen = false;
  private renderQueued = false;
  /** Which markdown image URLs may load; until the config arrives, only the host's own origin. */
  private gate: ImageGate = imageGate([]);
  /** Rendered rows by message, reused while a message is unchanged so images never reload. */
  private rows = new Map<Message, { el: HTMLElement; sig: string }>();
  private welcomeMessage: Message | null = null;
  /** Object URLs of fetched files by artifact id (revoked on reset), and per-file failures. */
  private fileUrls = new Map<string, Promise<string>>();
  private fileErrors = new Map<string, string>();
  /** Files attached to the message being written, and what the backend accepts. */
  private pending: PendingFile[] = [];
  private pendingKey = 0;
  private attachRules: AttachmentRules = NO_ATTACHMENTS;
  /** Initial config + history load; 'failed' means auth was missing and it retries once getHeaders is set. */
  private loadState: 'idle' | 'scheduled' | 'loading' | 'done' | 'failed' = 'idle';
  private els!: {
    container: HTMLElement; panel: HTMLElement; title: HTMLElement; subtitle: HTMLElement; dot: HTMLElement;
    list: HTMLElement; input: HTMLTextAreaElement; send: HTMLButtonElement; launcher: HTMLButtonElement;
    reset: HTMLButtonElement; close: HTMLButtonElement;
    attach: HTMLButtonElement; fileInput: HTMLInputElement; attachments: HTMLElement; dropHint: HTMLElement;
  };

  constructor() {
    super();
    this.root = this.attachShadow({ mode: 'open' });
  }

  /** Returns the auth headers the host's backend requires for one request. */
  get getHeaders(): HeaderProvider | null {
    return this._getHeaders;
  }

  set getHeaders(value: HeaderProvider | null) {
    this._getHeaders = typeof value === 'function' ? value : null;
    if (this.loadState === 'failed' && this._getHeaders) {
      void this.load(); // the first attempt went out before the host could authenticate it
    }
  }

  get options(): GaithChatOptions {
    return this._options;
  }

  set options(value: GaithChatOptions) {
    this._options = value || {};
    if (this.els) { this.renderMessages(); }
  }

  private get endpoint(): string {
    return this.getAttribute('endpoint') || 'api/gaith';
  }

  private get language(): Lang {
    return normalizeLang(this.getAttribute('lang') || document.documentElement.lang);
  }

  private get storageKey(): string {
    return 'gaith-chat:conversation:' + this.endpoint;
  }

  connectedCallback(): void {
    // Properties set before this script defined the element shadow the class
    // accessors; re-apply them so they take effect.
    this.upgradeProperty('getHeaders');
    this.upgradeProperty('options');

    if (!this.els) {
      this.build();
    }
    this.applyLanguage();
    this.renderMessages();

    if (this.loadState === 'idle') {
      this.loadState = 'scheduled';
      this.conversationId = this.readStoredConversation();
      // Deferred one tick: frameworks attach the element first and set getHeaders
      // right after (Angular: ngAfterViewInit), and both requests need those headers.
      setTimeout(() => void this.load(), 0);
      // Never stay invisible because the backend is slow or unreachable.
      setTimeout(() => this.els.container.classList.remove('pending'), 1500);
    }
  }

  /** Loads the product theme and, if a conversation is stored, its history. */
  private async load(): Promise<void> {
    this.loadState = 'loading';
    const results = await Promise.all([
      this.loadTheme(),
      this.conversationId && this.messages.length === 0 ? this.restoreHistory(this.conversationId) : Promise.resolve(true),
    ]);
    this.loadState = results.every(Boolean) ? 'done' : 'failed';
  }

  /** @returns false only when the request was refused for lack of auth (worth retrying). */
  private async loadTheme(): Promise<boolean> {
    try {
      const { theme, image_origins, attachments } = await fetchConfig(this.endpoint, this.getHeaders);
      this.applyTheme('--gaith-chat-theme-primary', theme.primary_color);
      this.applyTheme('--gaith-chat-theme-on-primary', theme.text_color);
      this.gate = imageGate(image_origins);
      this.attachRules = attachments;
      this.rows.clear(); // re-render bot replies under the new image rules
      this.renderMessages();
      return true;
    } catch (error) {
      return !this.isAuthError(error);
    } finally {
      this.els.container.classList.remove('pending');
    }
  }

  private applyTheme(property: string, value: unknown): void {
    // The server validates too; this guards against a host whose route returns something else.
    if (typeof value === 'string' && (typeof CSS === 'undefined' || !CSS.supports || CSS.supports('color', value))) {
      this.els.container.style.setProperty(property, value);
    }
  }

  private isAuthError(error: unknown): boolean {
    return error instanceof Error && (error.message === 'http_401' || error.message === 'http_403' || error.message === 'http_419');
  }

  disconnectedCallback(): void {
    this.abandonTurn();
    // A framework may detach and later reattach the same element. The canceled
    // request can no longer call onDone/onError, so it must not leave the composer
    // permanently disabled in the streaming state.
    if (this.status === 'streaming') {
      this.status = 'idle';
    }
  }

  /** Frees the fetched files' memory. */
  private releaseFiles(): void {
    this.fileUrls.forEach((url) => url.then((u) => URL.revokeObjectURL(u), () => undefined));
    this.fileUrls.clear();
    this.fileErrors.clear();
  }

  attributeChangedCallback(): void {
    if (!this.els) { return; }
    this.applyLanguage();
    this.renderMessages();
  }

  open(): void {
    this.isOpen = true;
    this.renderOpenState();
    this.els.input.focus();
  }

  close(): void {
    this.isOpen = false;
    this.renderOpenState();
  }

  /** Drops the conversation and starts again from the welcome message. */
  reset(): void {
    this.abandonTurn();
    this.status = 'idle';
    this.conversationId = null;
    this.messages = [];
    this.rows.clear();
    this.releaseFiles();
    this.clearPending();
    this.storeConversation(null);
    this.els.input.value = '';
    this.renderMessages();
  }

  /**
   * Sends text as if the user had typed it, with any uploaded attachments.
   * Ignored while a reply is streaming or a file is still uploading.
   */
  send(text?: string): void {
    const typed = (text !== undefined ? text : this.els.input.value).trim();
    const ready = this.pending.filter((p) => p.status === 'ready' && p.uploaded);
    if (this.status === 'streaming' || this.pending.some((p) => p.status === 'uploading')) { return; }
    if (!typed && !ready.length) { return; }

    // GAITH needs some text with a message; files alone get a short default request.
    const value = typed || strings(this.language).attachmentOnly;
    const files = ready.map((p) => {
      const file = p.uploaded as ChatFile;
      if (p.previewUrl) {
        // The sent message shows the local copy instead of downloading it back.
        this.fileUrls.set(file.artifact_id, Promise.resolve(p.previewUrl));
        p.previewUrl = undefined;
      }
      return file;
    });
    this.clearPending();

    this.messages.push({ author: 'user', text: value, sentAt: new Date(), files });
    this.els.input.value = '';
    this.ask(value, files.map((f) => f.artifact_id));
  }

  /** Validates and starts uploading files the user picked or dropped. */
  attachFiles(files: FileList | File[]): void {
    const rules = this.attachRules;
    if (!rules.enabled || this.status === 'streaming') { return; }
    for (const file of Array.from(files)) {
      const pending: PendingFile = { key: ++this.pendingKey, file, status: 'uploading' };
      const type = typeOf(file);
      if (this.pending.filter((p) => p.status !== 'error').length >= rules.max_files) {
        pending.status = 'error';
        pending.error = 'too_many_files';
      } else if (rules.allowed_types.length && rules.allowed_types.indexOf(type) === -1) {
        pending.status = 'error';
        pending.error = 'file_type_not_allowed';
      } else if (rules.max_bytes && file.size > rules.max_bytes) {
        pending.status = 'error';
        pending.error = 'file_too_large';
      }
      if (PREVIEW_TYPES.indexOf(type) !== -1 && typeof URL.createObjectURL === 'function') {
        pending.previewUrl = URL.createObjectURL(file);
      }
      this.pending.push(pending);
      if (pending.status === 'uploading') {
        uploadFile(this.endpoint, file, this.getHeaders).then((uploaded) => {
          pending.uploaded = uploaded;
          pending.status = 'ready';
          this.renderComposer();
        }, (error: unknown) => {
          pending.status = 'error';
          pending.error = error instanceof Error ? error.message : 'upload_failed';
          this.renderComposer();
        });
      }
    }
    this.renderComposer();
  }

  private removePending(key: number): void {
    const index = this.pending.findIndex((p) => p.key === key);
    if (index === -1) { return; }
    const [removed] = this.pending.splice(index, 1);
    if (removed.previewUrl) { URL.revokeObjectURL(removed.previewUrl); }
    this.renderComposer();
  }

  private clearPending(): void {
    this.pending.forEach((p) => { if (p.previewUrl) { URL.revokeObjectURL(p.previewUrl); } });
    this.pending = [];
  }

  private ask(text: string, attachments: string[] = []): void {
    const reply: Message = { author: 'bot', text: '', sentAt: null, files: [] };
    this.messages.push(reply);
    this.status = 'streaming';
    this.renderMessages();

    this.turn = streamTurn(this.endpoint, text, this.conversationId, this.getHeaders, {
      onConversation: (id) => {
        this.conversationId = id;
        this.storeConversation(id);
      },
      onToken: (content) => {
        reply.text += content;
        this.queueRender();
      },
      onFile: (file) => {
        reply.files.push(file);
        this.queueRender();
      },
      onNotice: (kind) => {
        reply.notice = kind === 'limit' ? strings(this.language).limit : strings(this.language).working;
        this.queueRender();
      },
      onDone: (id) => {
        if (id) {
          this.conversationId = id;
          this.storeConversation(id);
        }
        if (reply.notice === strings(this.language).working) { reply.notice = undefined; }
        this.finishTurn(reply, 'idle');
      },
      onError: (code) => {
        if (!reply.text) {
          // Nothing arrived, so say so rather than leaving an empty bubble.
          reply.text = code === 'safety_block'
            ? strings(this.language).blocked
            : pick(this._options.errorMessage, this.language) || strings(this.language).error;
        }
        if (reply.notice === strings(this.language).working) { reply.notice = undefined; }
        this.finishTurn(reply, 'error');
        this.dispatchEvent(new CustomEvent('gaith-chat:error', { detail: { code }, bubbles: true, composed: true }));
      },
    }, attachments);
  }

  private finishTurn(reply: Message, status: Status): void {
    this.turn = null;
    this.status = status;
    reply.sentAt = new Date();
    this.renderMessages();
  }

  private abandonTurn(): void {
    if (this.turn) {
      this.turn.cancel();
      this.turn = null;
    }
  }

  /** @returns false only when the request was refused for lack of auth (worth retrying). */
  private async restoreHistory(conversationId: string): Promise<boolean> {
    try {
      const history = await fetchHistory(this.endpoint, conversationId, this.getHeaders);
      if (history === null) {
        // Expired, deleted, or belongs to someone else (e.g. a different login in this tab).
        this.conversationId = null;
        this.storeConversation(null);
        return true;
      }
      // Only apply if the user has not started typing a new turn meanwhile.
      if (this.messages.length === 0 && this.conversationId === conversationId) {
        this.messages = history.map((m): Message => ({
          author: m.role === 'user' ? 'user' : 'bot',
          text: m.content,
          sentAt: m.created_at ? new Date(m.created_at) : null,
          files: (Array.isArray(m.files) ? m.files : []).map(toChatFile).filter((f): f is ChatFile => f !== null),
        }));
        this.renderMessages();
      }
      return true;
    } catch (error) {
      if (this.isAuthError(error)) {
        return false;
      }
      // History is a convenience; the conversation itself still continues in GAITH.
      this.dispatchEvent(new CustomEvent('gaith-chat:error', {
        detail: { code: 'history_failed' }, bubbles: true, composed: true,
      }));
      return true;
    }
  }

  private build(): void {
    this.root.innerHTML = `
      <style>${STYLES}</style>
      <div class="root pending" part="root">
        <div class="panel" part="panel" role="dialog" hidden>
          <div class="header" part="header">
            <span class="avatar">${ICONS.bot}<span class="status"><span class="dot"></span></span></span>
            <span class="heading">
              <span class="title"></span>
              <span class="subtitle" role="status" aria-live="polite"></span>
            </span>
            <span class="actions">
              <button type="button" class="action reset">${ICONS.reset}</button>
              <button type="button" class="action close">${ICONS.close}</button>
            </span>
          </div>
          <div class="messages" part="messages" aria-live="polite"></div>
          <div class="composer">
            <div class="attachments" hidden></div>
            <div class="composer-box">
              <button type="button" class="attach" hidden>${ICONS.attach}</button>
              <input type="file" class="file-input" multiple hidden>
              <textarea class="input" rows="1"></textarea>
              <button type="button" class="send">${ICONS.send}</button>
            </div>
          </div>
          <div class="drop-hint" hidden></div>
        </div>
        <button type="button" class="launcher" part="launcher"></button>
      </div>`;

    const q = <T extends Element>(selector: string) => this.root.querySelector(selector) as unknown as T;
    this.els = {
      container: q('.root'), panel: q('.panel'), title: q('.title'), subtitle: q('.subtitle'), dot: q('.dot'), list: q('.messages'),
      input: q('.input'), send: q('.send'), launcher: q('.launcher'), reset: q('.reset'), close: q('.close'),
      attach: q('.attach'), fileInput: q('.file-input'), attachments: q('.attachments'), dropHint: q('.drop-hint'),
    };

    this.els.attach.addEventListener('click', () => this.els.fileInput.click());
    this.els.fileInput.addEventListener('change', () => {
      if (this.els.fileInput.files) { this.attachFiles(this.els.fileInput.files); }
      this.els.fileInput.value = ''; // picking the same file again still fires change
    });
    // Drag and drop anywhere on the open panel.
    let dragDepth = 0;
    const dragsFiles = (e: DragEvent) =>
      this.attachRules.enabled && !!e.dataTransfer && Array.from(e.dataTransfer.types || []).indexOf('Files') !== -1;
    this.els.panel.addEventListener('dragenter', (e) => {
      if (!dragsFiles(e)) { return; }
      e.preventDefault();
      dragDepth++;
      this.els.dropHint.hidden = false;
    });
    this.els.panel.addEventListener('dragover', (e) => { if (dragsFiles(e)) { e.preventDefault(); } });
    this.els.panel.addEventListener('dragleave', () => {
      dragDepth = Math.max(0, dragDepth - 1);
      if (!dragDepth) { this.els.dropHint.hidden = true; }
    });
    this.els.panel.addEventListener('drop', (e) => {
      dragDepth = 0;
      this.els.dropHint.hidden = true;
      if (!dragsFiles(e)) { return; }
      e.preventDefault();
      this.attachFiles(e.dataTransfer!.files);
    });

    this.els.launcher.addEventListener('click', () => (this.isOpen ? this.close() : this.open()));
    this.els.close.addEventListener('click', () => this.close());
    this.els.reset.addEventListener('click', () => this.reset());
    this.els.send.addEventListener('click', () => this.send());
    this.els.input.addEventListener('input', () => {
      this.fitInput();
      this.renderComposer();
    });
    this.els.input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        this.send();
      }
    });
    this.els.panel.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') { this.close(); }
    });
  }

  private applyLanguage(): void {
    const t = strings(this.language);
    this.els.container.setAttribute('dir', this.language === 'ar' ? 'rtl' : 'ltr');
    this.els.title.textContent = this.getAttribute('bot-name') || t.chat;
    this.els.panel.setAttribute('aria-label', this.els.title.textContent);
    this.els.input.placeholder = t.placeholder;
    this.labelButton(this.els.send, t.send);
    this.labelButton(this.els.reset, t.reset);
    this.labelButton(this.els.close, t.close);
    this.labelButton(this.els.attach, t.attach);
    this.els.dropHint.textContent = t.dropFiles;
    this.renderOpenState();
  }

  private labelButton(button: HTMLElement, label: string): void {
    button.setAttribute('aria-label', label);
    button.setAttribute('title', label);
  }

  private renderOpenState(): void {
    const t = strings(this.language);
    const icon = this.getAttribute('launcher-icon');
    this.els.panel.hidden = !this.isOpen;
    this.els.launcher.classList.toggle('open', this.isOpen);
    this.els.launcher.classList.toggle('has-image', !!icon);
    this.labelButton(this.els.launcher, this.isOpen ? t.close : (this.getAttribute('bot-name') || t.open));
    if (this.isOpen) {
      this.els.launcher.innerHTML = ICONS.close;
    } else if (icon) {
      const img = document.createElement('img');
      img.src = icon;
      img.alt = '';
      this.els.launcher.replaceChildren(img);
    } else {
      this.els.launcher.innerHTML = ICONS.chat;
    }
  }

  /** Token updates arrive many times a second; render at most once per frame. */
  private queueRender(): void {
    if (this.renderQueued) { return; }
    this.renderQueued = true;
    const raf = typeof requestAnimationFrame === 'function' ? requestAnimationFrame : (fn: () => void) => setTimeout(fn, 16);
    raf(() => {
      this.renderQueued = false;
      this.renderMessages();
    });
  }

  private renderMessages(): void {
    const t = strings(this.language);
    const list = this.els.list;
    const nearBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 40;
    const children: HTMLElement[] = [];

    const visible: Message[] = this.messages.slice();
    const welcome = pick(this._options.welcomeMessage, this.language);
    if (visible.length === 0 && welcome) {
      if (!this.welcomeMessage || this.welcomeMessage.text !== welcome) {
        this.welcomeMessage = { author: 'bot', text: welcome, sentAt: null, files: [] };
      }
      visible.push(this.welcomeMessage);
    }

    const seen = new Set<Message>();
    for (const message of visible) {
      // An empty streaming bubble is represented by the typing dots instead.
      if (message.author === 'bot' && !message.text && !message.files.length && this.status === 'streaming' && !message.notice) {
        continue;
      }
      seen.add(message);
      children.push(this.rowFor(message));
    }
    this.rows.forEach((_, message) => { if (!seen.has(message)) { this.rows.delete(message); } });

    if (this.status === 'streaming') {
      const typing = document.createElement('div');
      typing.className = 'typing';
      typing.innerHTML = '<span></span><span></span><span></span>';
      children.push(typing);
    }

    const quickOptions = pick(this._options.quickOptions, this.language) || [];
    if (quickOptions.length > 0 && !this.messages.some((m) => m.author === 'user')) {
      const options = document.createElement('div');
      options.className = 'options';
      for (const option of quickOptions) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'option';
        button.textContent = option;
        button.disabled = this.status === 'streaming';
        button.addEventListener('click', () => this.send(option));
        options.appendChild(button);
      }
      children.push(options);
    }

    // Moving existing nodes keeps loaded images; only changed rows were rebuilt.
    list.replaceChildren(...children);

    this.els.dot.className = 'dot' + (this.status === 'streaming' ? ' busy' : this.status === 'error' ? ' error' : '');
    const statusLabel = this.status === 'streaming' ? t.thinking : this.status === 'error' ? t.unavailable : t.online;
    this.els.subtitle.textContent = statusLabel;
    this.els.dot.parentElement!.setAttribute('title', statusLabel);
    this.fitInput();
    this.renderComposer();

    if (nearBottom || this.status === 'streaming') {
      list.scrollTop = list.scrollHeight;
    }
  }

  /** The message's row, rebuilt only when something visible about it changed. */
  private rowFor(message: Message): HTMLElement {
    const sig = [
      message.text, message.notice || '', message.sentAt ? message.sentAt.getTime() : '',
      message.files.map((f) => f.artifact_id + ':' + (this.fileErrors.get(f.artifact_id) || '')).join(','),
      this.language,
    ].join('\u0000');
    const cached = this.rows.get(message);
    if (cached && cached.sig === sig) {
      return cached.el;
    }
    const el = this.buildRow(message, cached ? cached.el : null);
    this.rows.set(message, { el, sig });
    return el;
  }

  private buildRow(message: Message, previous: HTMLElement | null): HTMLElement {
    const row = document.createElement('div');
    row.className = 'message' + (message.author === 'bot' ? ' bot' : '');

    if (message.text) {
      const bubble = document.createElement('div');
      bubble.className = 'bubble';
      if (message.author === 'user') {
        bubble.textContent = message.text; // user text is never interpreted as HTML
      } else {
        bubble.innerHTML = renderMarkdown(message.text, this.gate);
        this.keepLoadedImages(bubble, previous);
      }
      row.appendChild(bubble);
    }

    if (message.files.length) {
      const files = document.createElement('div');
      files.className = 'files';
      message.files.forEach((file) => files.appendChild(this.fileElement(file, previous)));
      row.appendChild(files);
    }

    if (message.notice) {
      const notice = document.createElement('div');
      notice.className = 'notice';
      notice.textContent = message.notice;
      row.appendChild(notice);
    }
    if (message.sentAt) {
      const time = document.createElement('div');
      time.className = 'time';
      time.textContent = pad(message.sentAt.getHours()) + ':' + pad(message.sentAt.getMinutes());
      row.appendChild(time);
    }
    return row;
  }

  /**
   * A streaming reply is rebuilt as tokens arrive; reuse its already-loaded
   * images so a picture does not reload (flicker) with every word after it.
   */
  private keepLoadedImages(bubble: HTMLElement, previous: HTMLElement | null): void {
    if (!previous) { return; }
    const old = new Map<string, HTMLImageElement>();
    previous.querySelectorAll('.bubble img').forEach((img) => old.set(img.getAttribute('src') || '', img as HTMLImageElement));
    bubble.querySelectorAll('img').forEach((img) => {
      const match = old.get(img.getAttribute('src') || '');
      if (match) {
        old.delete(img.getAttribute('src') || '');
        img.replaceWith(match);
      }
    });
  }

  private fileElement(file: ChatFile, previous: HTMLElement | null): HTMLElement {
    const t = strings(this.language);
    const error = this.fileErrors.get(file.artifact_id);
    const type = file.media_type || '';

    if (PREVIEW_TYPES.indexOf(type) !== -1 && !error) {
      const kept = previous
        ? Array.from(previous.querySelectorAll<HTMLElement>('.preview')).find((p) => p.dataset.artifact === file.artifact_id)
        : undefined;
      if (kept) { return kept; }

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'preview';
      button.dataset.artifact = file.artifact_id;
      this.labelButton(button, t.openFile + ': ' + file.filename);
      const img = document.createElement('img');
      img.alt = file.filename;
      // A placeholder frame until the picture has arrived, instead of the browser's
      // broken-image icon while a large file is still downloading.
      button.classList.add('loading');
      img.addEventListener('load', () => button.classList.remove('loading'));
      const hint = document.createElement('span');
      hint.className = 'preview-hint';
      hint.innerHTML = ICONS.expand;
      button.append(img, hint);
      this.fileUrl(file).then((url) => { img.src = url; }, () => this.queueRender());
      button.addEventListener('click', () => this.openFile(file));
      return button;
    }

    const card = document.createElement('button');
    card.type = 'button';
    card.className = 'file-card' + (error ? ' failed' : '');
    card.disabled = !!error;
    const opens = OPEN_TYPES.indexOf(type) !== -1;
    const label = fileTypeLabel(file);
    this.labelButton(card, (opens ? t.openFile : t.download) + ': ' + file.filename);

    // One standard icon for every generated file; the type is named in the meta line.
    const icon = document.createElement('span');
    icon.className = 'file-icon';
    icon.innerHTML = ICONS.file;

    const info = document.createElement('span');
    info.className = 'file-info';
    const name = document.createElement('span');
    name.className = 'file-name';
    name.textContent = file.filename;
    const meta = document.createElement('span');
    meta.className = 'file-meta';
    meta.textContent = error
      ? (error === 'http_410' ? t.fileExpired : t.fileUnavailable)
      : [label, formatSize(file.size_bytes)].filter(Boolean).join(' · ');
    info.append(name, meta);

    const action = document.createElement('span');
    action.className = 'file-action';
    action.innerHTML = opens ? ICONS.open : ICONS.download;

    card.append(icon, info);
    if (!error) { card.appendChild(action); }
    card.addEventListener('click', () => this.openFile(file));
    return card;
  }

  /** The file's bytes as an object URL, fetched once through the host's file route. */
  private fileUrl(file: ChatFile): Promise<string> {
    let url = this.fileUrls.get(file.artifact_id);
    if (!url) {
      url = fetchFile(this.endpoint, file.artifact_id, this.getHeaders).then((blob) => URL.createObjectURL(blob));
      url.catch((error: unknown) => {
        this.fileUrls.delete(file.artifact_id);
        this.fileErrors.set(file.artifact_id, error instanceof Error ? error.message : 'error');
      });
      this.fileUrls.set(file.artifact_id, url);
    }
    return url;
  }

  /** Images and PDFs open in a new tab; every other type is saved, never opened. */
  private openFile(file: ChatFile): void {
    const opens = OPEN_TYPES.indexOf(file.media_type || '') !== -1;
    // Opened now, inside the click, or the browser's popup blocker stops it once
    // the (asynchronous) download finishes.
    const tab = opens ? window.open('', '_blank') : null;
    if (tab) { tab.opener = null; }

    this.fileUrl(file).then((url) => {
      if (tab) {
        tab.location.href = url;
        return;
      }
      const link = document.createElement('a');
      link.href = url;
      link.download = file.filename;
      link.rel = 'noopener';
      this.root.appendChild(link);
      link.click();
      link.remove();
    }, (error: unknown) => {
      if (tab) { tab.close(); }
      this.queueRender(); // the card now shows why
      this.dispatchEvent(new CustomEvent('gaith-chat:error', {
        detail: { code: error instanceof Error ? error.message : 'file_failed' }, bubbles: true, composed: true,
      }));
    });
  }

  /** Grows the input with its text, up to its max height, then it scrolls. */
  private fitInput(): void {
    const input = this.els.input;
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight || 36, 120) + 'px';
  }

  private renderComposer(): void {
    const streaming = this.status === 'streaming';
    const uploading = this.pending.some((p) => p.status === 'uploading');
    const ready = this.pending.some((p) => p.status === 'ready');
    this.els.input.disabled = streaming;
    // Waits for uploads to finish, so a message never goes out without its files.
    this.els.send.disabled = streaming || uploading || (!this.els.input.value.trim() && !ready);
    this.els.attach.hidden = !this.attachRules.enabled;
    this.els.attach.disabled = streaming;
    this.els.fileInput.accept = this.acceptList();
    this.renderAttachments();
  }

  /** The file picker's filter: the allowed types plus their extensions (some OSes need those). */
  private acceptList(): string {
    const types = this.attachRules.allowed_types;
    const exts = Object.keys(TYPE_BY_EXTENSION).filter((ext) => types.indexOf(TYPE_BY_EXTENSION[ext]) !== -1);
    return types.concat(exts.map((ext) => '.' + ext)).join(',');
  }

  private renderAttachments(): void {
    const t = strings(this.language);
    const box = this.els.attachments;
    box.hidden = this.pending.length === 0;
    box.replaceChildren(...this.pending.map((p) => {
      const chip = document.createElement('div');
      chip.className = 'chip ' + p.status;

      const thumb = document.createElement('span');
      thumb.className = 'chip-thumb';
      if (p.previewUrl) {
        const img = document.createElement('img');
        img.src = p.previewUrl;
        img.alt = '';
        thumb.appendChild(img);
      } else {
        thumb.innerHTML = ICONS.file;
      }

      const info = document.createElement('span');
      info.className = 'chip-info';
      const name = document.createElement('span');
      name.className = 'chip-name';
      name.textContent = p.file.name;
      const meta = document.createElement('span');
      meta.className = 'chip-meta';
      meta.textContent = p.status === 'uploading' ? t.uploading
        : p.status === 'error' ? this.uploadErrorText(p.error)
        : formatSize(p.file.size);
      info.append(name, meta);

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'chip-remove';
      remove.innerHTML = ICONS.remove;
      this.labelButton(remove, t.remove + ': ' + p.file.name);
      remove.addEventListener('click', () => this.removePending(p.key));

      chip.append(thumb, info, remove);
      return chip;
    }));
  }

  private uploadErrorText(code: string | undefined): string {
    const t = strings(this.language);
    if (code === 'file_too_large' || code === 'http_413') { return t.fileTooLarge; }
    if (code === 'file_type_not_allowed') { return t.fileTypeNotAllowed; }
    if (code === 'file_rejected') { return t.fileRejected; }
    if (code === 'too_many_files') { return t.tooManyFiles; }
    return t.uploadFailed;
  }

  private readStoredConversation(): string | null {
    try {
      return sessionStorage.getItem(this.storageKey);
    } catch {
      return null; // storage blocked (privacy mode, sandboxed iframe)
    }
  }

  private storeConversation(id: string | null): void {
    try {
      if (id) {
        sessionStorage.setItem(this.storageKey, id);
      } else {
        sessionStorage.removeItem(this.storageKey);
      }
    } catch {
      // Without storage the conversation simply does not survive a reload.
    }
  }

  private upgradeProperty(name: 'getHeaders' | 'options'): void {
    if (Object.prototype.hasOwnProperty.call(this, name)) {
      const value = (this as any)[name];
      delete (this as any)[name];
      (this as any)[name] = value;
    }
  }
}
