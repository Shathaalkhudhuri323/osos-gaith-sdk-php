/**
 * Lives in the shadow root, so host CSS cannot break it; hosts theme it through
 * the custom properties on :host (or the backend theme, see .root below).
 */
export const STYLES = `
:host {
  --gaith-chat-bottom: 24px;
  --gaith-chat-side: 24px;
  --gaith-chat-z-index: 1030;
  --gaith-chat-width: 420px;
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
  --surface: #fafafa;
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
/* Not shown at all until the backend confirms the chatbot is on for this customer. */
.root.off { display: none; }
.root[dir="rtl"] { right: auto; left: var(--gaith-chat-side); align-items: flex-start; }

/* ---------- Launcher ---------- */
/* Small round button. By default: the animated GAITH logo on a white circle with a soft
   shadow, so it stands out on any page; a host's own launcher-icon sits on a clear circle. */
.launcher {
  width: 48px; height: 48px; border: 0; border-radius: 50%; padding: 0; overflow: hidden;
  display: flex; align-items: center; justify-content: center;
  background: var(--p); background: color-mix(in srgb, var(--p) 70%, transparent); color: var(--on-p);
  -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px);
  cursor: pointer;
  box-shadow: 0 4px 12px rgba(16,24,40,.16);
  transition: transform .2s cubic-bezier(.2,.8,.2,1), box-shadow .2s ease, background-color .2s ease;
}
.launcher:hover { background: var(--p); }
/* The pane has its own close button; the launcher steps aside while it is open. */
.launcher.open { display: none; }
.launcher:hover { transform: translateY(-2px) scale(1.04); box-shadow: 0 6px 16px rgba(16,24,40,.2); }
.launcher:active { transform: scale(.96); }
.launcher.has-image:not(.open) { background: transparent; box-shadow: 0 4px 12px rgba(16,24,40,.16); }
.launcher.logo, .launcher.logo:hover {
  background: #fff; -webkit-backdrop-filter: none; backdrop-filter: none;
  box-shadow: 0 6px 16px rgba(16,24,40,.18), 0 1px 3px rgba(16,24,40,.12);
}
.launcher.logo:hover { box-shadow: 0 10px 24px rgba(16,24,40,.22), 0 2px 6px rgba(16,24,40,.12); }
.launcher img { width: 34px; height: 34px; object-fit: contain; display: block; pointer-events: none; }
.launcher.logo img { width: 30px; height: 30px; }
.launcher svg { width: 30px; height: 30px; animation: spin-in .25s cubic-bezier(.2,.8,.2,1); }
.launcher.open svg { width: 18px; height: 18px; }
@keyframes spin-in { from { transform: rotate(-90deg) scale(.6); opacity: 0; } to { transform: none; opacity: 1; } }

/* ---------- Panel ---------- */
/* A full-height side pane on the page's right edge (left in Arabic), like Copilot in Office. */
.panel {
  position: fixed; top: 0; right: 0; bottom: 0;
  display: flex; flex-direction: column;
  width: var(--gaith-chat-width); max-width: 100vw; overflow: hidden;
  background: var(--surface); border-left: 1px solid #e0e0e0;
  box-shadow: -4px 0 16px rgba(0,0,0,.06);
  animation: slide-in .22s cubic-bezier(.2,.8,.2,1);
}
.root[dir="rtl"] .panel {
  right: auto; left: 0; border-left: 0; border-right: 1px solid #e0e0e0;
  box-shadow: 4px 0 16px rgba(0,0,0,.06); animation-name: slide-in-rtl;
}
.panel[hidden] { display: none; }
@keyframes slide-in { from { opacity: 0; transform: translateX(24px); } to { opacity: 1; transform: none; } }
@keyframes slide-in-rtl { from { opacity: 0; transform: translateX(-24px); } to { opacity: 1; transform: none; } }

/* ---------- Panel controls ---------- */
/* One plain row, no bar: status at the start, refresh/close at the end. */
.header {
  flex: 0 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 6px;
  height: 56px; padding: 0 12px 0 18px; background: transparent; color: var(--muted);
}
.root[dir="rtl"] .header { padding: 0 18px 0 12px; }
/* Menu (chat history) then the status, at the start of the row. */
.start { display: flex; align-items: center; gap: 8px; min-width: 0; }
.status {
  display: flex; align-items: center; gap: 6px; height: 32px; padding: 0;
  font-size: 12px; line-height: 1; color: var(--muted); white-space: nowrap;
}
.dot { flex: 0 0 auto; width: 8px; height: 8px; border-radius: 50%; background: #22c55e; }
.dot.busy { background: #f59e0b; animation: pulse 1s ease-in-out infinite; }
.dot.error { background: #ef4444; animation: none; }
@keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: .4; } }

.actions {
  display: flex; align-items: center; gap: 2px;
  background: transparent;
}
/* Small, see-through icons; solid on hover. */
.action {
  width: 28px; height: 28px; padding: 0; border: 0; border-radius: 6px;
  display: flex; align-items: center; justify-content: center;
  background: transparent; color: #424242; cursor: pointer; opacity: .5;
  transition: background-color .15s ease, opacity .15s ease;
}
.action:hover { background: rgba(0,0,0,.05); opacity: 1; }
.action:focus-visible { opacity: 1; }
.action svg { width: 16px; height: 16px; stroke-width: 1.8; }
.action.reset svg, .action.close svg { width: 14px; height: 14px; }
/* Data protection shield: a link once the product sets its policy URL, otherwise just the badge. */
/* Data protection: a lock in the product colour, with a small card on hover or focus. */
.protect { position: relative; display: flex; }
.action.privacy { color: var(--p); cursor: default; }
.protect:hover .action.privacy, .action.privacy:focus-visible { opacity: 1; background: rgba(0,0,0,.05); }
/* One look for every hover card: the data protection note and each control's label. */
.protect-tip, .tip {
  width: max-content; max-width: 240px; padding: 8px 12px; border-radius: 8px;
  background: #fff; color: var(--ink); font-size: 13px; line-height: 1.45; overflow-wrap: anywhere;
  box-shadow: 0 4px 16px rgba(0,0,0,.14), 0 0 0 1px rgba(0,0,0,.05);
  visibility: hidden; opacity: 0; transition: opacity .12s ease, visibility .12s;
}
.protect-tip { position: absolute; top: 100%; inset-inline-end: -8px; z-index: 3; margin-top: 6px; }
.tip { position: fixed; top: 0; left: 0; z-index: 5; padding: 6px 10px; pointer-events: none; }
.tip.show { visibility: visible; opacity: 1; }
/* Invisible bridge over the gap, so the pointer can move from the lock onto the link. */
.protect-tip::before { content: ""; position: absolute; left: 0; right: 0; top: -8px; height: 8px; }
.protect:hover .protect-tip, .protect:focus-within .protect-tip { visibility: visible; opacity: 1; }
.protect-name { color: inherit; font-weight: 600; }
a.protect-name { text-decoration: underline; text-underline-offset: 2px; }
a.protect-name:hover { color: var(--p); }

/* ---------- Messages ---------- */
.messages {
  flex: 1 1 auto; padding: 8px 24px 12px; overflow-y: auto; background: var(--surface);
  scrollbar-width: thin; scrollbar-color: #cfd4da transparent;
}
.messages::-webkit-scrollbar { width: 6px; }
.messages::-webkit-scrollbar-thumb { background: #cfd4da; border-radius: 3px; }
.message { display: flex; flex-direction: column; align-items: flex-end; margin-bottom: 18px; animation: rise .2s ease; }
.message.bot { align-items: flex-start; }
/* Welcome: its first paragraph is the pane's title, the rest a short intro. */
.message.welcome { margin: 4px 0 20px; }
.message.welcome .bubble > :first-child { margin-bottom: 12px; font-size: 20px; font-weight: 700; line-height: 1.35; }
@keyframes rise { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
/* The user's message is a soft bubble; replies are plain text on the pane, full width. */
.bubble {
  max-width: 85%; padding: 10px 14px; border-radius: 16px;
  font-size: 15px; line-height: 1.6; word-break: break-word; white-space: pre-wrap;
  background: #ebebeb; background: color-mix(in srgb, var(--p) 10%, #ececec); color: var(--ink);
}
.message.bot .bubble {
  max-width: 100%; padding: 0; white-space: normal; background: transparent; border-radius: 0;
}
.notice { font-size: 12px; color: var(--muted); margin: 6px 6px 0; font-style: italic; }
.time { margin: 5px 6px 0; font-size: 11px; line-height: 1; color: #9aa1ab; font-variant-numeric: tabular-nums; }

/* Markdown inside a reply: compact, document-like spacing. */
.bubble p, .bubble ul, .bubble ol, .bubble pre, .bubble blockquote, .bubble table { margin: 0 0 8px; }
.bubble > :last-child { margin-bottom: 0; }
.bubble ul, .bubble ol { padding-left: 20px; }
.bubble li + li { margin-top: 2px; }
.root[dir="rtl"] .bubble ul, .root[dir="rtl"] .bubble ol { padding-left: 0; padding-right: 20px; }
.bubble code { padding: 1px 5px; border-radius: 5px; background: rgba(16,24,40,.07); font-size: 12.5px; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; }
.bubble pre { padding: 10px 12px; border-radius: 10px; background: #f0f0f0; overflow-x: auto; }
.bubble pre code { padding: 0; background: none; }
.bubble table { width: 100%; border-collapse: collapse; font-size: 12.5px; overflow: hidden; border-radius: 8px; }
.bubble th { background: #f3f4f6; font-weight: 600; text-align: start; }
.bubble th, .bubble td { padding: 6px 8px; border: 1px solid var(--line); }
.bubble blockquote { padding-left: 10px; border-left: 3px solid var(--line); color: #4b5563; }
.root[dir="rtl"] .bubble blockquote { padding-left: 0; padding-right: 10px; border-left: 0; border-right: 3px solid var(--line); }
.bubble a { color: inherit; text-decoration: underline; text-underline-offset: 2px; word-break: break-word; }
.message.bot .bubble a { color: var(--p); }
.bubble h1, .bubble h2, .bubble h3, .bubble h4, .bubble h5, .bubble h6 { margin: 4px 0 6px; font-size: 15px; font-weight: 700; }
.bubble hr { border: 0; border-top: 1px solid var(--line); margin: 10px 0; }
.bubble img { display: block; max-width: 100%; max-height: 240px; border-radius: 10px; margin: 6px 0; }

/* Typing: three dots in their own reply bubble. */
.typing { display: inline-flex; align-items: center; gap: 4px; padding: 8px 0; margin-bottom: 18px; }
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
/* Suggestions: grey pills, one per line. */
.options { display: flex; flex-direction: column; align-items: flex-start; gap: 10px; margin: 0 0 18px; }
.option {
  max-width: 100%; padding: 10px 16px; border: 0; border-radius: 14px;
  background: #f0f0f0; color: #424242; font: inherit; font-size: 15px; line-height: 1.45; text-align: start; cursor: pointer;
  transition: background-color .15s ease;
}
.option:hover:not(:disabled) { background: #e6e6e6; }
.option:disabled { opacity: .5; cursor: default; }
/* A link that is not clickable: shown as text, with long addresses allowed to wrap. */
.blocked-link { overflow-wrap: anywhere; }
/* Connect buttons: side by side, the first one in the product colour. */
.connect { flex-direction: row; flex-wrap: wrap; }
.connect .option.primary { background: var(--p); color: var(--on-p); }

/* ---------- Composer ---------- */
.composer { flex: 0 0 auto; padding: 8px 24px 10px; background: var(--surface); }
/* Footer under the input. */
.powered { margin-top: 10px; text-align: center; color: #616161; font-size: 12px; line-height: 1.4; }
.powered-label { unicode-bidi: plaintext; }

/* Attached files waiting to be sent: chips above the input. */
.attachments { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; max-height: 120px; overflow-y: auto; }
.attachments[hidden] { display: none; }
.chip {
  display: flex; align-items: center; gap: 8px; max-width: 100%; min-width: 0; padding: 5px 6px 5px 5px;
  border-radius: 12px; background: #fff; box-shadow: inset 0 0 0 1px var(--line);
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

/* Attachment action, presented as the plus control inside the input bar. */
.attach {
  flex: 0 0 auto; width: 36px; height: 36px; margin: 0; padding: 0; border: 0; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: transparent; color: #424242; cursor: pointer; transition: background-color .15s ease, color .15s ease;
}
.attach[hidden] { display: none; }
.attach:hover:not(:disabled) { background: var(--p-soft); color: var(--p); }
.attach:disabled { opacity: .45; cursor: default; }
.attach svg { width: 20px; height: 20px; stroke-width: 1.6; }

/* Shown over the panel while files are dragged onto it. */
.drop-hint {
  position: absolute; inset: 10px; z-index: 2; border-radius: 16px;
  display: flex; align-items: center; justify-content: center; text-align: center; padding: 20px;
  font-size: 15px; font-weight: 600; color: var(--p);
  background: rgba(255,255,255,.92); box-shadow: inset 0 0 0 2px var(--p); border: 0;
  pointer-events: none;
}
.drop-hint[hidden] { display: none; }
/* A white pill: plus, text, send. */
.composer-box {
  display: flex; align-items: center; gap: 6px; min-height: 50px; padding: 6px 8px;
  border-radius: 25px; background: #fff; box-shadow: 0 0 0 1px #d1d1d1;
  transition: box-shadow .15s ease;
}
.composer-box:focus-within { box-shadow: 0 0 0 1.5px var(--p); }
.attach[hidden] ~ .input { margin-inline-start: 10px; }
.input {
  flex: 1 1 auto; height: 36px; max-height: 120px; min-height: 36px; padding: 8px 0; resize: none; overflow-y: auto;
  border: 0; background: transparent; color: var(--ink); font: inherit; font-size: 15px; line-height: 20px; outline: none;
}
.input::placeholder { color: #707070; opacity: 1; }
.send {
  flex: 0 0 auto; width: 34px; height: 34px; border: 0; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: var(--p); color: var(--on-p); cursor: pointer;
  transition: background-color .15s ease, transform .15s ease;
}
.send:hover:not(:disabled) { transform: scale(1.06); }
/* Like Copilot: the send button only appears once there is something to send. */
.send:disabled { display: none; }
.send svg { width: 18px; height: 18px; }

/* Mic: same plain icon as the plus; while listening it fills with the product colour and pulses. */
.mic {
  flex: 0 0 auto; width: 36px; height: 36px; margin: 0; padding: 0; border: 0; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: transparent; color: #424242; cursor: pointer; transition: background-color .15s ease, color .15s ease;
}
.mic[hidden] { display: none; }
.mic:hover:not(:disabled) { background: rgba(0,0,0,.05); }
.mic:disabled { opacity: .45; cursor: default; }
.mic svg { width: 20px; height: 20px; stroke-width: 1.6; }
.mic.listening, .mic.listening:hover { background: var(--p); color: var(--on-p); animation: listening 1.4s ease-out infinite; }
@keyframes listening {
  0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--p) 45%, transparent); }
  100% { box-shadow: 0 0 0 8px transparent; }
}
button:focus-visible { outline: 2px solid var(--p); outline-offset: 2px; }
.header button:focus-visible { outline-color: var(--p); }

@media (max-width: 575px) {
  .root { right: 12px; }
  .root[dir="rtl"] { left: 12px; }
  .panel { width: 100vw; border: 0; }
}
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation: none !important; transition: none !important; }
}
`;
