-- Paste this script into phpMyAdmin or your CloudPanel MariaDB database

CREATE TABLE IF NOT EXISTS `variables` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `var_key` VARCHAR(255) NOT NULL,
  `var_value` LONGTEXT NOT NULL,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `var_key_unique` (`var_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
