<?php

namespace App\Http\Requests\Quote;

/**
 * Stesse regole della creazione (parità legacy: il form di modifica
 * riusa gli stessi campi del form di creazione).
 */
class UpdateQuotaRequest extends StoreQuotaRequest {}
