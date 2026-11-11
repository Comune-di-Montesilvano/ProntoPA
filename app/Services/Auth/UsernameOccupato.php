<?php

namespace App\Services\Auth;

/** Lo sAMAccountName è già lo username di un altro account ProntoPA. */
final class UsernameOccupato extends \RuntimeException {}
