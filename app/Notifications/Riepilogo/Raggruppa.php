<?php

namespace App\Notifications\Riepilogo;

/** Marca la notifica generata dal riepilogo stesso: i canali la inviano subito, senza trattenerla. */
trait Raggruppa
{
    public bool $daRiepilogo = false;
}
