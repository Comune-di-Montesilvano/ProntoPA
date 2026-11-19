import './bootstrap';

import Alpine from 'alpinejs';
import { avviaAnteprime } from './anteprima';
import { avviaUltimaAperta } from './ultima-aperta';
import { avviaGalleria } from './galleria';

window.Alpine = Alpine;

Alpine.start();

avviaAnteprime();
avviaUltimaAperta();
avviaGalleria();
