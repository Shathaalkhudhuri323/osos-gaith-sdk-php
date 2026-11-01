/**
 * Dictation through the browser's own speech recognition (Web Speech API).
 * Chrome, Edge and Safari have it; Firefox does not, so callers hide the mic there.
 * The browser vendor's speech service does the recognition: audio never reaches the host or GAITH.
 */
type Recognition = any; // not in TypeScript's DOM types yet

function recognitionClass(): (new () => Recognition) | null {
  const w = window as any;
  return w.SpeechRecognition || w.webkitSpeechRecognition || null;
}

export function voiceSupported(): boolean {
  return typeof window !== 'undefined' && !!recognitionClass();
}

export interface DictationHandlers {
  /** Everything heard so far: the settled words plus the current best guess, updated as the user speaks. */
  onText(text: string): void;
  /** Recognition stopped; `error` is the browser's code (e.g. 'not-allowed') when it failed. */
  onEnd(error?: string): void;
}

export interface Dictation {
  /** Stops listening; words already spoken still arrive. */
  stop(): void;
  /** Stops listening and drops anything not delivered yet. */
  abort(): void;
}

export function startDictation(lang: string, handlers: DictationHandlers): Dictation | null {
  const Ctor = recognitionClass();
  if (!Ctor) { return null; }

  const rec = new Ctor();
  rec.lang = lang;
  rec.continuous = true; // keep listening through pauses until the user stops
  rec.interimResults = true; // words appear while they are spoken, not only at the end
  let error: string | undefined;

  rec.onresult = (event: any) => {
    let text = '';
    for (let i = 0; i < event.results.length; i++) {
      text += event.results[i][0].transcript;
    }
    handlers.onText(text);
  };
  rec.onerror = (event: any) => {
    // Silence and our own abort() are normal endings, not failures.
    if (event.error !== 'aborted' && event.error !== 'no-speech') { error = event.error; }
  };
  rec.onend = () => handlers.onEnd(error);

  try {
    rec.start();
  } catch (e) {
    return null;
  }
  return { stop: () => rec.stop(), abort: () => rec.abort() };
}
