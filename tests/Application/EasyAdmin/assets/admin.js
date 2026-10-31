// The EasyAdmin application's page script: the bundle's entry point, then the rich-text editors of the
// form (and of collection rows a peer adds, through the bundle's row initializers).
import '@janalis/collaborate/easyadmin.js';
import { startEditors } from 'editors';

startEditors();
