-- Create database
CREATE DATABASE IF NOT EXISTS dtr_tracking;

USE dtr_tracking;

-- Create temp DTR table (auto-purged after 3 months)
CREATE TABLE IF NOT EXISTS dtr_temp (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date DATE NOT NULL UNIQUE,
    am_in TIME NULL,
    am_out TIME NULL,
    pm_in TIME NULL,
    pm_out TIME NULL,
    remarks VARCHAR(255) NULL,
    daily_hours DECIMAL(5,2) DEFAULT 0,
    is_overtime TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Index for faster date queries
CREATE INDEX idx_date ON dtr_temp(date);
