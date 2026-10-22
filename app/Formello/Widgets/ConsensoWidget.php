<?php

namespace App\Formello\Widgets;

use Metalogico\Formello\Widgets\BaseWidget;

/**
 * Widget Formello "consenso": singola casella (privacy, termini) che invia 1 se spuntata.
 *
 * Opzioni (FormelloField::extra):
 * - 'link_url' / 'link_testo': link mostrato dopo l'etichetta (es. informativa privacy);
 *   l'etichetta resta testo semplice, quindi non serve HTML non escapato;
 * - 'interruttore' (bool): stile switch invece di casella;
 * - 'invia_zero' (bool, default true): campo nascosto con 0 quando non spuntata. Da disattivare
 *   se la regola di validazione controlla la sola presenza del campo (es. required_without).
 */
class ConsensoWidget extends BaseWidget
{
    public function getWidgetName(): string
    {
        return 'consenso';
    }

    public function getTemplate(): string
    {
        return 'formello.widgets.consenso';
    }

    public function getViewData($name, $value, array $fieldConfig, $errors = null): array
    {
        $fieldConfig['attributes'] = array_merge($fieldConfig['attributes'] ?? [], [
            'id' => $fieldConfig['attributes']['id'] ?? $name,
        ]);

        return [
            'name' => $name,
            'label' => $fieldConfig['label'] ?? null,
            'checked' => filter_var(old($name, $value), FILTER_VALIDATE_BOOLEAN),
            'interruttore' => (bool) ($fieldConfig['interruttore'] ?? false),
            'inviaZero' => (bool) ($fieldConfig['invia_zero'] ?? true),
            'config' => $fieldConfig,
            'errors' => $errors,
        ];
    }
}
