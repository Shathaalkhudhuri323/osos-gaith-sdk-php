/** Images are bundled into gaith-chat.js as data URLs (see build.mjs). */
declare module '*.gif' {
  const url: string;
  export default url;
}
