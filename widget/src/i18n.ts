export type Lang = 'en' | 'ar';

export interface Strings {
  chat: string;
  welcome: string;
  online: string;
  thinking: string;
  unavailable: string;
  placeholder: string;
  poweredBy: string;
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
  },
  ar: {
    chat: 'محادثة',
    welcome: 'كيف يمكنني مساعدتك اليوم؟',
    online: 'متصل',
    thinking: 'يكتب...',
    unavailable: 'غير متاح',
    placeholder: 'مثال: «اعرض الموافقات المعلقة»',
    poweredBy: 'مدعوم من GAITH',
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
