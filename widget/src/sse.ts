/** One server-sent event. A keep-alive comment arrives as `{ comment: true }`. */
export interface SseFrame {
  event: string | null;
  data: string;
  comment: boolean;
}

/**
 * Incremental SSE parser. Bytes arrive in arbitrary chunks, so a frame split
 * across two network reads must reassemble - push() keeps the partial tail.
 * Mirrors the SDK's PHP SseReader so both halves agree on framing.
 */
export class SseParser {
  private buffer = '';
  private pendingCr = false;

  constructor(private readonly onFrame: (frame: SseFrame) => void) {}

  push(chunk: string): void {
    if (chunk === '') {
      return;
    }
    if (this.pendingCr) {
      // A CR/LF pair straddled two chunks: the LF belongs to the CR already seen.
      if (chunk[0] === '\n') {
        chunk = chunk.substring(1);
      }
      this.buffer += '\n';
      this.pendingCr = false;
    }
    if (chunk.endsWith('\r')) {
      this.pendingCr = true;
      chunk = chunk.substring(0, chunk.length - 1);
    }

    this.buffer += chunk.replace(/\r\n?/g, '\n');

    let separator = this.buffer.indexOf('\n\n');
    while (separator !== -1) {
      const raw = this.buffer.substring(0, separator);
      this.buffer = this.buffer.substring(separator + 2);
      this.dispatch(raw);
      separator = this.buffer.indexOf('\n\n');
    }
  }

  private dispatch(raw: string): void {
    let event: string | null = null;
    const data: string[] = [];
    let sawComment = false;

    for (const line of raw.split('\n')) {
      if (line === '') {
        continue;
      }
      if (line[0] === ':') {
        sawComment = true;
        continue;
      }
      const colon = line.indexOf(':');
      const field = colon === -1 ? line : line.substring(0, colon);
      let value = colon === -1 ? '' : line.substring(colon + 1);
      if (value[0] === ' ') {
        value = value.substring(1);
      }
      if (field === 'event') {
        event = value;
      } else if (field === 'data') {
        data.push(value);
      }
    }

    if (event === null && data.length === 0) {
      if (sawComment) {
        this.onFrame({ event: null, data: '', comment: true });
      }
      return;
    }

    this.onFrame({ event, data: data.join('\n'), comment: false });
  }
}
