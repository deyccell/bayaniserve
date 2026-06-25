-- BayaniServe Smart Inventory & Resource Optimization
-- Run this once in the BayaniServe database before enabling the dashboard hooks.

CREATE TABLE IF NOT EXISTS inventory_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_id INT NULL,
    medicine_id INT NOT NULL,
    station_id INT NOT NULL,
    batch_no VARCHAR(80) NULL,
    quantity INT NOT NULL DEFAULT 0,
    expiration_date DATE NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_inventory_batches_fifo (medicine_id, station_id, expiration_date, received_at),
    INDEX idx_inventory_batches_expiry (expiration_date),
    CONSTRAINT fk_inventory_batches_medicine
        FOREIGN KEY (medicine_id) REFERENCES medicines(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_batches_station
        FOREIGN KEY (station_id) REFERENCES health_stations(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS dashboard_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_role VARCHAR(40) NOT NULL,
    station_id INT NULL,
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    severity ENUM('info', 'ok', 'warning', 'critical') NOT NULL DEFAULT 'info',
    source_type VARCHAR(60) NULL,
    source_id INT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dashboard_notifications_role_read (recipient_role, is_read, created_at),
    INDEX idx_dashboard_notifications_station (station_id),
    CONSTRAINT fk_dashboard_notifications_station
        FOREIGN KEY (station_id) REFERENCES health_stations(id)
        ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS resident_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resident_name VARCHAR(160) NULL,
    mobile_number VARCHAR(30) NOT NULL,
    station_id INT NULL,
    category VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_resident_subscription (mobile_number, station_id, category),
    INDEX idx_resident_subscriptions_category (category, is_active),
    CONSTRAINT fk_resident_subscriptions_station
        FOREIGN KEY (station_id) REFERENCES health_stations(id)
        ON DELETE SET NULL
);

-- Optional if your medicines table does not yet classify stock.
-- ALTER TABLE medicines ADD COLUMN category VARCHAR(120) NULL AFTER name;

-- Optional if you want batch imports to back-reference the summary inventory row.
-- ALTER TABLE inventory_batches
--     ADD CONSTRAINT fk_inventory_batches_inventory
--     FOREIGN KEY (inventory_id) REFERENCES inventory(id)
--     ON DELETE SET NULL;
