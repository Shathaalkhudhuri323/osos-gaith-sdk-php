/** A fetch Response whose body yields the given chunks, like a real SSE stream. */
export function sseResponse(chunks: string[], status = 200): Response {
  const encoder = new TextEncoder();
  const body = new ReadableStream<Uint8Array>({
    start(controller) {
      chunks.forEach((c) => controller.enqueue(encoder.encode(c)));
      controller.close();
    },
  });
  return new Response(body, { status, headers: { 'Content-Type': 'text/event-stream' } });
}

export const HAPPY_PATH = [
  'event: meta\ndata: {"conversation_id":"c1"}\n\n',
  'event: token\ndata: {"content":"Hel"}\n\n',
  ': heartbeat\n\n',
  'event: token\ndata: {"content":"lo"}\n\n',
  'event: done\ndata: {"message_id":"m1","conversation_id":"c1","finish_reason":"stop"}\n\n',
];

/**
 * Fakes the host backend: each request is answered by the first route whose key
 * the URL ends with, with a fresh Response every time (a body can be read once).
 * Unrouted config calls get an empty theme.
 */
export function routeFetch(routes: Record<string, () => Response>) {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async (input: any) => {
    const url = String(input);
    for (const key of Object.keys(routes)) {
      if (url.endsWith(key)) {
        return routes[key]();
      }
    }
    if (url.endsWith('/config')) {
      return new Response(JSON.stringify({ theme: {} }), { status: 200 });
    }
    return new Response('', { status: 404 });
  });
}

export function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status });
}

export function flush(ms = 20): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
