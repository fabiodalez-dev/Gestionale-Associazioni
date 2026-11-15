<?php

namespace App\Support;

/**
 * Rende inerte nel Markdown un testo che arriva da fuori (per esempio l'email
 * digitata in un login fallito) prima di metterlo in una riga di notifica:
 * i template mail di Laravel interpretano il Markdown e senza questo un
 * valore come "[testo](https://sito)" diventerebbe un link nella mail.
 */
final class TestoMarkdown
{
    public static function neutralizza(string $testo): string
    {
        $testo = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $testo) ?? '';

        return (string) preg_replace('/([\\\\`*_{}\[\]()<>#+\-.!|~&])/u', '\\\\$1', $testo);
    }
}
