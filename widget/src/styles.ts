/**
 * Lives in the shadow root, so host CSS cannot break it; hosts theme it through
 * the custom properties on :host (or the backend theme, see .root below).
 */
export const STYLES = `
:host {
  --gaith-chat-bottom: 24px;
  --gaith-chat-side: 24px;
  --gaith-chat-z-index: 1030;
  /* A clean system stack that also covers Arabic; hosts can set their own. */
  --gaith-chat-font: system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", "Noto Sans Arabic", Tahoma, Arial, sans-serif;
  all: initial;
  font-family: var(--gaith-chat-font);
}
* { box-sizing: border-box; }
.root {
  /* Page CSS (--gaith-chat-*) wins over the server theme (set inline from {prefix}/config),
     which wins over the built-in default. */
  --p: var(--gaith-chat-primary, var(--gaith-chat-theme-primary, #1f6feb));
  --on-p: var(--gaith-chat-on-primary, var(--gaith-chat-theme-on-primary, #fff));
  --p-soft: color-mix(in srgb, var(--p) 10%, transparent);
  --ink: #1f2328;
  --muted: #6b7280;
  --line: #e6e8ec;
  --surface: #f6f7f9;
  position: fixed;
  right: var(--gaith-chat-side);
  bottom: var(--gaith-chat-bottom);
  z-index: var(--gaith-chat-z-index);
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  font-family: var(--gaith-chat-font);
  color: var(--ink);
  -webkit-font-smoothing: antialiased;
}
/* Hidden until the product theme arrives, so the button never flashes the wrong colour. */
.root.pending { visibility: hidden; }
.root[dir="rtl"] { right: auto; left: var(--gaith-chat-side); align-items: flex-start; }

/* ---------- Launcher ---------- */
.launcher {
  width: 60px; height: 60px; border: 0; border-radius: 50%; padding: 0; overflow: hidden;
  display: flex; align-items: center; justify-content: center;
  background: var(--p); color: var(--on-p);
  cursor: pointer;
  box-shadow: 0 6px 16px rgba(16,24,40,.22);
  transition: transform .2s cubic-bezier(.2,.8,.2,1), box-shadow .2s ease;
}
.launcher:hover { transform: translateY(-2px) scale(1.04); }
.launcher:active { transform: scale(.96); }
.launcher.has-image:not(.open) { background: transparent; box-shadow: 0 6px 16px rgba(16,24,40,.2); }
.launcher img { width: 44px; height: 44px; object-fit: contain; display: block; pointer-events: none; }
.launcher svg { width: 26px; height: 26px; animation: spin-in .25s cubic-bezier(.2,.8,.2,1); }
@keyframes spin-in { from { transform: rotate(-90deg) scale(.6); opacity: 0; } to { transform: none; opacity: 1; } }

/* ---------- Panel ---------- */
.panel {
  display: flex; flex-direction: column;
  width: 400px; height: 640px; max-height: calc(100vh - 140px); max-width: calc(100vw - 48px);
  margin-bottom: 16px; overflow: hidden;
  background: #fff; border-radius: 20px;
  box-shadow: 0 24px 60px -12px rgba(16,24,40,.28), 0 8px 20px -8px rgba(16,24,40,.14), 0 0 0 1px rgba(16,24,40,.05);
  transform-origin: bottom right;
  animation: pop-in .22s cubic-bezier(.2,.8,.2,1);
}
.root[dir="rtl"] .panel { transform-origin: bottom left; }
.panel[hidden] { display: none; }
@keyframes pop-in { from { opacity: 0; transform: translateY(12px) scale(.96); } to { opacity: 1; transform: none; } }

/* ---------- Header ---------- */
/* Compact, solid product colour - no gradient, so it matches any product's brand as-is. */
.header {
  display: flex; align-items: center; gap: 10px; flex: 0 0 auto;
  padding: 10px 10px 10px 14px;
  background: var(--p); color: var(--on-p);
}
.root[dir="rtl"] .header { padding: 10px 14px 10px 10px; }
.avatar {
  position: relative; flex: 0 0 auto; width: 30px; height: 30px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: rgba(255,255,255,.18);
}
.avatar svg { width: 16px; height: 16px; }
.status { position: absolute; bottom: -1px; right: -1px; display: flex; }
.root[dir="rtl"] .status { right: auto; left: -1px; }
.dot { width: 9px; height: 9px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 0 2px var(--p); }
.dot.busy { background: #fbbf24; animation: pulse 1s ease-in-out infinite; }
.dot.error { background: #f87171; animation: none; }
@keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: .4; } }
.heading { display: flex; flex-direction: column; min-width: 0; flex: 1 1 auto; }
.title { font-size: 13.5px; font-weight: 600; line-height: 1.2; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.subtitle { font-size: 11px; opacity: .8; line-height: 1.2; }

.actions { display: flex; align-items: center; gap: 2px; }
.action {
  width: 30px; height: 30px; padding: 0; border: 0; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: transparent; color: inherit; cursor: pointer; opacity: .9;
  transition: background-color .15s ease, opacity .15s ease;
}
.action:hover { background: rgba(255,255,255,.16); opacity: 1; }
.action svg { width: 15px; height: 15px; }

/* ---------- Messages ---------- */
.messages {
  flex: 1 1 auto; padding: 18px 16px 8px; overflow-y: auto; background: var(--surface);
  scrollbar-width: thin; scrollbar-color: #cfd4da transparent;
}
.messages::-webkit-scrollbar { width: 6px; }
.messages::-webkit-scrollbar-thumb { background: #cfd4da; border-radius: 3px; }
.message { display: flex; flex-direction: column; align-items: flex-end; margin-bottom: 14px; animation: rise .2s ease; }
.message.bot { align-items: flex-start; }
@keyframes rise { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
.bubble {
  max-width: 82%; padding: 10px 14px; border-radius: 18px 18px 6px 18px;
  font-size: 14px; line-height: 1.5; word-break: break-word; white-space: pre-wrap;
  background: var(--p); color: var(--on-p);
  box-shadow: 0 1px 2px rgba(16,24,40,.08);
}
.root[dir="rtl"] .bubble { border-radius: 18px 18px 18px 6px; }
.message.bot .bubble {
  white-space: normal; background: #fff; color: var(--ink);
  border-radius: 18px 18px 18px 6px; box-shadow: 0 1px 2px rgba(16,24,40,.06), 0 0 0 1px var(--line);
}
.root[dir="rtl"] .message.bot .bubble { border-radius: 18px 18px 6px 18px; }
.notice { font-size: 12px; color: var(--muted); margin: 6px 6px 0; font-style: italic; }
.time { margin: 5px 6px 0; font-size: 11px; line-height: 1; color: #9aa1ab; font-variant-numeric: tabular-nums; }

/* Markdown inside a reply: compact, document-like spacing. */
.bubble p, .bubble ul, .bubble ol, .bubble pre, .bubble blockquote, .bubble table { margin: 0 0 8px; }
.bubble > :last-child { margin-bottom: 0; }
.bubble ul, .bubble ol { padding-left: 20px; }
.bubble li + li { margin-top: 2px; }
.root[dir="rtl"] .bubble ul, .root[dir="rtl"] .bubble ol { padding-left: 0; padding-right: 20px; }
.bubble code { padding: 1px 5px; border-radius: 5px; background: rgba(16,24,40,.07); font-size: 12.5px; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; }
.bubble pre { padding: 10px 12px; border-radius: 10px; background: #f3f4f6; overflow-x: auto; }
.bubble pre code { padding: 0; background: none; }
.bubble table { width: 100%; border-collapse: collapse; font-size: 12.5px; overflow: hidden; border-radius: 8px; }
.bubble th { background: #f3f4f6; font-weight: 600; text-align: start; }
.bubble th, .bubble td { padding: 6px 8px; border: 1px solid var(--line); }
.bubble blockquote { padding-left: 10px; border-left: 3px solid var(--line); color: #4b5563; }
.root[dir="rtl"] .bubble blockquote { padding-left: 0; padding-right: 10px; border-left: 0; border-right: 3px solid var(--line); }
.bubble a { color: inherit; text-decoration: underline; text-underline-offset: 2px; word-break: break-word; }
.message.bot .bubble a { color: var(--p); }
.bubble h1, .bubble h2, .bubble h3, .bubble h4, .bubble h5, .bubble h6 { margin: 4px 0 6px; font-size: 14px; font-weight: 700; }
.bubble hr { border: 0; border-top: 1px solid var(--line); margin: 10px 0; }
.bubble img { display: block; max-width: 100%; max-height: 240px; border-radius: 10px; margin: 6px 0; }

/* Typing: three dots in their own reply bubble. */
.typing {
  display: inline-flex; align-items: center; gap: 4px; padding: 14px 16px; margin-bottom: 14px;
  background: #fff; border-radius: 18px 18px 18px 6px; box-shadow: 0 1px 2px rgba(16,24,40,.06), 0 0 0 1px var(--line);
}
.root[dir="rtl"] .typing { border-radius: 18px 18px 6px 18px; }
.typing span { width: 7px; height: 7px; border-radius: 50%; background: #9aa1ab; animation: bounce 1.2s ease-in-out infinite; }
.typing span:nth-child(2) { animation-delay: .15s; }
.typing span:nth-child(3) { animation-delay: .3s; }
@keyframes bounce { 0%,60%,100% { transform: translateY(0); opacity: .45; } 30% { transform: translateY(-4px); opacity: 1; } }

/* ---------- Files ---------- */
.files { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; margin-top: 6px; width: 82%; max-width: 300px; }
.message:not(.bot) .files { align-items: flex-end; }

/* Inline image: a rounded photo with a soft shadow; an expand hint appears on hover. */
.preview {
  position: relative; padding: 0; border: 0; border-radius: 16px; background: #e9ecef; cursor: zoom-in;
  overflow: hidden; max-width: 100%; line-height: 0; box-shadow: 0 1px 2px rgba(16,24,40,.06), 0 4px 12px rgba(16,24,40,.08);
  transition: box-shadow .15s ease, transform .15s ease;
}
.preview:hover { box-shadow: 0 8px 20px rgba(16,24,40,.16); transform: translateY(-1px); }
.preview img { display: block; max-width: 100%; max-height: 260px; min-width: 96px; min-height: 72px; object-fit: cover; animation: fade-in .2s ease; }
.preview.loading { width: 240px; height: 180px; cursor: progress; }
.preview.loading img, .preview.loading .preview-hint { visibility: hidden; }
.preview.loading::after {
  content: ""; position: absolute; inset: 0;
  background: linear-gradient(100deg, transparent 20%, rgba(255,255,255,.55) 50%, transparent 80%) #e9ecef;
  background-size: 200% 100%; animation: shimmer 1.2s linear infinite;
}
@keyframes shimmer { from { background-position: 150% 0; } to { background-position: -50% 0; } }
@keyframes fade-in { from { opacity: 0; } to { opacity: 1; } }
.preview-hint {
  position: absolute; top: 8px; right: 8px; width: 30px; height: 30px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: rgba(0,0,0,.45); color: #fff; opacity: 0; transition: opacity .15s ease;
}
.root[dir="rtl"] .preview-hint { right: auto; left: 8px; }
.preview:hover .preview-hint, .preview:focus-visible .preview-hint { opacity: 1; }
.preview-hint svg { width: 14px; height: 14px; }

/* File card: one standard icon for every file, name over "PDF · 36 KB", and an open/download button. */
.file-card {
  display: flex; align-items: center; gap: 12px; width: 100%; padding: 10px;
  border: 0; border-radius: 16px; background: #fff; color: var(--ink);
  font: inherit; text-align: start; cursor: pointer;
  box-shadow: 0 1px 2px rgba(16,24,40,.06), 0 0 0 1px var(--line);
  transition: box-shadow .15s ease, transform .15s ease;
}
.file-card:hover:not(:disabled) { box-shadow: 0 8px 20px rgba(16,24,40,.12), 0 0 0 1px #d6dae0; transform: translateY(-1px); }
.file-card:disabled { cursor: default; background: #f3f4f6; box-shadow: 0 0 0 1px var(--line); }
.file-icon {
  flex: 0 0 auto; width: 42px; height: 42px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  color: var(--p); background: rgba(16,24,40,.06); background: var(--p-soft);
}
.file-icon svg { width: 20px; height: 20px; }
.file-card:disabled .file-icon { color: #9aa0a6; background: #e9ecef; }
.file-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1 1 auto; }
.file-name { font-size: 13.5px; font-weight: 600; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.file-meta { font-size: 12px; color: var(--muted); line-height: 1.3; }
.file-action {
  flex: 0 0 auto; width: 34px; height: 34px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  color: var(--p); background: rgba(16,24,40,.06); background: var(--p-soft);
  transition: background-color .15s ease, color .15s ease;
}
.file-card:hover .file-action { background: var(--p); color: var(--on-p); }
.file-action svg { width: 16px; height: 16px; }

/* ---------- Starter options ---------- */
.options { display: flex; flex-wrap: wrap; gap: 8px; margin: 2px 0 12px; }
.option {
  max-width: 100%; padding: 8px 14px; border: 0; border-radius: 999px;
  background: #fff; color: var(--p); font: inherit; font-size: 13px; font-weight: 500; text-align: start; cursor: pointer;
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--p) 35%, transparent), 0 1px 2px rgba(16,24,40,.05);
  transition: background-color .15s ease, color .15s ease, transform .15s ease;
}
.option:hover:not(:disabled) { background: var(--p); color: var(--on-p); transform: translateY(-1px); }
.option:disabled { opacity: .5; cursor: default; }

/* ---------- Composer ---------- */
.composer { flex: 0 0 auto; padding: 12px 14px 14px; background: #fff; border-top: 1px solid var(--line); }

/* Attached files waiting to be sent: chips above the input. */
.attachments { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; max-height: 120px; overflow-y: auto; }
.attachments[hidden] { display: none; }
.chip {
  display: flex; align-items: center; gap: 8px; max-width: 100%; min-width: 0; padding: 5px 6px 5px 5px;
  border-radius: 12px; background: var(--surface); box-shadow: inset 0 0 0 1px var(--line);
}
.root[dir="rtl"] .chip { padding: 5px 5px 5px 6px; }
.chip.error { background: #fef2f2; box-shadow: inset 0 0 0 1px #fecaca; }
.chip-thumb {
  position: relative; flex: 0 0 auto; width: 34px; height: 34px; border-radius: 8px; overflow: hidden;
  display: flex; align-items: center; justify-content: center; color: var(--p); background: var(--p-soft);
}
.chip-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.chip-thumb svg { width: 18px; height: 18px; }
.chip.error .chip-thumb { color: #dc2626; background: #fee2e2; }
.chip.uploading .chip-thumb::after {
  content: ""; position: absolute; inset: 0; background: rgba(255,255,255,.55);
  border-radius: 8px; animation: pulse 1s ease-in-out infinite;
}
.chip-info { display: flex; flex-direction: column; min-width: 0; max-width: 150px; }
.chip-name { font-size: 12.5px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.chip-meta { font-size: 11px; color: var(--muted); }
.chip.error .chip-meta { color: #dc2626; }
.chip-remove {
  flex: 0 0 auto; width: 22px; height: 22px; padding: 0; border: 0; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: transparent; color: var(--muted); cursor: pointer;
}
.chip-remove:hover { background: rgba(16,24,40,.08); color: var(--ink); }
.chip-remove svg { width: 12px; height: 12px; }

/* Paperclip inside the input bar. */
.attach {
  flex: 0 0 auto; width: 36px; height: 36px; margin: 0 0 0 -10px; padding: 0; border: 0; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: transparent; color: var(--muted); cursor: pointer; transition: background-color .15s ease, color .15s ease;
}
.root[dir="rtl"] .attach { margin: 0 -10px 0 0; }
.attach[hidden] { display: none; }
.attach:hover:not(:disabled) { background: var(--p-soft); color: var(--p); }
.attach:disabled { opacity: .45; cursor: default; }
.attach svg { width: 19px; height: 19px; }

/* Shown over the panel while files are dragged onto it. */
.panel { position: relative; }
.drop-hint {
  position: absolute; inset: 10px; z-index: 2; border-radius: 16px;
  display: flex; align-items: center; justify-content: center; text-align: center; padding: 20px;
  font-size: 15px; font-weight: 600; color: var(--p);
  background: rgba(255,255,255,.92); box-shadow: inset 0 0 0 2px var(--p); border: 0;
  pointer-events: none;
}
.drop-hint[hidden] { display: none; }
.composer-box {
  display: flex; align-items: flex-end; gap: 8px; padding: 6px 6px 6px 16px;
  border-radius: 24px; background: var(--surface); box-shadow: inset 0 0 0 1px var(--line);
  transition: box-shadow .15s ease, background-color .15s ease;
}
.root[dir="rtl"] .composer-box { padding: 6px 16px 6px 6px; }
.composer-box:focus-within { background: #fff; box-shadow: inset 0 0 0 1.5px var(--p), 0 0 0 4px var(--p-soft); }
.input {
  flex: 1 1 auto; height: 36px; max-height: 120px; min-height: 36px; padding: 8px 0; resize: none; overflow-y: auto;
  border: 0; background: transparent; color: var(--ink); font: inherit; font-size: 14px; line-height: 20px; outline: none;
}
.input::placeholder { color: #9aa1ab; }
.send {
  flex: 0 0 auto; width: 36px; height: 36px; border: 0; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: var(--p); color: var(--on-p); cursor: pointer;
  transition: transform .15s ease, opacity .15s ease, background-color .15s ease;
}
.send:hover:not(:disabled) { transform: scale(1.06); }
.send:disabled { background: #d5d9df; color: #fff; cursor: not-allowed; }
.send svg { width: 16px; height: 16px; }
.root[dir="rtl"] .send svg { transform: scaleX(-1); }
button:focus-visible { outline: 2px solid var(--p); outline-offset: 2px; }
.header button:focus-visible { outline-color: var(--on-p); }

@media (max-width: 575px) {
  .root { right: 12px; }
  .root[dir="rtl"] { left: 12px; }
  .panel { width: calc(100vw - 24px); height: calc(100vh - 120px); border-radius: 16px; }
}
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation: none !important; transition: none !important; }
}
`;
