import { SseFrame, SseParser } from '../src/sse';

function parse(chunks: string[]): SseFrame[] {
  const frames: SseFrame[] = [];
  const parser = new SseParser((f) => frames.push(f));
  chunks.forEach((c) => parser.push(c));
  return frames;
}

describe('SseParser', () => {
  it('parses event and data', () => {
    expect(parse(['event: token\ndata: {"content":"hi"}\n\n'])).toEqual([
      { event: 'token', data: '{"content":"hi"}', comment: false },
    ]);
  });

  it('reassembles a frame split across chunks', () => {
    const frames = parse(['event: tok', 'en\ndata: {"con', 'tent":"hi"}\n', '\n']);
    expect(frames).toHaveLength(1);
    expect(frames[0].data).toBe('{"content":"hi"}');
  });

  it('joins multiple data lines with a newline', () => {
    expect(parse(['event: done\ndata: a\ndata: b\n\n'])[0].data).toBe('a\nb');
  });

  it('reports a comment-only frame as a keep-alive', () => {
    expect(parse([': heartbeat\n\n'])).toEqual([{ event: null, data: '', comment: true }]);
  });

  it('ignores comment lines inside a real event', () => {
    expect(parse([': note\nevent: done\ndata: {}\n\n'])).toEqual([{ event: 'done', data: '{}', comment: false }]);
  });

  it('handles CRLF, including a CR/LF pair split across chunks', () => {
    const frames = parse(['event: token\r\ndata: x\r', '\n\r\n']);
    expect(frames).toEqual([{ event: 'token', data: 'x', comment: false }]);
  });

  it('keeps an incomplete trailing frame buffered', () => {
    expect(parse(['event: token\ndata: x\n'])).toEqual([]);
  });
});
