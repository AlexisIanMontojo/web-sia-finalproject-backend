CREATE TABLE IF NOT EXISTS `reservation_service_requests` (
    `idempotency_key` CHAR(36) NOT NULL,
    `reservation_service_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`idempotency_key`),
    UNIQUE KEY `reservation_service_requests_service_unique` (`reservation_service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
