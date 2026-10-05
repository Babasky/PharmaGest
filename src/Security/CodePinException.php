<?php

namespace App\Security;

/**
 * Code PIN refusé (faux, absent, ou trop d'essais) : le message s'affiche tel quel.
 */
final class CodePinException extends \RuntimeException
{
}
