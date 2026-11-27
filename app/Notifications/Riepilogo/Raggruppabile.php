<?php

namespace App\Notifications\Riepilogo;

/** Notifica che può confluire in un riepilogo (ora | giorno) invece di partire subito. */
interface Raggruppabile
{
    /** Chiave di `App\Support\Avvisi\Categorie`. */
    public function categoriaRiepilogo(): string;

    public function segnalazioneRiepilogo(): int;

    /** Riga di testo della notifica nel riepilogo. */
    public function rigaRiepilogo(object $notifiable): string;

    /** Parola breve per la riga multi-motivo («Assegnata a te», «Scaduta», «Nuova nota»…). */
    public function motivoRiepilogo(): string;

    /** Al momento dell'invio il motivo dell'avviso vale ancora per questo destinatario? */
    public function ancoraValida(object $notifiable): bool;
}
