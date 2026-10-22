-- Gestione Associazioni — schema del database vuoto per l'installer web.
-- Generato da `php artisan installer:genera-schema` (server 9.6.0).
-- NON modificare a mano: rigenera il file dopo ogni nuova migration.
-- Formato: ogni istruzione termina con ";" a fine riga; i commenti iniziano con "--".

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `accessi`;
CREATE TABLE `accessi` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guard` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `autenticabile_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `autenticabile_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_tentata` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `esito` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `accessi_autenticabile_type_autenticabile_id_index` (`autenticabile_type`,`autenticabile_id`),
  KEY `idx_accessi_email_data` (`email_tentata`,`created_at`),
  KEY `idx_accessi_assoc_data` (`associazione_id`,`created_at`),
  KEY `accessi_associazione_id_index` (`associazione_id`),
  CONSTRAINT `accessi_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `accounts`;
CREATE TABLE `accounts` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cognome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attivo` tinyint(1) NOT NULL DEFAULT '1',
  `preferenze` json DEFAULT NULL,
  `privacy_accettata_at` datetime DEFAULT NULL,
  `richiesta_socio_at` datetime DEFAULT NULL,
  `ultimo_accesso_at` datetime DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `accounts_associazione_id_email_unique` (`associazione_id`,`email`),
  UNIQUE KEY `accounts_socio_id_unique` (`socio_id`),
  KEY `accounts_associazione_id_index` (`associazione_id`),
  CONSTRAINT `accounts_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `accounts_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `activity_log`;
CREATE TABLE `activity_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `log_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `causer_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `causer_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attribute_changes` json DEFAULT NULL,
  `properties` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subject` (`subject_type`,`subject_id`),
  KEY `causer` (`causer_type`,`causer_id`),
  KEY `activity_log_log_name_index` (`log_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `adempimenti`;
CREATE TABLE `adempimenti` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titolo` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` text COLLATE utf8mb4_unicode_ci,
  `mese_scadenza` tinyint unsigned NOT NULL,
  `attivo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_adempimenti_assoc_mese` (`associazione_id`,`mese_scadenza`),
  KEY `adempimenti_associazione_id_index` (`associazione_id`),
  CONSTRAINT `adempimenti_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `adempimenti_anni`;
CREATE TABLE `adempimenti_anni` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `adempimento_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `anno` smallint unsigned NOT NULL,
  `completato_at` datetime DEFAULT NULL,
  `completato_da` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_adempimenti_anni_anno` (`adempimento_id`,`anno`),
  KEY `adempimenti_anni_completato_da_foreign` (`completato_da`),
  KEY `adempimenti_anni_associazione_id_index` (`associazione_id`),
  CONSTRAINT `adempimenti_anni_adempimento_id_foreign` FOREIGN KEY (`adempimento_id`) REFERENCES `adempimenti` (`id`) ON DELETE CASCADE,
  CONSTRAINT `adempimenti_anni_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `adempimenti_anni_completato_da_foreign` FOREIGN KEY (`completato_da`) REFERENCES `utenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `annunci`;
CREATE TABLE `annunci` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titolo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'comunicazione',
  `corpo_html` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_assemblea` datetime DEFAULT NULL,
  `luogo_assemblea` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ordine_del_giorno` text COLLATE utf8mb4_unicode_ci,
  `evento_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `convocazione_inviata_at` timestamp NULL DEFAULT NULL,
  `pubblicato_dal` datetime DEFAULT NULL,
  `pubblicato_al` datetime DEFAULT NULL,
  `in_evidenza` tinyint(1) NOT NULL DEFAULT '0',
  `solo_soci` tinyint(1) NOT NULL DEFAULT '0',
  `creato_da` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_annunci_pubblicazione` (`associazione_id`,`pubblicato_dal`,`pubblicato_al`),
  KEY `annunci_creato_da_foreign` (`creato_da`),
  KEY `annunci_associazione_id_index` (`associazione_id`),
  KEY `annunci_evento_id_foreign` (`evento_id`),
  CONSTRAINT `annunci_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `annunci_creato_da_foreign` FOREIGN KEY (`creato_da`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `annunci_evento_id_foreign` FOREIGN KEY (`evento_id`) REFERENCES `eventi` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `api_keys`;
CREATE TABLE `api_keys` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `api_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `permessi` json NOT NULL,
  `attiva` tinyint(1) NOT NULL DEFAULT '1',
  `ultimo_utilizzo` datetime DEFAULT NULL,
  `scadenza` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_keys_api_key_unique` (`api_key`),
  KEY `api_keys_associazione_id_index` (`associazione_id`),
  CONSTRAINT `api_keys_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `associazioni`;
CREATE TABLE `associazioni` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `partita_iva` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `codice_fiscale` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `indirizzo` text COLLATE utf8mb4_unicode_ci,
  `citta` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provincia` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cap` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `costo_tessera` decimal(10,2) DEFAULT NULL,
  `logo_url` text COLLATE utf8mb4_unicode_ci,
  `attiva` tinyint(1) NOT NULL DEFAULT '1',
  `frontend_pubblico_attivo` tinyint(1) NOT NULL DEFAULT '0',
  `iscrizioni_socio_aperte` tinyint(1) NOT NULL DEFAULT '1',
  `modalita_iscrizione` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'preiscrizione',
  `iscrizione_form_config` json DEFAULT NULL,
  `promemoria_rinnovo_config` json DEFAULT NULL,
  `auguri_automatici` tinyint(1) NOT NULL DEFAULT '0',
  `descrizione_pubblica` text COLLATE utf8mb4_unicode_ci,
  `tipo_scadenza_default` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'solare',
  `giorni_notifica_scadenza` int NOT NULL DEFAULT '30',
  `template_email_scadenza` text COLLATE utf8mb4_unicode_ci,
  `privacy_policy_html` longtext COLLATE utf8mb4_unicode_ci,
  `campi_obbligatori_config` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `associazioni_email_unique` (`email`),
  UNIQUE KEY `associazioni_partita_iva_unique` (`partita_iva`),
  UNIQUE KEY `associazioni_codice_fiscale_unique` (`codice_fiscale`),
  UNIQUE KEY `associazioni_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `attivita_admin`;
CREATE TABLE `attivita_admin` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titolo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` text COLLATE utf8mb4_unicode_ci,
  `scadenza` date DEFAULT NULL,
  `assegnatario_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notabile_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notabile_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stato` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aperta',
  `completata_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attivita_admin_notabile_type_notabile_id_index` (`notabile_type`,`notabile_id`),
  KEY `idx_attivita_assoc_stato_scadenza` (`associazione_id`,`stato`,`scadenza`),
  KEY `attivita_admin_associazione_id_index` (`associazione_id`),
  KEY `attivita_admin_assegnatario_id_index` (`assegnatario_id`),
  CONSTRAINT `attivita_admin_assegnatario_id_foreign` FOREIGN KEY (`assegnatario_id`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attivita_admin_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `attrezzature`;
CREATE TABLE `attrezzature` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` text COLLATE utf8mb4_unicode_ci,
  `quantita` int unsigned NOT NULL DEFAULT '1',
  `valore_unitario` decimal(10,2) DEFAULT NULL,
  `stato` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'buono',
  `spazio_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ubicazione` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attrezzature_spazio_id_foreign` (`spazio_id`),
  KEY `attrezzature_associazione_id_index` (`associazione_id`),
  CONSTRAINT `attrezzature_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attrezzature_spazio_id_foreign` FOREIGN KEY (`spazio_id`) REFERENCES `spazi` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `cache`;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `cache_locks`;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `campi_personalizzati`;
CREATE TABLE `campi_personalizzati` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome_campo` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo_campo` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` text COLLATE utf8mb4_unicode_ci,
  `opzioni` text COLLATE utf8mb4_unicode_ci,
  `obbligatorio` tinyint(1) NOT NULL DEFAULT '0',
  `obbligatorio_preiscrizione` tinyint(1) NOT NULL DEFAULT '0',
  `ordine` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `campi_personalizzati_associazione_id_nome_campo_unique` (`associazione_id`,`nome_campo`),
  CONSTRAINT `campi_personalizzati_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `cariche`;
CREATE TABLE `cariche` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ruolo` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `inizio` date NOT NULL,
  `fine` date DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cariche_assoc_fine` (`associazione_id`,`fine`),
  KEY `cariche_socio_id_index` (`socio_id`),
  KEY `cariche_associazione_id_index` (`associazione_id`),
  CONSTRAINT `cariche_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cariche_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `categorie_movimento`;
CREATE TABLE `categorie_movimento` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categorie_movimento_nome_tipo` (`associazione_id`,`nome`,`tipo`),
  KEY `categorie_movimento_associazione_id_index` (`associazione_id`),
  CONSTRAINT `categorie_movimento_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `categorie_socio`;
CREATE TABLE `categorie_socio` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` text COLLATE utf8mb4_unicode_ci,
  `testo_tessera` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `categorie_socio_associazione_id_nome_unique` (`associazione_id`,`nome`),
  CONSTRAINT `categorie_socio_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `cessazioni`;
CREATE TABLE `cessazioni` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` date NOT NULL,
  `motivo` text COLLATE utf8mb4_unicode_ci,
  `delibera_numero` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delibera_data` date DEFAULT NULL,
  `registrata_da` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cessazioni_assoc_data` (`associazione_id`,`data`),
  KEY `cessazioni_socio_id_index` (`socio_id`),
  KEY `cessazioni_registrata_da_foreign` (`registrata_da`),
  KEY `cessazioni_associazione_id_index` (`associazione_id`),
  CONSTRAINT `cessazioni_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cessazioni_registrata_da_foreign` FOREIGN KEY (`registrata_da`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cessazioni_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `consensi`;
CREATE TABLE `consensi` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chiave` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `testo` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `accettato` tinyint(1) NOT NULL,
  `origine` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `consensi_associazione_id_index` (`associazione_id`),
  KEY `consensi_socio_id_index` (`socio_id`),
  CONSTRAINT `consensi_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `consensi_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `contatti_esterni`;
CREATE TABLE `contatti_esterni` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'altro',
  `nome` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `referente` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `tag` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_contatti_esterni_assoc_tipo` (`associazione_id`,`tipo`),
  KEY `contatti_esterni_associazione_id_index` (`associazione_id`),
  CONSTRAINT `contatti_esterni_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `crm_opportunita`;
CREATE TABLE `crm_opportunita` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pipeline` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fase` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `posizione` int unsigned NOT NULL DEFAULT '0',
  `titolo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `origine` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `valore` decimal(10,2) DEFAULT NULL,
  `prossima_azione_il` date DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `responsabile_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contatto_esterno_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `esito` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chiusa_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `crm_opp_colonna_idx` (`associazione_id`,`pipeline`,`fase`,`posizione`),
  KEY `crm_opportunita_responsabile_id_foreign` (`responsabile_id`),
  KEY `crm_opportunita_socio_id_foreign` (`socio_id`),
  KEY `crm_opportunita_contatto_esterno_id_foreign` (`contatto_esterno_id`),
  KEY `crm_opportunita_associazione_id_index` (`associazione_id`),
  CONSTRAINT `crm_opportunita_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `crm_opportunita_contatto_esterno_id_foreign` FOREIGN KEY (`contatto_esterno_id`) REFERENCES `contatti_esterni` (`id`) ON DELETE SET NULL,
  CONSTRAINT `crm_opportunita_responsabile_id_foreign` FOREIGN KEY (`responsabile_id`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `crm_opportunita_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `documenti`;
CREATE TABLE `documenti` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome_file` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `percorso_file` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` text COLLATE utf8mb4_unicode_ci,
  `categoria` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pubblico` tinyint(1) NOT NULL DEFAULT '0',
  `categoria_pubblica` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dimensione_kb` int DEFAULT NULL,
  `caricato_da` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `documenti_associazione_id_index` (`associazione_id`),
  KEY `documenti_caricato_da_index` (`caricato_da`),
  KEY `idx_documenti_assoc_created` (`associazione_id`,`created_at`),
  CONSTRAINT `documenti_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `documenti_caricato_da_foreign` FOREIGN KEY (`caricato_da`) REFERENCES `utenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `documenti_socio`;
CREATE TABLE `documenti_socio` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome_file` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `percorso_file` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` text COLLATE utf8mb4_unicode_ci,
  `tipo_documento` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_scadenza` date DEFAULT NULL,
  `caricato_da` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `documenti_socio_socio_id_index` (`socio_id`),
  KEY `documenti_socio_associazione_id_index` (`associazione_id`),
  KEY `documenti_socio_caricato_da_index` (`caricato_da`),
  CONSTRAINT `documenti_socio_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `documenti_socio_caricato_da_foreign` FOREIGN KEY (`caricato_da`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documenti_socio_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `donazioni`;
CREATE TABLE `donazioni` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `donatore_nome` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `donatore_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `importo` decimal(10,2) NOT NULL,
  `anonimo` tinyint(1) NOT NULL DEFAULT '0',
  `stato` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_attesa',
  `gateway` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transaction_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pagata_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_donazioni_assoc_stato` (`associazione_id`,`stato`),
  KEY `idx_donazioni_assoc_pagata` (`associazione_id`,`pagata_at`),
  KEY `donazioni_associazione_id_index` (`associazione_id`),
  CONSTRAINT `donazioni_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `email_log`;
CREATE TABLE `email_log` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `queue_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `subject` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_codice` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body_html` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `sent_by` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sent_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `email_log_associazione_id_index` (`associazione_id`),
  KEY `email_log_batch_id_index` (`batch_id`),
  KEY `email_log_socio_id_index` (`socio_id`),
  KEY `email_log_sent_by_index` (`sent_by`),
  KEY `email_log_sent_at_index` (`sent_at`),
  CONSTRAINT `email_log_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `email_log_sent_by_foreign` FOREIGN KEY (`sent_by`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `email_log_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `email_queue`;
CREATE TABLE `email_queue` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `subject` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body_html` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_codice` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `priority` tinyint NOT NULL DEFAULT '5',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `attempts` int NOT NULL DEFAULT '0',
  `max_attempts` int NOT NULL DEFAULT '3',
  `last_attempt_at` datetime DEFAULT NULL,
  `next_attempt_at` datetime DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_eq_status` (`status`,`created_at`),
  KEY `email_queue_associazione_id_index` (`associazione_id`),
  KEY `email_queue_batch_id_index` (`batch_id`),
  KEY `email_queue_socio_id_index` (`socio_id`),
  CONSTRAINT `email_queue_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `email_queue_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `email_templates`;
CREATE TABLE `email_templates` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `codice` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `oggetto` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `corpo_html` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `corpo_json` text COLLATE utf8mb4_unicode_ci,
  `attivo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_template` (`associazione_id`,`codice`),
  KEY `email_templates_associazione_id_index` (`associazione_id`),
  CONSTRAINT `email_templates_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `eventi`;
CREATE TABLE `eventi` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sede_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `titolo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` text COLLATE utf8mb4_unicode_ci,
  `data_evento` datetime NOT NULL,
  `data_fine` datetime DEFAULT NULL,
  `luogo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stato_pubblicazione` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bozza',
  `capienza` int unsigned DEFAULT NULL,
  `prezzo` decimal(10,2) NOT NULL DEFAULT '0.00',
  `budget` decimal(10,2) DEFAULT NULL,
  `iscrizioni_dal` datetime DEFAULT NULL,
  `iscrizioni_al` datetime DEFAULT NULL,
  `iscrizioni_aperte` tinyint(1) NOT NULL DEFAULT '1',
  `richiede_approvazione` tinyint(1) NOT NULL DEFAULT '0',
  `max_posti_per_prenotazione` tinyint unsigned NOT NULL DEFAULT '4',
  `lista_attesa_attiva` tinyint(1) NOT NULL DEFAULT '1',
  `annullamento_entro_ore` smallint unsigned NOT NULL DEFAULT '24',
  `immagine_url` text COLLATE utf8mb4_unicode_ci,
  `serie_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `solo_soci` tinyint(1) NOT NULL DEFAULT '0',
  `spazio_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `progetto_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `galleria_pubblica` tinyint(1) NOT NULL DEFAULT '0',
  `google_event_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `eventi_associazione_id_index` (`associazione_id`),
  KEY `eventi_assoc_stato_data_index` (`associazione_id`,`stato_pubblicazione`,`data_evento`),
  KEY `eventi_serie_id_index` (`serie_id`),
  KEY `eventi_spazio_id_foreign` (`spazio_id`),
  KEY `eventi_progetto_id_foreign` (`progetto_id`),
  KEY `eventi_sede_id_index` (`sede_id`),
  CONSTRAINT `eventi_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `eventi_progetto_id_foreign` FOREIGN KEY (`progetto_id`) REFERENCES `progetti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `eventi_sede_id_foreign` FOREIGN KEY (`sede_id`) REFERENCES `sedi` (`id`) ON DELETE SET NULL,
  CONSTRAINT `eventi_spazio_id_foreign` FOREIGN KEY (`spazio_id`) REFERENCES `spazi` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `eventi_partecipanti`;
CREATE TABLE `eventi_partecipanti` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `evento_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stato_partecipazione` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Non Partecipa',
  `data_conferma` timestamp NULL DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_partecipation` (`evento_id`,`socio_id`),
  KEY `eventi_partecipanti_associazione_id_index` (`associazione_id`),
  KEY `eventi_partecipanti_socio_id_index` (`socio_id`),
  CONSTRAINT `eventi_partecipanti_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `eventi_partecipanti_evento_id_foreign` FOREIGN KEY (`evento_id`) REFERENCES `eventi` (`id`) ON DELETE CASCADE,
  CONSTRAINT `eventi_partecipanti_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `failed_jobs`;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `feedback_eventi`;
CREATE TABLE `feedback_eventi` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `evento_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenotazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `voto` tinyint unsigned DEFAULT NULL,
  `commento` text COLLATE utf8mb4_unicode_ci,
  `token` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `inviato_at` datetime DEFAULT NULL,
  `compilato_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `feedback_eventi_prenotazione_id_unique` (`prenotazione_id`),
  UNIQUE KEY `feedback_eventi_token_unique` (`token`),
  KEY `idx_feedback_evento_compilato` (`evento_id`,`compilato_at`),
  KEY `feedback_eventi_associazione_id_index` (`associazione_id`),
  CONSTRAINT `feedback_eventi_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `feedback_eventi_evento_id_foreign` FOREIGN KEY (`evento_id`) REFERENCES `eventi` (`id`) ON DELETE CASCADE,
  CONSTRAINT `feedback_eventi_prenotazione_id_foreign` FOREIGN KEY (`prenotazione_id`) REFERENCES `prenotazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `gruppi_dinamici`;
CREATE TABLE `gruppi_dinamici` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome_gruppo` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` text COLLATE utf8mb4_unicode_ci,
  `filtri_json` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gruppi_dinamici_associazione_id_nome_gruppo_unique` (`associazione_id`,`nome_gruppo`),
  CONSTRAINT `gruppi_dinamici_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `health_check_result_history_items`;
CREATE TABLE `health_check_result_history_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `check_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `check_label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notification_message` text COLLATE utf8mb4_unicode_ci,
  `short_summary` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta` json NOT NULL,
  `ended_at` timestamp NOT NULL,
  `batch` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `health_check_result_history_items_created_at_index` (`created_at`),
  KEY `health_check_result_history_items_batch_index` (`batch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `job_batches`;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `jobs`;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` smallint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `mansioni`;
CREATE TABLE `mansioni` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mansioni_assoc_nome` (`associazione_id`,`nome`),
  KEY `mansioni_associazione_id_index` (`associazione_id`),
  CONSTRAINT `mansioni_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `media`;
CREATE TABLE `media` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `collection_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disk` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `conversions_disk` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size` bigint unsigned NOT NULL,
  `manipulations` json NOT NULL,
  `custom_properties` json NOT NULL,
  `generated_conversions` json NOT NULL,
  `responsive_images` json NOT NULL,
  `order_column` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `media_uuid_unique` (`uuid`),
  KEY `media_model_type_model_id_index` (`model_type`,`model_id`),
  KEY `media_order_column_index` (`order_column`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `migrations`;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `milestone_progetto`;
CREATE TABLE `milestone_progetto` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `progetto_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titolo` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scadenza` date DEFAULT NULL,
  `completata_at` datetime DEFAULT NULL,
  `ordine` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_milestone_progetto_ordine` (`progetto_id`,`ordine`),
  KEY `milestone_progetto_associazione_id_index` (`associazione_id`),
  CONSTRAINT `milestone_progetto_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `milestone_progetto_progetto_id_foreign` FOREIGN KEY (`progetto_id`) REFERENCES `progetti` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `model_has_permissions`;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint unsigned NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `model_has_roles`;
CREATE TABLE `model_has_roles` (
  `role_id` bigint unsigned NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `monitored_scheduled_task_log_items`;
CREATE TABLE `monitored_scheduled_task_log_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `monitored_scheduled_task_id` bigint unsigned NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_scheduled_task_id` (`monitored_scheduled_task_id`),
  CONSTRAINT `fk_scheduled_task_id` FOREIGN KEY (`monitored_scheduled_task_id`) REFERENCES `monitored_scheduled_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `monitored_scheduled_tasks`;
CREATE TABLE `monitored_scheduled_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cron_expression` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `timezone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ping_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_started_at` datetime DEFAULT NULL,
  `last_finished_at` datetime DEFAULT NULL,
  `last_failed_at` datetime DEFAULT NULL,
  `last_skipped_at` datetime DEFAULT NULL,
  `registered_on_oh_dear_at` datetime DEFAULT NULL,
  `last_pinged_at` datetime DEFAULT NULL,
  `grace_time_in_minutes` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `movimenti`;
CREATE TABLE `movimenti` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sede_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `importo` decimal(10,2) NOT NULL,
  `data` date NOT NULL,
  `categoria_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `progetto_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descrizione` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metodo` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `origine_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `origine_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registrato_da` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_movimenti_origine` (`origine_type`,`origine_id`),
  KEY `movimenti_origine_type_origine_id_index` (`origine_type`,`origine_id`),
  KEY `idx_movimenti_assoc_data` (`associazione_id`,`data`),
  KEY `idx_movimenti_assoc_tipo_data` (`associazione_id`,`tipo`,`data`),
  KEY `movimenti_registrato_da_foreign` (`registrato_da`),
  KEY `movimenti_associazione_id_index` (`associazione_id`),
  KEY `movimenti_categoria_id_index` (`categoria_id`),
  KEY `movimenti_progetto_id_index` (`progetto_id`),
  KEY `movimenti_sede_id_index` (`sede_id`),
  CONSTRAINT `movimenti_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `movimenti_categoria_id_foreign` FOREIGN KEY (`categoria_id`) REFERENCES `categorie_movimento` (`id`) ON DELETE SET NULL,
  CONSTRAINT `movimenti_progetto_id_foreign` FOREIGN KEY (`progetto_id`) REFERENCES `progetti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `movimenti_registrato_da_foreign` FOREIGN KEY (`registrato_da`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `movimenti_sede_id_foreign` FOREIGN KEY (`sede_id`) REFERENCES `sedi` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `newsletter_inviate`;
CREATE TABLE `newsletter_inviate` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titolo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `annunci_ids` json NOT NULL,
  `inviata_a` int unsigned NOT NULL DEFAULT '0',
  `inviata_at` datetime DEFAULT NULL,
  `inviata_da` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_newsletter_assoc_data` (`associazione_id`,`inviata_at`),
  KEY `newsletter_inviate_inviata_da_foreign` (`inviata_da`),
  KEY `newsletter_inviate_associazione_id_index` (`associazione_id`),
  CONSTRAINT `newsletter_inviate_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsletter_inviate_inviata_da_foreign` FOREIGN KEY (`inviata_da`) REFERENCES `utenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `note_socio`;
CREATE TABLE `note_socio` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `autore_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `testo` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `fissata` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_note_socio_socio_fissata` (`socio_id`,`fissata`),
  KEY `note_socio_autore_id_foreign` (`autore_id`),
  KEY `note_socio_associazione_id_index` (`associazione_id`),
  CONSTRAINT `note_socio_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `note_socio_autore_id_foreign` FOREIGN KEY (`autore_id`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `note_socio_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `nuclei`;
CREATE TABLE `nuclei` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `capofamiglia_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `nuclei_capofamiglia_id_foreign` (`capofamiglia_id`),
  KEY `nuclei_associazione_id_index` (`associazione_id`),
  CONSTRAINT `nuclei_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `nuclei_capofamiglia_id_foreign` FOREIGN KEY (`capofamiglia_id`) REFERENCES `soci` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `password_reset_tokens`;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `payment_gateway_settings`;
CREATE TABLE `payment_gateway_settings` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stripe_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `stripe_publishable_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `stripe_secret_key_encrypted` text COLLATE utf8mb4_unicode_ci,
  `stripe_webhook_secret_encrypted` text COLLATE utf8mb4_unicode_ci,
  `paypal_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `paypal_client_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `paypal_client_secret_encrypted` text COLLATE utf8mb4_unicode_ci,
  `paypal_mode` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sandbox',
  `paypal_webhook_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `auto_attivazione_pagamento` tinyint(1) NOT NULL DEFAULT '1',
  `valuta` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'EUR',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pg_assoc` (`associazione_id`),
  CONSTRAINT `payment_gateway_settings_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `permissions`;
CREATE TABLE `permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `personal_access_tokens`;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `prenotazioni`;
CREATE TABLE `prenotazioni` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `evento_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `codice` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `qr_token` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stato` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `posti` tinyint unsigned NOT NULL DEFAULT '1',
  `prezzo_unitario` decimal(10,2) NOT NULL DEFAULT '0.00',
  `importo_totale` decimal(10,2) NOT NULL DEFAULT '0.00',
  `gateway` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gateway_transaction_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pagata_at` datetime DEFAULT NULL,
  `scadenza_pagamento_at` datetime DEFAULT NULL,
  `approvata_at` datetime DEFAULT NULL,
  `approvata_da` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `motivo_rifiuto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `promossa_at` datetime DEFAULT NULL,
  `annullata_at` datetime DEFAULT NULL,
  `motivo_annullamento` text COLLATE utf8mb4_unicode_ci,
  `checkin_at` datetime DEFAULT NULL,
  `checkin_utente_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prenotazioni_associazione_id_codice_unique` (`associazione_id`,`codice`),
  UNIQUE KEY `prenotazioni_qr_token_unique` (`qr_token`),
  KEY `prenotazioni_evento_id_stato_index` (`evento_id`,`stato`),
  KEY `prenotazioni_account_id_created_at_index` (`account_id`,`created_at`),
  KEY `prenotazioni_stato_scadenza_pagamento_at_index` (`stato`,`scadenza_pagamento_at`),
  KEY `prenotazioni_checkin_utente_id_foreign` (`checkin_utente_id`),
  KEY `prenotazioni_associazione_id_index` (`associazione_id`),
  KEY `prenotazioni_approvata_da_foreign` (`approvata_da`),
  CONSTRAINT `prenotazioni_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `prenotazioni_approvata_da_foreign` FOREIGN KEY (`approvata_da`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `prenotazioni_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prenotazioni_checkin_utente_id_foreign` FOREIGN KEY (`checkin_utente_id`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `prenotazioni_evento_id_foreign` FOREIGN KEY (`evento_id`) REFERENCES `eventi` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `prenotazioni_spazi`;
CREATE TABLE `prenotazioni_spazi` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `spazio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titolo` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `dal` datetime NOT NULL,
  `al` datetime NOT NULL,
  `richiedente_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_prenotazioni_spazi_periodo` (`spazio_id`,`dal`,`al`),
  KEY `prenotazioni_spazi_richiedente_id_foreign` (`richiedente_id`),
  KEY `prenotazioni_spazi_associazione_id_index` (`associazione_id`),
  CONSTRAINT `prenotazioni_spazi_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prenotazioni_spazi_richiedente_id_foreign` FOREIGN KEY (`richiedente_id`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `prenotazioni_spazi_spazio_id_foreign` FOREIGN KEY (`spazio_id`) REFERENCES `spazi` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `prestiti_attrezzature`;
CREATE TABLE `prestiti_attrezzature` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attrezzatura_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantita` int unsigned NOT NULL DEFAULT '1',
  `dal` date NOT NULL,
  `restituzione_prevista` date NOT NULL,
  `restituito_at` datetime DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_prestiti_attrezzatura_attivi` (`attrezzatura_id`,`restituito_at`),
  KEY `idx_prestiti_socio_data` (`socio_id`,`dal`),
  KEY `prestiti_attrezzature_associazione_id_index` (`associazione_id`),
  CONSTRAINT `prestiti_attrezzature_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prestiti_attrezzature_attrezzatura_id_foreign` FOREIGN KEY (`attrezzatura_id`) REFERENCES `attrezzature` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prestiti_attrezzature_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `progetti`;
CREATE TABLE `progetti` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` text COLLATE utf8mb4_unicode_ci,
  `dal` date DEFAULT NULL,
  `al` date DEFAULT NULL,
  `responsabile_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stato` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bozza',
  `budget` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_progetti_assoc_stato` (`associazione_id`,`stato`),
  KEY `progetti_responsabile_id_foreign` (`responsabile_id`),
  KEY `progetti_associazione_id_index` (`associazione_id`),
  CONSTRAINT `progetti_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `progetti_responsabile_id_foreign` FOREIGN KEY (`responsabile_id`) REFERENCES `utenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `promemoria_inviati`;
CREATE TABLE `promemoria_inviati` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tessera_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `riferimento_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `step` int unsigned NOT NULL,
  `inviato_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_promemoria_socio_tessera_tipo_step` (`socio_id`,`tessera_id`,`tipo`,`step`),
  KEY `idx_promemoria_assoc_tipo_data` (`associazione_id`,`tipo`,`inviato_at`),
  KEY `promemoria_inviati_tessera_id_foreign` (`tessera_id`),
  KEY `promemoria_inviati_associazione_id_index` (`associazione_id`),
  KEY `idx_promemoria_assoc_tipo_rif` (`associazione_id`,`tipo`,`riferimento_id`),
  CONSTRAINT `promemoria_inviati_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `promemoria_inviati_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE CASCADE,
  CONSTRAINT `promemoria_inviati_tessera_id_foreign` FOREIGN KEY (`tessera_id`) REFERENCES `tessere` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `quote`;
CREATE TABLE `quote` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `anno` int NOT NULL,
  `importo` decimal(10,2) NOT NULL,
  `data_scadenza` date NOT NULL,
  `data_pagamento` date DEFAULT NULL,
  `stato` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Da Pagare',
  `tipo` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `metodo_pagamento` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gateway` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gateway_transaction_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_token` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_token_expires` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quote_socio_id_index` (`socio_id`),
  KEY `quote_associazione_id_index` (`associazione_id`),
  KEY `quote_payment_token_index` (`payment_token`),
  CONSTRAINT `quote_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quote_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `ricevute`;
CREATE TABLE `ricevute` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quota_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `numero` int NOT NULL,
  `anno` int NOT NULL,
  `importo` decimal(10,2) NOT NULL,
  `emessa_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ricevute_assoc_anno_numero` (`associazione_id`,`anno`,`numero`),
  UNIQUE KEY `ricevute_quota_id_unique` (`quota_id`),
  KEY `ricevute_associazione_id_index` (`associazione_id`),
  CONSTRAINT `ricevute_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ricevute_quota_id_foreign` FOREIGN KEY (`quota_id`) REFERENCES `quote` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `richieste_iscrizione`;
CREATE TABLE `richieste_iscrizione` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stato` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_attesa',
  `dati_form` json NOT NULL,
  `motivo_rifiuto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gestita_da` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gestita_at` datetime DEFAULT NULL,
  `delibera_numero` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delibera_data` date DEFAULT NULL,
  `referral_codice` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `richieste_iscrizione_associazione_id_stato_index` (`associazione_id`,`stato`),
  KEY `richieste_iscrizione_account_id_stato_index` (`account_id`,`stato`),
  KEY `richieste_iscrizione_socio_id_foreign` (`socio_id`),
  KEY `richieste_iscrizione_gestita_da_foreign` (`gestita_da`),
  KEY `richieste_iscrizione_referral_codice_index` (`referral_codice`),
  CONSTRAINT `richieste_iscrizione_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `richieste_iscrizione_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `richieste_iscrizione_gestita_da_foreign` FOREIGN KEY (`gestita_da`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `richieste_iscrizione_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `richieste_modifica_dati`;
CREATE TABLE `richieste_modifica_dati` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `proposte` json NOT NULL,
  `stato` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_attesa',
  `origine_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `origine_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gestita_da` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gestita_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `richieste_modifica_dati_origine_type_origine_id_index` (`origine_type`,`origine_id`),
  KEY `idx_richieste_modifica_stato` (`associazione_id`,`stato`),
  KEY `richieste_modifica_dati_socio_id_foreign` (`socio_id`),
  KEY `richieste_modifica_dati_gestita_da_foreign` (`gestita_da`),
  KEY `richieste_modifica_dati_associazione_id_index` (`associazione_id`),
  CONSTRAINT `richieste_modifica_dati_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `richieste_modifica_dati_gestita_da_foreign` FOREIGN KEY (`gestita_da`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `richieste_modifica_dati_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `richieste_registrazione`;
CREATE TABLE `richieste_registrazione` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stato` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_attesa',
  `nome` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `codice_fiscale` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `partita_iva` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `citta` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provincia` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero_soci_stimato` int unsigned DEFAULT NULL,
  `messaggio` text COLLATE utf8mb4_unicode_ci,
  `admin_nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `admin_cognome` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admin_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `admin_password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `privacy_accettata_at` timestamp NULL DEFAULT NULL,
  `email_verificata_at` timestamp NULL DEFAULT NULL,
  `motivo_rifiuto` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gestita_da` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gestita_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `richieste_registrazione_gestita_da_foreign` (`gestita_da`),
  KEY `richieste_registrazione_stato_index` (`stato`),
  KEY `richieste_registrazione_associazione_id_index` (`associazione_id`),
  CONSTRAINT `richieste_registrazione_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE SET NULL,
  CONSTRAINT `richieste_registrazione_gestita_da_foreign` FOREIGN KEY (`gestita_da`) REFERENCES `utenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `risposte_sondaggio`;
CREATE TABLE `risposte_sondaggio` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sondaggio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `votante_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `votante_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `opzione` smallint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_risposta_sondaggio_votante` (`sondaggio_id`,`votante_type`,`votante_id`),
  KEY `risposte_sondaggio_votante_type_votante_id_index` (`votante_type`,`votante_id`),
  KEY `risposte_sondaggio_associazione_id_index` (`associazione_id`),
  CONSTRAINT `risposte_sondaggio_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `risposte_sondaggio_sondaggio_id_foreign` FOREIGN KEY (`sondaggio_id`) REFERENCES `sondaggi` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `riunioni`;
CREATE TABLE `riunioni` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titolo` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_ora` datetime NOT NULL,
  `luogo` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `spazio_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `odg` json DEFAULT NULL,
  `stato` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pianificata',
  `verbale_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `convocazione_inviata_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_riunioni_assoc_data` (`associazione_id`,`data_ora`),
  KEY `riunioni_spazio_id_foreign` (`spazio_id`),
  KEY `riunioni_verbale_id_foreign` (`verbale_id`),
  KEY `riunioni_associazione_id_index` (`associazione_id`),
  CONSTRAINT `riunioni_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `riunioni_spazio_id_foreign` FOREIGN KEY (`spazio_id`) REFERENCES `spazi` (`id`) ON DELETE SET NULL,
  CONSTRAINT `riunioni_verbale_id_foreign` FOREIGN KEY (`verbale_id`) REFERENCES `verbali` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `riunioni_partecipanti`;
CREATE TABLE `riunioni_partecipanti` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `riunione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `utente_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `carica_ruolo` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stato` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'invitato',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_riunioni_partecipanti_socio` (`riunione_id`,`socio_id`),
  UNIQUE KEY `uq_riunioni_partecipanti_utente` (`riunione_id`,`utente_id`),
  KEY `riunioni_partecipanti_socio_id_foreign` (`socio_id`),
  KEY `riunioni_partecipanti_utente_id_foreign` (`utente_id`),
  KEY `riunioni_partecipanti_associazione_id_index` (`associazione_id`),
  CONSTRAINT `riunioni_partecipanti_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `riunioni_partecipanti_riunione_id_foreign` FOREIGN KEY (`riunione_id`) REFERENCES `riunioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `riunioni_partecipanti_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE CASCADE,
  CONSTRAINT `riunioni_partecipanti_utente_id_foreign` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `role_has_permissions`;
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `saved_filters`;
CREATE TABLE `saved_filters` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scope` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'soci',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `params_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_filter` (`associazione_id`,`user_id`,`scope`,`name`),
  KEY `saved_filters_user_id_index` (`user_id`),
  CONSTRAINT `saved_filters_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `saved_filters_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `utenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `scadenze`;
CREATE TABLE `scadenze` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titolo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` text COLLATE utf8mb4_unicode_ci,
  `data_scadenza` date NOT NULL,
  `tipo_scadenza` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `priorita` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Media',
  `stato` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Attiva',
  `assegnato_a` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `promemoria_giorni` int NOT NULL DEFAULT '7',
  `completata_da` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completata_at` timestamp NULL DEFAULT NULL,
  `promemoria_inviato_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `scadenze_associazione_id_index` (`associazione_id`),
  KEY `scadenze_assegnato_a_index` (`assegnato_a`),
  KEY `scadenze_completata_da_foreign` (`completata_da`),
  KEY `idx_scadenze_assoc_stato_data` (`associazione_id`,`stato`,`data_scadenza`),
  CONSTRAINT `scadenze_assegnato_a_foreign` FOREIGN KEY (`assegnato_a`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scadenze_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `scadenze_completata_da_foreign` FOREIGN KEY (`completata_da`) REFERENCES `utenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sedi`;
CREATE TABLE `sedi` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `indirizzo` text COLLATE utf8mb4_unicode_ci,
  `citta` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provincia` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cap` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `responsabile` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sedi_associazione_id_index` (`associazione_id`),
  CONSTRAINT `sedi_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sessions`;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `group` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `locked` tinyint(1) NOT NULL DEFAULT '0',
  `payload` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_associazione_id_group_name_unique` (`associazione_id`,`group`,`name`),
  CONSTRAINT `settings_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `smtp_settings`;
CREATE TABLE `smtp_settings` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `smtp_host` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `smtp_port` int NOT NULL DEFAULT '587',
  `smtp_user` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `smtp_pass_encrypted` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `smtp_encryption` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tls',
  `from_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `from_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `reply_to_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reply_to_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT '0',
  `max_per_hour` int NOT NULL DEFAULT '100',
  `auto_benvenuto` tinyint(1) NOT NULL DEFAULT '1',
  `auto_scadenza_tessera` tinyint(1) NOT NULL DEFAULT '1',
  `auto_scadenza_quota` tinyint(1) NOT NULL DEFAULT '1',
  `auto_rinnovo_tessera` tinyint(1) NOT NULL DEFAULT '0',
  `auto_pagamento_quota` tinyint(1) NOT NULL DEFAULT '0',
  `last_test_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_smtp_assoc` (`associazione_id`),
  CONSTRAINT `smtp_settings_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `soci`;
CREATE TABLE `soci` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sede_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero_socio` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cognome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_nascita` date DEFAULT NULL,
  `codice_fiscale` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `indirizzo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `citta` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provincia` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cap` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_iscrizione` date NOT NULL,
  `stato` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Attivo',
  `note` text COLLATE utf8mb4_unicode_ci,
  `privacy_consenso` tinyint(1) NOT NULL DEFAULT '0',
  `email_opt_out` tinyint(1) NOT NULL DEFAULT '0',
  `email_opt_out_token` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo_socio_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `categoria_socio_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nucleo_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tutore_nome` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tutore_telefono` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tutore_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disponibilita` json DEFAULT NULL,
  `volontario_attivo` tinyint(1) NOT NULL DEFAULT '0',
  `codice_referral` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_reset_token` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_reset_expires` datetime DEFAULT NULL,
  `rinnovo_token` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rinnovo_token_expires` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `soci_associazione_id_numero_socio_unique` (`associazione_id`,`numero_socio`),
  UNIQUE KEY `soci_associazione_id_email_unique` (`associazione_id`,`email`),
  UNIQUE KEY `uniq_assoc_cf` (`associazione_id`,`codice_fiscale`),
  UNIQUE KEY `uq_soci_codice_referral` (`codice_referral`),
  KEY `soci_cognome_nome_index` (`cognome`,`nome`),
  KEY `soci_sede_id_index` (`sede_id`),
  KEY `soci_tipo_socio_id_index` (`tipo_socio_id`),
  KEY `soci_categoria_socio_id_index` (`categoria_socio_id`),
  KEY `soci_rinnovo_token_index` (`rinnovo_token`),
  KEY `soci_nucleo_id_index` (`nucleo_id`),
  CONSTRAINT `soci_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `soci_categoria_socio_id_foreign` FOREIGN KEY (`categoria_socio_id`) REFERENCES `categorie_socio` (`id`) ON DELETE SET NULL,
  CONSTRAINT `soci_nucleo_id_foreign` FOREIGN KEY (`nucleo_id`) REFERENCES `nuclei` (`id`) ON DELETE SET NULL,
  CONSTRAINT `soci_sede_id_foreign` FOREIGN KEY (`sede_id`) REFERENCES `sedi` (`id`) ON DELETE SET NULL,
  CONSTRAINT `soci_tipo_socio_id_foreign` FOREIGN KEY (`tipo_socio_id`) REFERENCES `tipi_socio` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `socio_mansioni`;
CREATE TABLE `socio_mansioni` (
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mansione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`socio_id`,`mansione_id`),
  KEY `socio_mansioni_mansione_id_index` (`mansione_id`),
  CONSTRAINT `socio_mansioni_mansione_id_foreign` FOREIGN KEY (`mansione_id`) REFERENCES `mansioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `socio_mansioni_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `socio_tags`;
CREATE TABLE `socio_tags` (
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tag_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`socio_id`,`tag_id`),
  KEY `socio_tags_tag_id_index` (`tag_id`),
  CONSTRAINT `socio_tags_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE CASCADE,
  CONSTRAINT `socio_tags_tag_id_foreign` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sondaggi`;
CREATE TABLE `sondaggi` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `domanda` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `opzioni` json NOT NULL,
  `aperto_dal` datetime DEFAULT NULL,
  `aperto_al` datetime DEFAULT NULL,
  `solo_soci` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sondaggi_apertura` (`associazione_id`,`aperto_dal`,`aperto_al`),
  KEY `sondaggi_associazione_id_index` (`associazione_id`),
  CONSTRAINT `sondaggi_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `spazi`;
CREATE TABLE `spazi` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` text COLLATE utf8mb4_unicode_ci,
  `capienza` int unsigned DEFAULT NULL,
  `sede_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attivo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `spazi_sede_id_foreign` (`sede_id`),
  KEY `spazi_associazione_id_index` (`associazione_id`),
  CONSTRAINT `spazi_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `spazi_sede_id_foreign` FOREIGN KEY (`sede_id`) REFERENCES `sedi` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `spese_evento`;
CREATE TABLE `spese_evento` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `evento_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `importo` decimal(10,2) NOT NULL,
  `data` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_spese_evento_data` (`evento_id`,`data`),
  KEY `spese_evento_associazione_id_index` (`associazione_id`),
  CONSTRAINT `spese_evento_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `spese_evento_evento_id_foreign` FOREIGN KEY (`evento_id`) REFERENCES `eventi` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sponsor`;
CREATE TABLE `sponsor` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contatto_esterno_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nome` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `logo_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `livello` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'partner',
  `dal` date DEFAULT NULL,
  `al` date DEFAULT NULL,
  `visibile_sul_sito` tinyint(1) NOT NULL DEFAULT '0',
  `sito_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sponsor_assoc_visibile` (`associazione_id`,`visibile_sul_sito`),
  KEY `sponsor_contatto_esterno_id_foreign` (`contatto_esterno_id`),
  KEY `sponsor_associazione_id_index` (`associazione_id`),
  CONSTRAINT `sponsor_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sponsor_contatto_esterno_id_foreign` FOREIGN KEY (`contatto_esterno_id`) REFERENCES `contatti_esterni` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `storico_attivita_socio`;
CREATE TABLE `storico_attivita_socio` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `utente_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo_attivita` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `dettagli_json` json DEFAULT NULL,
  `data_attivita` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `storico_attivita_socio_socio_id_index` (`socio_id`),
  KEY `storico_attivita_socio_associazione_id_index` (`associazione_id`),
  KEY `storico_attivita_socio_utente_id_index` (`utente_id`),
  CONSTRAINT `storico_attivita_socio_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `storico_attivita_socio_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE CASCADE,
  CONSTRAINT `storico_attivita_socio_utente_id_foreign` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `tags`;
CREATE TABLE `tags` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome_tag` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `colore` varchar(7) COLLATE utf8mb4_unicode_ci DEFAULT '#888888',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tags_associazione_id_nome_tag_unique` (`associazione_id`,`nome_tag`),
  CONSTRAINT `tags_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `tessera_counters`;
CREATE TABLE `tessera_counters` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `anno` int NOT NULL,
  `ultimo_numero` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tessera_counters_associazione_id_anno_unique` (`associazione_id`,`anno`),
  CONSTRAINT `tessera_counters_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `tessera_templates`;
CREATE TABLE `tessera_templates` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo_socio_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `titolo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT 'Template Tessera',
  `contenuto` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `attivo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tessera_templates_associazione_id_tipo_socio_id_unique` (`associazione_id`,`tipo_socio_id`),
  KEY `tessera_templates_tipo_socio_id_index` (`tipo_socio_id`),
  CONSTRAINT `tessera_templates_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tessera_templates_tipo_socio_id_foreign` FOREIGN KEY (`tipo_socio_id`) REFERENCES `tipi_socio` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `tessere`;
CREATE TABLE `tessere` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `numero_tessera` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `anno_validita` int NOT NULL,
  `data_emissione` date NOT NULL,
  `data_scadenza` date NOT NULL,
  `tipo_scadenza` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stato` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Attiva',
  `qr_code_url` text COLLATE utf8mb4_unicode_ci,
  `template_tessera` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `evento_creazione_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tessere_associazione_id_numero_tessera_unique` (`associazione_id`,`numero_tessera`),
  KEY `tessere_associazione_id_anno_validita_index` (`associazione_id`,`anno_validita`),
  KEY `tessere_socio_id_associazione_id_data_emissione_index` (`socio_id`,`associazione_id`,`data_emissione`),
  KEY `tessere_socio_id_index` (`socio_id`),
  KEY `tessere_data_scadenza_index` (`data_scadenza`),
  KEY `tessere_template_tessera_index` (`template_tessera`),
  KEY `tessere_evento_creazione_id_index` (`evento_creazione_id`),
  CONSTRAINT `tessere_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tessere_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `tipi_socio`;
CREATE TABLE `tipi_socio` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` text COLLATE utf8mb4_unicode_ci,
  `costo_tessera` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tipi_socio_associazione_id_nome_unique` (`associazione_id`,`nome`),
  CONSTRAINT `tipi_socio_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `turni`;
CREATE TABLE `turni` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titolo` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` date NOT NULL,
  `ora_inizio` time NOT NULL,
  `ora_fine` time NOT NULL,
  `posti` smallint unsigned NOT NULL DEFAULT '1',
  `mansione_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `evento_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `spazio_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `progetto_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `stato` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aperto',
  `auto_conferma` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_turni_assoc_data` (`associazione_id`,`data`),
  KEY `idx_turni_assoc_stato_data` (`associazione_id`,`stato`,`data`),
  KEY `turni_mansione_id_foreign` (`mansione_id`),
  KEY `turni_evento_id_foreign` (`evento_id`),
  KEY `turni_spazio_id_foreign` (`spazio_id`),
  KEY `turni_progetto_id_foreign` (`progetto_id`),
  KEY `turni_associazione_id_index` (`associazione_id`),
  CONSTRAINT `turni_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `turni_evento_id_foreign` FOREIGN KEY (`evento_id`) REFERENCES `eventi` (`id`) ON DELETE SET NULL,
  CONSTRAINT `turni_mansione_id_foreign` FOREIGN KEY (`mansione_id`) REFERENCES `mansioni` (`id`) ON DELETE SET NULL,
  CONSTRAINT `turni_progetto_id_foreign` FOREIGN KEY (`progetto_id`) REFERENCES `progetti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `turni_spazio_id_foreign` FOREIGN KEY (`spazio_id`) REFERENCES `spazi` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `turni_assegnazioni`;
CREATE TABLE `turni_assegnazioni` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `turno_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stato` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'proposto',
  `ore_effettive` decimal(5,2) DEFAULT NULL,
  `motivo_rinuncia` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_turni_assegnazioni_turno_socio` (`turno_id`,`socio_id`),
  KEY `idx_turni_assegnazioni_socio_stato` (`socio_id`,`stato`),
  KEY `turni_assegnazioni_associazione_id_index` (`associazione_id`),
  CONSTRAINT `turni_assegnazioni_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `turni_assegnazioni_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE CASCADE,
  CONSTRAINT `turni_assegnazioni_turno_id_foreign` FOREIGN KEY (`turno_id`) REFERENCES `turni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `update_logs`;
CREATE TABLE `update_logs` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_version` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_version` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stato` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'avviato',
  `backup_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `messaggio_errore` text COLLATE utf8mb4_unicode_ci,
  `eseguito_da` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avviato_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completato_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `update_logs_stato_avviato_at_index` (`stato`,`avviato_at`),
  KEY `update_logs_eseguito_da_foreign` (`eseguito_da`),
  CONSTRAINT `update_logs_eseguito_da_foreign` FOREIGN KEY (`eseguito_da`) REFERENCES `utenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `utente_sedi`;
CREATE TABLE `utente_sedi` (
  `utente_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sede_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`utente_id`,`sede_id`),
  KEY `utente_sedi_sede_id_index` (`sede_id`),
  CONSTRAINT `utente_sedi_sede_id_foreign` FOREIGN KEY (`sede_id`) REFERENCES `sedi` (`id`) ON DELETE CASCADE,
  CONSTRAINT `utente_sedi_utente_id_foreign` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `utenti`;
CREATE TABLE `utenti` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cognome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ruolo` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `limitato_sedi` tinyint(1) NOT NULL DEFAULT '0',
  `attivo` tinyint(1) NOT NULL DEFAULT '1',
  `last_login` datetime DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `two_factor_secret` text COLLATE utf8mb4_unicode_ci,
  `two_factor_recovery_codes` text COLLATE utf8mb4_unicode_ci,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `utenti_email_unique` (`email`),
  KEY `utenti_associazione_id_index` (`associazione_id`),
  CONSTRAINT `utenti_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `valori_campi_personalizzati`;
CREATE TABLE `valori_campi_personalizzati` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `campo_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valore` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `valori_campi_personalizzati_socio_id_campo_id_unique` (`socio_id`,`campo_id`),
  KEY `valori_campi_personalizzati_socio_id_index` (`socio_id`),
  KEY `valori_campi_personalizzati_campo_id_index` (`campo_id`),
  CONSTRAINT `valori_campi_personalizzati_campo_id_foreign` FOREIGN KEY (`campo_id`) REFERENCES `campi_personalizzati` (`id`) ON DELETE CASCADE,
  CONSTRAINT `valori_campi_personalizzati_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `verbale_firmatari`;
CREATE TABLE `verbale_firmatari` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `verbale_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `socio_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `utente_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nome` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ruolo` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `firmato_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `verbale_firmatari_verbale_id_index` (`verbale_id`),
  KEY `verbale_firmatari_associazione_id_index` (`associazione_id`),
  KEY `verbale_firmatari_socio_id_index` (`socio_id`),
  KEY `verbale_firmatari_utente_id_index` (`utente_id`),
  CONSTRAINT `verbale_firmatari_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `verbale_firmatari_socio_id_foreign` FOREIGN KEY (`socio_id`) REFERENCES `soci` (`id`) ON DELETE SET NULL,
  CONSTRAINT `verbale_firmatari_utente_id_foreign` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `verbale_firmatari_verbale_id_foreign` FOREIGN KEY (`verbale_id`) REFERENCES `verbali` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `verbali`;
CREATE TABLE `verbali` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titolo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `numero` int DEFAULT NULL,
  `anno` int DEFAULT NULL,
  `data_riunione` date NOT NULL,
  `tipo_riunione` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `presenti` text COLLATE utf8mb4_unicode_ci,
  `ordine_del_giorno` text COLLATE utf8mb4_unicode_ci,
  `deliberazioni` text COLLATE utf8mb4_unicode_ci,
  `allegati` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approvato` tinyint(1) NOT NULL DEFAULT '0',
  `approvato_da` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_approvazione` date DEFAULT NULL,
  `evento_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_verbali_assoc_anno_numero` (`associazione_id`,`anno`,`numero`),
  KEY `verbali_associazione_id_index` (`associazione_id`),
  KEY `verbali_approvato_da_index` (`approvato_da`),
  KEY `idx_verbali_assoc_data` (`associazione_id`,`data_riunione`),
  KEY `verbali_evento_id_index` (`evento_id`),
  CONSTRAINT `verbali_approvato_da_foreign` FOREIGN KEY (`approvato_da`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `verbali_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `verbali_evento_id_foreign` FOREIGN KEY (`evento_id`) REFERENCES `eventi` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `votazioni_riunione`;
CREATE TABLE `votazioni_riunione` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `associazione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `riunione_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `punto_indice` smallint unsigned NOT NULL,
  `esito` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `favorevoli` smallint unsigned NOT NULL DEFAULT '0',
  `contrari` smallint unsigned NOT NULL DEFAULT '0',
  `astenuti` smallint unsigned NOT NULL DEFAULT '0',
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_votazioni_riunione_punto` (`riunione_id`,`punto_indice`),
  KEY `votazioni_riunione_associazione_id_index` (`associazione_id`),
  CONSTRAINT `votazioni_riunione_associazione_id_foreign` FOREIGN KEY (`associazione_id`) REFERENCES `associazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `votazioni_riunione_riunione_id_foreign` FOREIGN KEY (`riunione_id`) REFERENCES `riunioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration già applicate dallo schema: `php artisan migrate` eseguirà solo quelle nuove.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
('0001_01_01_000000_create_users_table', 1),
('0001_01_01_000001_create_cache_table', 1),
('0001_01_01_000002_create_jobs_table', 1),
('2022_12_14_083707_create_settings_table', 1),
('2026_06_11_095830_create_activity_log_table', 1),
('2026_06_11_095830_create_media_table', 1),
('2026_06_11_095830_create_permission_tables', 1),
('2026_06_11_095831_create_health_tables', 1),
('2026_06_11_095831_create_schedule_monitor_tables', 1),
('2026_06_11_095844_create_personal_access_tokens_table', 1),
('2026_06_11_100001_create_associazioni_table', 1),
('2026_06_11_100002_create_utenti_table', 1),
('2026_06_11_100003_create_sedi_table', 1),
('2026_06_11_100004_create_tipi_socio_table', 1),
('2026_06_11_100005_create_categorie_socio_table', 1),
('2026_06_11_100006_create_soci_table', 1),
('2026_06_11_100007_create_tessere_table', 1),
('2026_06_11_100008_create_tessera_templates_table', 1),
('2026_06_11_100009_create_saved_filters_table', 1),
('2026_06_11_100010_create_quote_table', 1),
('2026_06_11_100011_create_eventi_table', 1),
('2026_06_11_100012_create_eventi_partecipanti_table', 1),
('2026_06_11_100013_create_documenti_table', 1),
('2026_06_11_100014_create_documenti_socio_table', 1),
('2026_06_11_100015_create_campi_personalizzati_table', 1),
('2026_06_11_100016_create_valori_campi_personalizzati_table', 1),
('2026_06_11_100017_create_tags_table', 1),
('2026_06_11_100018_create_socio_tags_table', 1),
('2026_06_11_100019_create_storico_attivita_socio_table', 1),
('2026_06_11_100020_create_gruppi_dinamici_table', 1),
('2026_06_11_100021_create_verbali_table', 1),
('2026_06_11_100022_create_scadenze_table', 1),
('2026_06_11_100023_create_smtp_settings_table', 1),
('2026_06_11_100024_create_payment_gateway_settings_table', 1),
('2026_06_11_100025_create_email_templates_table', 1),
('2026_06_11_100026_create_email_queue_table', 1),
('2026_06_11_100027_create_email_log_table', 1),
('2026_06_11_100028_create_api_keys_table', 1),
('2026_06_11_110000_add_email_module_improvements', 1),
('2026_06_11_110000_extend_documenti_verbali_scadenze_module', 1),
('2026_06_11_120000_create_notifications_table', 1),
('2026_06_11_120000_create_tessera_counters_table', 1),
('2026_06_11_130000_add_frontend_pubblico_to_associazioni_table', 1),
('2026_06_11_130001_add_campi_prenotazioni_to_eventi_table', 1),
('2026_06_11_130002_create_accounts_table', 1),
('2026_06_11_130003_create_prenotazioni_table', 1),
('2026_06_11_140000_add_iscrizioni_config_to_associazioni_table', 1),
('2026_06_11_140001_add_approvazione_iscrizioni_to_eventi_table', 1),
('2026_06_11_140002_add_approvazione_to_prenotazioni_table', 1),
('2026_06_11_140003_create_richieste_iscrizione_table', 1),
('2026_06_12_090000_make_account_id_nullable_on_richieste_iscrizione', 1),
('2026_06_12_100000_add_vita_sociale_foundation', 1),
('2026_06_12_110000_add_gestione_foundation', 1),
('2026_06_12_150000_add_convocazioni_e_trasparenza', 1),
('2026_06_13_100000_add_organizzazione_foundation', 1),
('2026_06_13_110000_backfill_codice_referral_soci', 1),
('2026_06_13_120000_extend_promemoria_inviati_per_riferimento', 1),
('2026_06_13_160000_create_update_logs_table', 1),
('2026_10_22_100000_add_associazione_id_to_settings_table', 1),
('2026_10_22_120000_add_google_event_id_to_eventi_table', 1),
('2026_10_23_100000_add_sedi_perimetro', 1),
('2026_10_23_110000_create_consensi_table', 1),
('2026_10_23_120000_add_limitato_sedi_to_utenti_table', 1),
('2026_10_24_100000_create_richieste_registrazione_table', 1),
('2026_10_24_110000_add_email_verificata_to_richieste_registrazione', 1),
('2026_10_25_100000_create_crm_opportunita_table', 1);

SET FOREIGN_KEY_CHECKS = 1;
