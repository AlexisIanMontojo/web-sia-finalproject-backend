CREATE TABLE IF NOT EXISTS `reservation_payment_services` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payment_id` INT UNSIGNED NOT NULL,
    `reservation_service_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `reservation_payment_services_payment_idx` (`payment_id`),
    KEY `reservation_payment_services_service_idx` (`reservation_service_id`),
    UNIQUE KEY `reservation_payment_services_unique` (`payment_id`, `reservation_service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
