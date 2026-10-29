import { fetchFile, fetchHistory, streamTurn, TurnHandlers, uploadFile } from '../src/client';
import { flush, HAPPY_PATH, sseResponse } from './helpers';

function recorder() {
  const calls: string[] = [];
  const handlers: TurnHandlers = {
    onConversation: (id) => calls.push('conversation:' + id),
    onToken: (t) => calls.push('token:' + t),
    onFile: (f) => calls.push('file:' + f.filename + ':' + f.media_type + ':' + f.size_bytes),
    onQuestion: (options) => calls.push('question:' + options.join('|')),
    onNotice: (k, d) => calls.push('notice:' + k + ':' + d),
    onDone: (id) => calls.push('done:' + id),
    onError: (code) => calls.push('error:' + code),
  };
  return { calls, handlers };
}

afterEach(() => vi.restoreAllMocks());

describe('streamTurn', () => {
  it('streams tokens and settles once on done', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(sseResponse(HAPPY_PATH));
    const { calls, handlers } = recorder();

    streamTurn('api/gaith', 'hello', null, null, handlers);
    await flush();

    expect(calls).toEqual(['conversation:c1', 'token:Hel', 'token:lo', 'done:c1']);
  });

  it('sends the message, conversation and host headers to {endpoint}/stream', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(sseResponse(HAPPY_PATH));
    const seen: unknown[] = [];

    streamTurn('api/v1/gaith/', 'hello', 'c1', (req) => {
      seen.push(req);
      return { Authorization: 'Bearer t', 'X-CSRF-TOKEN': 'sig' };
    }, recorder().handlers);
    await flush();

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('api/v1/gaith/stream');
    expect(init.body).toBe('{"message":"hello","conversation_id":"c1"}');
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer t');
    expect((init.headers as Record<string, string>)['X-CSRF-TOKEN']).toBe('sig');
    // The host signs exactly what is sent.
    expect(seen[0]).toEqual({
      method: 'POST',
      path: 'api/v1/gaith/stream',
      body: { message: 'hello', conversation_id: 'c1' },
      payload: '{"message":"hello","conversation_id":"c1"}',
    });
  });

  it('treats a stream that closes without done as an error', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(sseResponse(['event: token\ndata: {"content":"hi"}\n\n']));
    const { calls, handlers } = recorder();

    streamTurn('api/gaith', 'hello', null, null, handlers);
    await flush();

    expect(calls).toEqual(['token:hi', 'error:stream_closed']);
  });

  it('reports server error frames and safety blocks once', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(sseResponse([
      'event: safety_block\ndata: {}\n\n',
      'event: error\ndata: {"code":"late"}\n\n',
    ]));
    const { calls, handlers } = recorder();

    streamTurn('api/gaith', 'hello', null, null, handlers);
    await flush();

    expect(calls).toEqual(['error:safety_block']);
  });

  it('reports a non-2xx response as an error', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('', { status: 401 }));
    const { calls, handlers } = recorder();

    streamTurn('api/gaith', 'hello', null, null, handlers);
    await flush();

    expect(calls).toEqual(['error:http_401']);
  });

  it('never reports back after cancel', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(sseResponse(HAPPY_PATH));
    const { calls, handlers } = recorder();

    streamTurn('api/gaith', 'hello', null, null, handlers).cancel();
    await flush();

    expect(calls).toEqual([]);
  });
});

describe('file events', () => {
  it('reports files a tool produced, and ignores malformed ones', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(sseResponse([
      'event: file\ndata: {"artifact_id":"a1","filename":"onboarding-checklist.pdf","media_type":"application/pdf","size_bytes":36979}\n\n',
      'event: file\ndata: {"filename":"no-id.pdf"}\n\n',
      'event: done\ndata: {"message_id":"m1","conversation_id":"c1"}\n\n',
    ]));
    const { calls, handlers } = recorder();

    streamTurn('api/gaith', 'make a pdf', null, null, handlers);
    await flush();

    expect(calls).toEqual(['file:onboarding-checklist.pdf:application/pdf:36979', 'done:c1']);
  });
});

describe('question events', () => {
  it('reports the options of a clarifying question, and ignores a malformed one', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(sseResponse([
      'event: token\ndata: {"content":"Which month?"}\n\n',
      'event: question\ndata: {"question":"Which month?","options":["October"," November ","October",5]}\n\n',
      'event: question\ndata: {"question":"Broken","options":["only one"]}\n\n',
      'event: done\ndata: {"message_id":"m1","conversation_id":"c1","finish_reason":"question"}\n\n',
    ]));
    const { calls, handlers } = recorder();

    streamTurn('api/gaith', 'show my leave', null, null, handlers);
    await flush();

    expect(calls).toEqual(['token:Which month?', 'question:October|November', 'done:c1']);
  });
});

describe('fetchFile', () => {
  it('fetches through the host file route with the host headers', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('%PDF', { status: 200 }));

    const blob = await fetchFile('api/v1/gaith', 'a 1', () => ({ Authorization: 'Bearer t' }));

    expect(blob.size).toBe(4); // '%PDF'
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('api/v1/gaith/files/a%201');
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer t');
  });

  it('reports an expired file by status', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{}', { status: 410 }));

    await expect(fetchFile('api/gaith', 'a1', null)).rejects.toThrow('http_410');
  });
});

describe('streamTurn with attachments', () => {
  it('sends the attachment ids with the message', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(sseResponse(HAPPY_PATH));

    streamTurn('api/gaith', 'read this', null, null, recorder().handlers, ['art-1', 'art-2']);
    await flush();

    const init = fetchMock.mock.calls[0][1] as RequestInit;
    expect(JSON.parse(init.body as string)).toEqual({ message: 'read this', attachments: ['art-1', 'art-2'] });
  });
});

describe('uploadFile', () => {
  it('returns the uploaded file, and reports the host error code on refusal', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(new Response(JSON.stringify({ artifact_id: 'a1', filename: 'r.pdf', media_type: 'application/pdf', size_bytes: 3 }), { status: 201 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ code: 'file_too_large' }), { status: 413 }))
      .mockResolvedValueOnce(new Response('<html>Bad gateway</html>', { status: 502 }));
    const file = new File(['pdf'], 'r.pdf', { type: 'application/pdf' });

    expect(await uploadFile('api/gaith', file, null)).toEqual({ artifact_id: 'a1', filename: 'r.pdf', media_type: 'application/pdf', size_bytes: 3 });
    await expect(uploadFile('api/gaith', file, null)).rejects.toThrow('file_too_large');
    await expect(uploadFile('api/gaith', file, null)).rejects.toThrow('http_502');
    // The browser sets the multipart boundary itself; a manual Content-Type would break it.
    const headers = (fetchMock.mock.calls[0][1] as RequestInit).headers as Record<string, string>;
    expect(Object.keys(headers).map((h) => h.toLowerCase())).not.toContain('content-type');
  });
});

describe('fetchHistory', () => {
  it('returns the messages', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(
      JSON.stringify({ messages: [{ role: 'user', content: 'hi', created_at: '2026-01-01T00:00:00Z' }] }),
      { status: 200 },
    ));

    const history = await fetchHistory('api/gaith', 'c 1', null);

    expect(history).toEqual([{ role: 'user', content: 'hi', created_at: '2026-01-01T00:00:00Z' }]);
    expect(fetchMock.mock.calls[0][0]).toBe('api/gaith/conversations/c%201/messages');
  });

  it('returns null when the conversation is gone', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{}', { status: 404 }));

    expect(await fetchHistory('api/gaith', 'c1', null)).toBeNull();
  });
});
