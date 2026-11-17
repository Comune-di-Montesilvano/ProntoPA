import './bootstrap';

import Alpine from 'alpinejs';
import { avviaAnteprime } from './anteprima';
import { avviaUltimaAperta } from './ultima-aperta';

window.Alpine = Alpine;

Alpine.start();

avviaAnteprime();
avviaUltimaAperta();
