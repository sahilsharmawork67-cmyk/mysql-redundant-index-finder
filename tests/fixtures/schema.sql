-- Fixture for the end-to-end tests. Each table exercises one or more rules;
-- the expected outcome is noted next to every index.

/*!40101 SET NAMES utf8mb4 */;

CREATE TABLE IF NOT EXISTS `app`.`accounts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `email` varchar(255) NOT NULL COMMENT 'Login, unique (see KEY below)',
  `name` varchar(100) NOT NULL, -- display name, may contain "quotes"
  `region` char(2) DEFAULT NULL /* nullable, (legacy) */,
  UNIQUE KEY `uniq_email` (`email`),                 # kept: unique constraint
  KEY `idx_email_name` (`email`, `name`),            # kept: does not make uniq_email redundant
  KEY `idx_id` (`id`),                               # redundant: duplicate of PRIMARY
  KEY `idx_name_prefix` (`name`(20)),                # kept: prefix length differs from idx_name
  KEY `idx_name` (`name`)                            # kept
) ENGINE=InnoDB;

CREATE TABLE `invoices` (
  `id` bigint NOT NULL,
  `account_id` int unsigned NOT NULL,
  `state` varchar(20) NOT NULL DEFAULT 'draft',
  `issued_on` date NOT NULL,
  `notes` text,
  PRIMARY KEY (`id`),
  KEY (`account_id`),                                -- redundant, MySQL names it account_id
  KEY (`account_id`),                                -- redundant, MySQL names it account_id_2
  KEY `idx_account_state_issued` (`account_id`, `state`, `issued_on`), -- kept
  KEY `idx_issued_desc` (`issued_on` DESC),          -- kept: direction differs
  KEY `idx_issued_state` (`issued_on`, `state`),     -- kept
  FULLTEXT KEY `ft_notes` (`notes`),                 -- kept: declared first
  FULLTEXT INDEX `ft_notes_copy` (`notes`),          -- redundant: duplicate fulltext
  CONSTRAINT `fk_invoices_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `chk_state` CHECK (`state` in ('draft', 'sent', 'paid'))
) ENGINE=InnoDB;

CREATE TABLE sessions (
  token char(64) NOT NULL,
  account_id int unsigned NOT NULL,
  expires_at datetime NOT NULL,
  PRIMARY KEY (token) USING HASH,
  KEY idx_account USING HASH (account_id),           -- kept: HASH cannot serve prefixes
  KEY idx_account_expires USING HASH (account_id, expires_at) -- kept
) ENGINE=MEMORY;

CREATE TABLE accounts_archive LIKE accounts;
