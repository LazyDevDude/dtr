<?php
// Database configuration for XAMPP localhost
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'dtr_tracking');

// Create connection (returns null if DB doesn't exist - graceful fail)
function getDBConnection() {
    try {
        // First check if database exists by connecting without DB name
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
        
        if ($conn->connect_error) {
            return null;
        }
        
        // Try to select the database
        if (!$conn->select_db(DB_NAME)) {
            $conn->close();
            return null;
        }
        
        return $conn;
    } catch (Exception $e) {
        return null;
    }
}

// Auto-purge old records (older than 3 months)
function autoPurgeOldRecords() {
    try {
        $conn = getDBConnection();
        if (!$conn) return; // Gracefully skip if DB not available
        
        $threeMonthsAgo = date('Y-m-d', strtotime('-3 months'));
        $sql = "DELETE FROM dtr_temp WHERE date < ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $threeMonthsAgo);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    } catch (Exception $e) {
        // Silently fail if DB issues
    }
}

// Ensure is_absent column exists in the database
function ensureAbsentColumn() {
    try {
        $conn = getDBConnection();
        if (!$conn) return;
        
        // Check if column exists
        $result = $conn->query("SHOW COLUMNS FROM dtr_temp LIKE 'is_absent'");
        if ($result->num_rows == 0) {
            // Add the column
            $conn->query("ALTER TABLE dtr_temp ADD COLUMN is_absent TINYINT(1) DEFAULT 0 AFTER is_overtime");
        }
        $conn->close();
    } catch (Exception $e) {
        // Silently fail if DB issues
    }
}

// Get holidays for a specific year (Philippine holidays + configurable)
function getHolidays($year = null) {
    if ($year === null) {
        $year = date('Y');
    }
    
    // Philippine National Holidays (you can modify these)
    $holidays = [
        "$year-01-01" => "New Year's Day",
        "$year-04-09" => "Araw ng Kagitingan (Day of Valor)",
        "$year-04-17" => "Maundy Thursday", // Note: Changes yearly, update as needed
        "$year-04-18" => "Good Friday", // Note: Changes yearly, update as needed
        "$year-04-19" => "Black Saturday", // Note: Changes yearly, update as needed
        "$year-05-01" => "Labor Day",
        "$year-06-12" => "Independence Day",
        "$year-08-25" => "National Heroes Day", // Last Monday of August
        "$year-11-01" => "All Saints' Day",
        "$year-11-30" => "Bonifacio Day",
        "$year-12-25" => "Christmas Day",
        "$year-12-30" => "Rizal Day",
        "$year-12-31" => "New Year's Eve"
    ];
    
    return $holidays;
}
?>
