import { GaithChatElement } from './element';

// Guarded so loading the script twice (e.g. two bundles on one page) is harmless.
if (!customElements.get('gaith-chat')) {
  customElements.define('gaith-chat', GaithChatElement);
}

export { GaithChatElement };
