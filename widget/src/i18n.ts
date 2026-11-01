export type Lang = 'en' | 'ar';

export interface Strings {
  chat: string;
  welcome: string;
  online: string;
  thinking: string;
  unavailable: string;
  placeholder: string;
  poweredBy: string;
  /** The menu at the top left; will open the list of past chats. */
  history: string;
  /** The lock's hover card; {name} is replaced by protectionName (a link to the policy when set). */
  protection: string;
  protectionName: string;
  voice: string;
  stopVoice: string;
  listening: string;
  /** The browser or the user refused the microphone. */
  micBlocked: string;
  voiceFailed: string;
  send: string;
  reset: string;
  close: string;
  open: string;
  error: string;
  blocked: string;
  limit: string;
  working: string;
  download: string;
  openFile: string;
  fileExpired: string;
  fileUnavailable: string;
  attach: string;
  remove: string;
  uploading: string;
  uploadFailed: string;
  fileTooLarge: string;
  fileTypeNotAllowed: string;
  /** GAITH could not use the file: corrupt, password-protected, content not matching its type. */
  fileRejected: string;
  tooManyFiles: string;
  dropFiles: string;
  /** Sent as the message text when the user sends files without typing anything. */
  attachmentOnly: string;
  /** The button that opens the link to connect an account; {server} is the service name. */
  connect: string;
  /** Used for {server} when GAITH did not say which service. */
  connectFallback: string;
  /** Sends the question again, after the user has connected. */
  tryAgain: string;
}

const STRINGS: Record<Lang, Strings> = {
  en: {
    chat: 'Chat',
    welcome: 'How can I help you today?',
    online: 'Online',
    thinking: 'Typing...',
    unavailable: 'Unavailable',
    placeholder: 'Example: “Show my pending approvals”',
    poweredBy: 'Powered by GAITH',
    history: 'Chat history',
    protection: '{name} applies to this chat.',
    protectionName: 'GAITH data protection',
    voice: 'Voice input',
    stopVoice: 'Stop voice input',
    listening: 'Listening...',
    micBlocked: 'Microphone access is blocked',
    voiceFailed: 'Voice input is not available',
    send: 'Send',
    reset: 'New conversation',
    close: 'Close',
    open: 'Open chat',
    error: 'Sorry, something went wrong. Please try again.',
    blocked: 'Sorry, I can\'t help with that request.',
    limit: 'The reply was cut short because a limit was reached.',
    working: 'Working...',
    download: 'Download',
    openFile: 'Open',
    fileExpired: 'This file has expired',
    fileUnavailable: 'File unavailable',
    attach: 'Attach files',
    remove: 'Remove',
    uploading: 'Uploading...',
    uploadFailed: 'Upload failed',
    fileTooLarge: 'Too large',
    fileTypeNotAllowed: 'Type not supported',
    fileRejected: 'Couldn\'t read this file',
    tooManyFiles: 'Too many files',
    dropFiles: 'Drop files to attach',
    attachmentOnly: 'Please take a look at the attached file(s).',
    connect: 'Connect to {server}',
    connectFallback: 'your account',
    tryAgain: 'Try again',
  },
  ar: {
    chat: 'محادثة',
    welcome: 'كيف يمكنني مساعدتك اليوم؟',
    online: 'متصل',
    thinking: 'يكتب...',
    unavailable: 'غير متاح',
    placeholder: 'مثال: «اعرض الموافقات المعلقة»',
    poweredBy: 'مدعوم من GAITH',
    history: 'سجل المحادثات',
    protection: 'تنطبق {name} على هذه المحادثة.',
    protectionName: 'حماية بيانات GAITH',
    voice: 'الإدخال الصوتي',
    stopVoice: 'إيقاف الإدخال الصوتي',
    listening: 'جارٍ الاستماع...',
    micBlocked: 'تم حظر الوصول إلى الميكروفون',
    voiceFailed: 'الإدخال الصوتي غير متاح',
    send: 'إرسال',
    reset: 'محادثة جديدة',
    close: 'إغلاق',
    open: 'فتح المحادثة',
    error: 'عذرًا، حدث خطأ. يرجى المحاولة مرة أخرى.',
    blocked: 'عذرًا، لا يمكنني المساعدة في هذا الطلب.',
    limit: 'تم اختصار الرد بسبب الوصول إلى حد معين.',
    working: 'جارٍ العمل...',
    download: 'تنزيل',
    openFile: 'فتح',
    fileExpired: 'انتهت صلاحية هذا الملف',
    fileUnavailable: 'الملف غير متاح',
    attach: 'إرفاق ملفات',
    remove: 'إزالة',
    uploading: 'جارٍ الرفع...',
    uploadFailed: 'فشل الرفع',
    fileTooLarge: 'الحجم كبير جدًا',
    fileTypeNotAllowed: 'نوع غير مدعوم',
    fileRejected: 'تعذّرت قراءة هذا الملف',
    tooManyFiles: 'عدد الملفات كبير',
    dropFiles: 'أفلت الملفات لإرفاقها',
    attachmentOnly: 'يرجى الاطلاع على الملف (الملفات) المرفقة.',
    connect: 'الاتصال بـ {server}',
    connectFallback: 'حسابك',
    tryAgain: 'حاول مرة أخرى',
  },
};

export function normalizeLang(value: string | null | undefined): Lang {
  return value && value.toLowerCase().indexOf('ar') === 0 ? 'ar' : 'en';
}

export function strings(lang: Lang): Strings {
  return STRINGS[lang];
}

/** Picks the host-configured text for a language, falling back to English. */
export function pick<T>(value: Partial<Record<Lang, T>> | undefined, lang: Lang): T | undefined {
  if (!value) { return undefined; }
  return value[lang] !== undefined ? value[lang] : value.en;
}
