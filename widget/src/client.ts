import { SseParser, SseFrame } from './sse';

/** What the host is given so it can sign the request its own way. */
export interface HeaderRequest {
  method: 'GET' | 'POST';
  /** Exactly the URL passed to fetch, e.g. "api/v1/gaith/stream". */
  path: string;
  /** The parsed JSON body, or null for GET. */
  body: Record<string, unknown> | null;
  /** The exact string sent as the body, for hosts that sign raw bytes. */
  payload: string | null;
  /** For a file upload: the multipart form being sent (body and payload are then null). */
  form?: FormData;
}

export type HeaderProvider = (request: HeaderRequest) => Record<string, string> | Promise<Record<string, string>>;

/** A file in a conversation: the user's upload or one a tool produced. */
export interface ChatFile {
  artifact_id: string;
  filename: string;
  media_type: string | null;
  size_bytes: number;
}

/** Callbacks fired as a reply streams in. Exactly one of onDone / onError fires. */
export interface TurnHandlers {
  onConversation(conversationId: string): void;
  onToken(content: string): void;
  onFile(file: ChatFile): void;
  onNotice(kind: 'limit' | 'tool', detail: string): void;
  /** The bot asks a clarifying question; these labels are offered as buttons. */
  onQuestion(options: string[]): void;
  onDone(conversationId: string | null): void;
  /** code is for logging; the widget shows its own localized text. */
  onError(code: string): void;
}

export interface HistoryMessage {
  role: string;
  content: string;
  created_at: string;
  files?: ChatFile[];
  /** Clickable answers to a clarifying question this reply asked. */
  options?: string[];
}

/** Clean, distinct option labels; GAITH sends 2-6, anything malformed is dropped. */
export function toOptions(value: unknown): string[] {
  if (!Array.isArray(value)) { return []; }
  const options: string[] = [];
  for (const item of value) {
    const label = typeof item === 'string' ? item.trim() : '';
    if (label && options.indexOf(label) === -1) { options.push(label); }
  }
  return options.slice(0, 6);
}

/** Keeps only well-formed file entries; anything else from the wire is dropped. */
export function toChatFile(data: any): ChatFile | null {
  if (!data || typeof data.artifact_id !== 'string' || data.artifact_id === '') {
    return null;
  }
  return {
    artifact_id: data.artifact_id,
    filename: typeof data.filename === 'string' && data.filename !== '' ? data.filename : 'file',
    media_type: typeof data.media_type === 'string' ? data.media_type.toLowerCase() : null,
    size_bytes: typeof data.size_bytes === 'number' ? data.size_bytes : 0,
  };
}

export interface Turn {
  cancel(): void;
}

function join(endpoint: string, path: string): string {
  return endpoint.replace(/\/+$/, '') + '/' + path;
}

async function headersFor(provider: HeaderProvider | null, request: HeaderRequest): Promise<Record<string, string>> {
  return provider ? await provider(request) : {};
}

/**
 * Sends one chat turn and streams the reply through the handlers.
 *
 * Uses fetch rather than EventSource: EventSource can only GET and cannot carry
 * the host's auth headers.
 */
export function streamTurn(
  endpoint: string,
  message: string,
  conversationId: string | null,
  headerProvider: HeaderProvider | null,
  handlers: TurnHandlers,
  attachments: string[] = [],
): Turn {
  const controller = new AbortController();
  let settled = false;

  // A turn ends exactly once. Without this a late network error after `done`
  // could report the turn twice and flip a finished reply to failed.
  const settle = (fn: () => void) => {
    if (settled) { return; }
    settled = true;
    fn();
  };

  const body: Record<string, unknown> = { message };
  if (conversationId) {
    body.conversation_id = conversationId;
  }
  if (attachments.length) {
    body.attachments = attachments;
  }
  const payload = JSON.stringify(body);
  const path = join(endpoint, 'stream');

  const onFrame = (frame: SseFrame) => {
    if (settled || frame.comment || frame.event === null) {
      return; // keep-alives only keep proxies from closing the socket
    }
    let data: Record<string, any> = {};
    if (frame.data !== '') {
      try {
        data = JSON.parse(frame.data);
      } catch {
        settle(() => handlers.onError('malformed_frame'));
        controller.abort();
        return;
      }
    }

    switch (frame.event) {
      case 'meta':
        if (data.conversation_id) { handlers.onConversation(String(data.conversation_id)); }
        break;
      case 'token':
        handlers.onToken(typeof data.content === 'string' ? data.content : '');
        break;
      case 'limit':
        handlers.onNotice('limit', String(data.reason || ''));
        break;
      case 'tool_call':
        handlers.onNotice('tool', String(data.tool || ''));
        break;
      case 'question': {
        // The question itself also arrives as tokens; this adds its answer buttons.
        const options = toOptions(data.options);
        if (options.length >= 2) { handlers.onQuestion(options); }
        break;
      }
      case 'file': {
        const file = toChatFile(data);
        if (file) { handlers.onFile(file); }
        break;
      }
      case 'done':
        settle(() => handlers.onDone(data.conversation_id ? String(data.conversation_id) : null));
        break;
      case 'error':
        settle(() => handlers.onError(String(data.code || 'error')));
        break;
      case 'safety_block':
        settle(() => handlers.onError('safety_block'));
        break;
      // tool_result and any future event names are ignored, never fatal.
    }
  };

  (async () => {
    const headers = await headersFor(headerProvider, { method: 'POST', path, body, payload });
    const response = await fetch(path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream', ...headers },
      body: payload,
      credentials: 'same-origin',
      signal: controller.signal,
    });
    if (!response.ok) {
      throw new Error('http_' + response.status);
    }

    const parser = new SseParser(onFrame);
    const decoder = new TextDecoder('utf-8');

    if (!response.body) {
      parser.push(await response.text());
    } else {
      const reader = response.body.getReader();
      for (;;) {
        const { done, value } = await reader.read();
        if (done) { break; }
        parser.push(decoder.decode(value, { stream: true }));
        if (settled) {
          // done/error already arrived: release the connection instead of draining it.
          reader.cancel().catch(() => undefined);
          return;
        }
      }
    }
    parser.push(decoder.decode() + '\n\n');

    // A close without done or error means the turn never finished; the widget
    // must not be left waiting.
    settle(() => handlers.onError('stream_closed'));
  })().catch((error: unknown) => {
    if (controller.signal.aborted) { return; }
    settle(() => handlers.onError(error instanceof Error && error.message ? error.message : 'network_error'));
  });

  return {
    cancel: () => {
      // Nothing may report back once the caller has walked away.
      settled = true;
      controller.abort();
    },
  };
}

/** Per-product settings from the host's backend (its .env / config file). */
export interface WidgetConfig {
  theme: { primary_color?: string; text_color?: string };
  /** Extra origins markdown images may load from (the host's own is always allowed). */
  image_origins: string[];
  attachments: AttachmentRules;
}

/** What the host's backend accepts as message attachments. */
export interface AttachmentRules {
  enabled: boolean;
  max_files: number;
  max_bytes: number;
  allowed_types: string[];
}

/** Until the backend says otherwise: off, so no attach button flashes up on hosts without it. */
export const NO_ATTACHMENTS: AttachmentRules = { enabled: false, max_files: 0, max_bytes: 0, allowed_types: [] };

function toAttachmentRules(data: any): AttachmentRules {
  if (!data || data.enabled !== true) {
    return NO_ATTACHMENTS;
  }
  return {
    enabled: true,
    max_files: typeof data.max_files === 'number' && data.max_files > 0 ? data.max_files : 1,
    max_bytes: typeof data.max_bytes === 'number' && data.max_bytes > 0 ? data.max_bytes : 0,
    allowed_types: Array.isArray(data.allowed_types)
      ? data.allowed_types.filter((t: unknown) => typeof t === 'string').map((t: string) => t.toLowerCase())
      : [],
  };
}

/**
 * Uploads one attached file through the host (which forwards it to GAITH) and
 * returns it as a conversation file. Errors carry the host's code, e.g.
 * `file_too_large`, `file_type_not_allowed`, or `http_<status>`.
 */
export async function uploadFile(
  endpoint: string,
  file: File,
  headerProvider: HeaderProvider | null,
): Promise<ChatFile> {
  const path = join(endpoint, 'attachments');
  const form = new FormData();
  form.append('file', file, file.name);
  const headers = await headersFor(headerProvider, { method: 'POST', path, body: null, payload: null, form });
  // No Content-Type here: the browser sets multipart/form-data with its boundary.
  const response = await fetch(path, {
    method: 'POST',
    headers: { Accept: 'application/json', ...headers },
    body: form,
    credentials: 'same-origin',
  });
  let json: any = null;
  try {
    json = await response.json();
  } catch {
    // e.g. a proxy's HTML error page; the status below still says what happened
  }
  if (!response.ok) {
    throw new Error(json && typeof json.code === 'string' ? json.code : 'http_' + response.status);
  }
  const uploaded = toChatFile(json);
  if (!uploaded) {
    throw new Error('upload_failed');
  }
  return uploaded;
}

export async function fetchConfig(endpoint: string, headerProvider: HeaderProvider | null): Promise<WidgetConfig> {
  const path = join(endpoint, 'config');
  const headers = await headersFor(headerProvider, { method: 'GET', path, body: null, payload: null });
  const response = await fetch(path, {
    headers: { Accept: 'application/json', ...headers },
    credentials: 'same-origin',
  });
  if (!response.ok) {
    throw new Error('http_' + response.status);
  }
  const json = await response.json();
  return {
    theme: (json && typeof json.theme === 'object' && json.theme) || {},
    image_origins: Array.isArray(json && json.image_origins)
      ? json.image_origins.filter((o: unknown) => typeof o === 'string')
      : [],
    attachments: toAttachmentRules(json && json.attachments),
  };
}

/**
 * A conversation file's bytes, through the host's file route (which holds the
 * GAITH key). Errors carry `http_<status>`: 410 = expired, 404 = not this user's.
 */
export async function fetchFile(
  endpoint: string,
  artifactId: string,
  headerProvider: HeaderProvider | null,
): Promise<Blob> {
  const path = join(endpoint, 'files/' + encodeURIComponent(artifactId));
  const headers = await headersFor(headerProvider, { method: 'GET', path, body: null, payload: null });
  const response = await fetch(path, { headers, credentials: 'same-origin' });
  if (!response.ok) {
    throw new Error('http_' + response.status);
  }
  return response.blob();
}

/**
 * The conversation's history from GAITH (via the host). Resolves null when the
 * conversation no longer exists for this user, so the caller starts fresh.
 */
export async function fetchHistory(
  endpoint: string,
  conversationId: string,
  headerProvider: HeaderProvider | null,
): Promise<HistoryMessage[] | null> {
  const path = join(endpoint, 'conversations/' + encodeURIComponent(conversationId) + '/messages');
  const headers = await headersFor(headerProvider, { method: 'GET', path, body: null, payload: null });
  const response = await fetch(path, {
    headers: { Accept: 'application/json', ...headers },
    credentials: 'same-origin',
  });
  if (response.status === 404) {
    return null;
  }
  if (!response.ok) {
    throw new Error('http_' + response.status);
  }
  const json = await response.json();
  return Array.isArray(json.messages) ? json.messages : [];
}
