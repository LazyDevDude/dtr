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
?>
