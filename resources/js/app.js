import './bootstrap';

import Alpine from 'alpinejs';
import { avviaAnteprime } from './anteprima';
import { avviaUltimaAperta } from './ultima-aperta';
import { avviaGalleria } from './galleria';
import { avviaFoto } from './foto';

window.Alpine = Alpine;
avviaFoto(Alpine);

Alpine.start();

avviaAnteprime();
avviaUltimaAperta();
avviaGalleria();
