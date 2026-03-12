-- Migration 1.1 for Main Database
-- Add SAML 2.0 provider table for Shibboleth/InCommon institutional SSO
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 1.1;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `saml_provider` (
  `saml_provider_id`         int(11) NOT NULL AUTO_INCREMENT,
  `display_name`             varchar(100) NOT NULL,
  `slug`                     varchar(50) NOT NULL,
  `idp_entity_id`            varchar(500) NOT NULL,
  `idp_sso_url`              varchar(500) NOT NULL,
  `idp_slo_url`              varchar(500) DEFAULT NULL,
  `idp_x509_cert`            text NOT NULL,
  `attr_email`               varchar(255) DEFAULT 'urn:oid:0.9.2342.19200300.100.1.3',
  `attr_first_name`          varchar(255) DEFAULT 'urn:oid:2.5.4.42',
  `attr_last_name`           varchar(255) DEFAULT 'urn:oid:2.5.4.4',
  `attr_display_name`        varchar(255) DEFAULT 'urn:oid:2.16.840.1.113730.3.1.241',
  `sp_entity_id`             varchar(500) DEFAULT NULL,
  `want_assertions_signed`   tinyint(1) DEFAULT 1,
  `want_nameid_encrypted`    tinyint(1) DEFAULT 0,
  `authn_context`            varchar(500) DEFAULT 'urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport',
  `is_enabled`               tinyint(1) DEFAULT 0,
  `created`                  datetime DEFAULT CURRENT_TIMESTAMP,
  `updated`                  datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`saml_provider_id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB;

COMMIT;
