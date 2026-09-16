import { cpSync, mkdirSync, rmSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(fileURLToPath(new URL('.', import.meta.url)), '..');
const dist = resolve(root, 'dist');

rmSync(dist, { recursive: true, force: true });
mkdirSync(resolve(dist, 'assets'), { recursive: true });
cpSync(resolve(root, 'index.html'), resolve(dist, 'index.html'));
cpSync(resolve(root, 'public'), dist, { recursive: true });
cpSync(resolve(root, 'src/styles.css'), resolve(dist, 'assets/styles.css'));
