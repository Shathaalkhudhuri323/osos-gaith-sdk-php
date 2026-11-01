import { build } from 'esbuild';

// One self-contained file the host loads with a <script> tag. ES2017 so it runs in
// every evergreen browser; hosts' own build tools (e.g. Angular 5 / TS 2.9) never
// compile it, which is what lets old frontends use it.
await build({
  entryPoints: ['src/index.ts'],
  outfile: '../dist/gaith-chat.js',
  bundle: true,
  format: 'iife',
  target: 'es2017',
  minify: true,
  sourcemap: true,
  legalComments: 'linked',
  // The launcher logo ships inside the script, so hosts still deploy one file.
  loader: { '.gif': 'dataurl' },
  logLevel: 'info',
});
