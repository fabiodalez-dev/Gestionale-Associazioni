<?php

namespace App\Formello\Widgets;

use App\Support\PasswordPolicy;
use Metalogico\Formello\Widgets\BaseWidget;

/**
 * Widget Formello "password": campo password con pulsante mostra/nascondi e,
 * se richiesto, indicatore di robustezza zxcvbn lato client che rispecchia
 * PasswordPolicy (public/assets/vendor/zxcvbn/password-strength.min.js).
 *
 * Opzioni (FormelloField::extra):
 * - 'misuratore' (bool, default true): mostra l'indicatore di robustezza;
 * - 'campi_utente' (list<string>): id dei campi del form con dati personali (nome, email…);
 * - 'dati_utente' (list<string>): dati personali già noti (utente invitato, account esistente);
 * - 'autocomplete' (string, default new-password).
 *
 * Il valore non viene mai ripopolato nel campo.
 */
class PasswordWidget extends BaseWidget
{
    public function getWidgetName(): string
    {
        return 'password';
    }

    public function getTemplate(): string
    {
        return 'formello.widgets.password';
    }

    public function getViewData($name, $value, array $fieldConfig, $errors = null): array
    {
        $misuratore = (bool) ($fieldConfig['misuratore'] ?? true);
        $id = $fieldConfig['attributes']['id'] ?? $name;

        $attributi = array_merge($fieldConfig['attributes'] ?? [], [
            'id' => $id,
            'autocomplete' => $fieldConfig['autocomplete'] ?? 'new-password',
            'maxlength' => PasswordPolicy::MAX_LENGTH,
        ]);

        if ($misuratore) {
            $attributi += [
                'data-password-strength' => true,
                'data-min-score' => (string) config('zxcvbn.min_score', 3),
                'data-min-length' => (string) PasswordPolicy::MIN_LENGTH,
                'data-password-inputs' => implode(',', array_map(fn (string $c) => '#'.$c, $fieldConfig['campi_utente'] ?? [])),
                'data-password-dati' => json_encode(array_values(array_filter($fieldConfig['dati_utente'] ?? [])), JSON_UNESCAPED_UNICODE),
            ];
        }

        $fieldConfig['attributes'] = $attributi;

        return [
            'name' => $name,
            'label' => $fieldConfig['label'] ?? null,
            'config' => $fieldConfig,
            'errors' => $errors,
            'misuratore' => $misuratore,
        ];
    }
}
