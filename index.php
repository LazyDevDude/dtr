<?php
session_start();

// Set timezone (adjust to your timezone)
date_default_timezone_set('Asia/Manila');

require_once 'config.php';

// Initialize session data
if (!isset($_SESSION['dtr'])) {
    $_SESSION['dtr'] = [
        'date' => date('Y-m-d'),
        'am_in' => null,
        'am_out' => null,
        'pm_in' => null,
        'pm_out' => null,
        'remarks' => ''
    ];
}

// Reset session if date changed
if ($_SESSION['dtr']['date'] !== date('Y-m-d')) {
    $_SESSION['dtr'] = [
        'date' => date('Y-m-d'),
        'am_in' => null,
        'am_out' => null,
        'pm_in' => null,
        'pm_out' => null,
        'remarks' => ''
    ];
}

// Auto-purge old records on page load
autoPurgeOldRecords();

// Ensure is_absent column exists
ensureAbsentColumn();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    $response = ['success' => false];
    
    switch ($action) {
        case 'time_in_out':
            $type = $_POST['type'] ?? '';
            $time = date('H:i:s');
            
            if (in_array($type, ['am_in', 'am_out', 'pm_in', 'pm_out'])) {
                $_SESSION['dtr'][$type] = $time;
                
                // Save to database
                saveToDB();
                
                $response['success'] = true;
                $response['time'] = $time;
                $response['data'] = $_SESSION['dtr'];
            }
            break;
            
        case 'save_remarks':
            $remarks = $_POST['remarks'] ?? '';
            $_SESSION['dtr']['remarks'] = htmlspecialchars($remarks);
            saveToDB();
            $response['success'] = true;
            break;
            
        case 'manual_entry':
            $entry_date = $_POST['entry_date'] ?? date('Y-m-d');
            $am_in = $_POST['am_in'] ?? null;
            $am_out = $_POST['am_out'] ?? null;
            $pm_in = $_POST['pm_in'] ?? null;
            $pm_out = $_POST['pm_out'] ?? null;
            $remarks = $_POST['remarks_manual'] ?? '';
            $is_ot_day = isset($_POST['is_ot_day']) && $_POST['is_ot_day'] === 'true';
            $is_absent_day = isset($_POST['is_absent_day']) && $_POST['is_absent_day'] === 'true';
            
            // If marked as Absent Day
            if ($is_absent_day) {
                // If it's today's date, update session
                if ($entry_date === date('Y-m-d')) {
                    $_SESSION['dtr']['am_in'] = null;
                    $_SESSION['dtr']['am_out'] = null;
                    $_SESSION['dtr']['pm_in'] = null;
                    $_SESSION['dtr']['pm_out'] = null;
                    if ($remarks) $_SESSION['dtr']['remarks'] = htmlspecialchars($remarks);
                }
                // Save absent day with 0 hours
                saveAbsentDay($entry_date, $remarks);
                
                $response['data'] = [];
            } elseif ($is_ot_day) {
                // If marked as OT Day, set fixed 16 hours
                // If it's today's date, update session
                if ($entry_date === date('Y-m-d')) {
                    $_SESSION['dtr']['am_in'] = null;
                    $_SESSION['dtr']['am_out'] = null;
                    $_SESSION['dtr']['pm_in'] = null;
                    $_SESSION['dtr']['pm_out'] = null;
                    if ($remarks) $_SESSION['dtr']['remarks'] = htmlspecialchars($remarks);
                }
                // Save OT day with 16 hours locked
                saveOTDay($entry_date, $remarks);
                
                // Return data with OT flags for today
                if ($entry_date === date('Y-m-d')) {
                    $response['data'] = $_SESSION['dtr'];
                    $response['data']['is_overtime'] = true;
                    $response['data']['daily_hours'] = 16;
                } else {
                    $response['data'] = [];
                }
            } else {
                // Regular manual entry
                // Check if this is a remarks-only update (no times provided)
                $isRemarksOnlyUpdate = !$am_in && !$am_out && !$pm_in && !$pm_out;
                
                // If it's today's date, update session
                if ($entry_date === date('Y-m-d')) {
                    if ($am_in) $_SESSION['dtr']['am_in'] = $am_in . ':00';
                    if ($am_out) $_SESSION['dtr']['am_out'] = $am_out . ':00';
                    if ($pm_in) $_SESSION['dtr']['pm_in'] = $pm_in . ':00';
                    if ($pm_out) $_SESSION['dtr']['pm_out'] = $pm_out . ':00';
                    if ($remarks !== null) $_SESSION['dtr']['remarks'] = htmlspecialchars($remarks);
                    
                    // Only save to DB if we have times or it's a new entry
                    if (!$isRemarksOnlyUpdate) {
                        saveToDB();
                    } else {
                        // Remarks-only update for today - just update remarks in DB
                        updateRemarksOnly($entry_date, $remarks);
                    }
                    $response['data'] = $_SESSION['dtr'];
                } else {
                    // For past dates
                    if ($isRemarksOnlyUpdate) {
                        // Remarks-only update - don't overwrite existing times
                        updateRemarksOnly($entry_date, $remarks);
                    } else {
                        // Regular save with times
                        $am_in_full = $am_in ? $am_in . ':00' : null;
                        $am_out_full = $am_out ? $am_out . ':00' : null;
                        $pm_in_full = $pm_in ? $pm_in . ':00' : null;
                        $pm_out_full = $pm_out ? $pm_out . ':00' : null;
                        
                        saveToDBWithDate($entry_date, $am_in_full, $am_out_full, $pm_in_full, $pm_out_full, $remarks);
                    }
                }
            }
            
            $response['success'] = true;
            break;
            
        case 'clear_today':
            $_SESSION['dtr'] = [
                'date' => date('Y-m-d'),
                'am_in' => null,
                'am_out' => null,
                'pm_in' => null,
                'pm_out' => null,
                'remarks' => ''
            ];
            
            // Clear from database if available
            try {
                $conn = getDBConnection();
                if ($conn) {
                    $stmt = $conn->prepare("DELETE FROM dtr_temp WHERE date = ?");
                    $date = date('Y-m-d');
                    $stmt->bind_param("s", $date);
                    $stmt->execute();
                    $stmt->close();
                    $conn->close();
                }
            } catch (Exception $e) {
                // Silently fail if DB not available
            }
            
            $response['success'] = true;
            break;
            
        case 'delete_record':
            $delete_date = $_POST['date'] ?? '';
            
            if ($delete_date) {
                try {
                    $conn = getDBConnection();
                    if ($conn) {
                        $stmt = $conn->prepare("DELETE FROM dtr_temp WHERE date = ?");
                        $stmt->bind_param("s", $delete_date);
                        $stmt->execute();
                        $stmt->close();
                        $conn->close();
                        
                        // If it's today, clear session too
                        if ($delete_date === date('Y-m-d')) {
                            $_SESSION['dtr'] = [
                                'date' => date('Y-m-d'),
                                'am_in' => null,
                                'am_out' => null,
                                'pm_in' => null,
                                'pm_out' => null,
                                'remarks' => ''
                            ];
                        }
                        
                        $response['success'] = true;
                    }
                } catch (Exception $e) {
                    $response['error'] = 'Database error';
                }
            }
            break;
            
        case 'check_date_exists':
            $check_date = $_POST['check_date'] ?? '';
            if ($check_date) {
                try {
                    $conn = getDBConnection();
                    if ($conn) {
                        $stmt = $conn->prepare("SELECT date, remarks, is_overtime, is_absent, daily_hours, am_in, am_out, pm_in, pm_out FROM dtr_temp WHERE date = ?");
                        $stmt->bind_param("s", $check_date);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        
                        if ($result->num_rows > 0) {
                            $record = $result->fetch_assoc();
                            $isLocked = $record['is_overtime'] && $record['daily_hours'] == 16.00 && 
                                       !$record['am_in'] && !$record['am_out'] && 
                                       !$record['pm_in'] && !$record['pm_out'];
                            
                            $response['success'] = true;
                            $response['exists'] = true;
                            $response['date'] = $record['date'];
                            $response['remarks'] = $record['remarks'] ?? '';
                            $response['isOT'] = (bool)$record['is_overtime'];
                            $response['isAbsent'] = isset($record['is_absent']) ? (bool)$record['is_absent'] : false;
                            $response['isLocked'] = $isLocked;
                        } else {
                            $response['success'] = true;
                            $response['exists'] = false;
                        }
                        
                        $stmt->close();
                        $conn->close();
                    }
                } catch (Exception $e) {
                    $response['error'] = 'Database error';
                }
            }
            break;
    }
    
    echo json_encode($response);
    exit;
}

// Save current session data to database
function saveToDB() {
    $date = $_SESSION['dtr']['date'];
    $am_in = $_SESSION['dtr']['am_in'];
    $am_out = $_SESSION['dtr']['am_out'];
    $pm_in = $_SESSION['dtr']['pm_in'];
    $pm_out = $_SESSION['dtr']['pm_out'];
    $remarks = $_SESSION['dtr']['remarks'];
    
    saveToDBWithDate($date, $am_in, $am_out, $pm_in, $pm_out, $remarks);
}

// Save specific date entry to database
function saveToDBWithDate($date, $am_in, $am_out, $pm_in, $pm_out, $remarks) {
    try {
        $conn = getDBConnection();
        if (!$conn) return;
        
        // Calculate daily hours
        $daily_hours = calculateDailyHours($am_in, $am_out, $pm_in, $pm_out);
        $is_overtime = $daily_hours > 16 ? 1 : 0;
        $is_absent = 0; // Regular entries are not absent
        
        // Insert or update
        $sql = "INSERT INTO dtr_temp (date, am_in, am_out, pm_in, pm_out, remarks, daily_hours, is_overtime, is_absent) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                am_in = VALUES(am_in), 
                am_out = VALUES(am_out), 
                pm_in = VALUES(pm_in), 
                pm_out = VALUES(pm_out), 
                remarks = VALUES(remarks),
                daily_hours = VALUES(daily_hours),
                is_overtime = VALUES(is_overtime),
                is_absent = VALUES(is_absent)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssdii", $date, $am_in, $am_out, $pm_in, $pm_out, $remarks, $daily_hours, $is_overtime, $is_absent);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    } catch (Exception $e) {
        // Silently fail if DB not available
    }
}

// Save OT Day (16 hours locked)
function saveOTDay($date, $remarks) {
    try {
        $conn = getDBConnection();
        if (!$conn) return;
        
        // Fixed 16 hours for OT day
        $daily_hours = 16.00;
        $is_overtime = 1;
        $is_absent = 0;
        $am_in = null;
        $am_out = null;
        $pm_in = null;
        $pm_out = null;
        
        // Insert or update with OT locked
        $sql = "INSERT INTO dtr_temp (date, am_in, am_out, pm_in, pm_out, remarks, daily_hours, is_overtime, is_absent) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                am_in = VALUES(am_in), 
                am_out = VALUES(am_out), 
                pm_in = VALUES(pm_in), 
                pm_out = VALUES(pm_out), 
                remarks = VALUES(remarks),
                daily_hours = VALUES(daily_hours),
                is_overtime = VALUES(is_overtime),
                is_absent = VALUES(is_absent)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssdii", $date, $am_in, $am_out, $pm_in, $pm_out, $remarks, $daily_hours, $is_overtime, $is_absent);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    } catch (Exception $e) {
        // Silently fail if DB not available
    }
}

// Save absent day (0 hours, no times)
function saveAbsentDay($date, $remarks) {
    try {
        $conn = getDBConnection();
        if (!$conn) return;
        
        // 0 hours for absent day
        $daily_hours = 0.00;
        $is_overtime = 0;
        $is_absent = 1;
        $am_in = null;
        $am_out = null;
        $pm_in = null;
        $pm_out = null;
        
        // Insert or update with absent marked
        $sql = "INSERT INTO dtr_temp (date, am_in, am_out, pm_in, pm_out, remarks, daily_hours, is_overtime, is_absent) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                am_in = VALUES(am_in), 
                am_out = VALUES(am_out), 
                pm_in = VALUES(pm_in), 
                pm_out = VALUES(pm_out), 
                remarks = VALUES(remarks),
                daily_hours = VALUES(daily_hours),
                is_overtime = VALUES(is_overtime),
                is_absent = VALUES(is_absent)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssdii", $date, $am_in, $am_out, $pm_in, $pm_out, $remarks, $daily_hours, $is_overtime, $is_absent);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    } catch (Exception $e) {
        // Silently fail if DB not available
    }
}

// Update remarks only (preserve existing times)
function updateRemarksOnly($date, $remarks) {
    try {
        $conn = getDBConnection();
        if (!$conn) return;
        
        // Only update remarks field, leave everything else unchanged
        $sql = "UPDATE dtr_temp SET remarks = ? WHERE date = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $remarks, $date);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    } catch (Exception $e) {
        // Silently fail if DB not available
    }
}

// Calculate daily hours
function calculateDailyHours($am_in, $am_out, $pm_in, $pm_out) {
    $total = 0;
    
    if ($am_in && $am_out) {
        $total += (strtotime($am_out) - strtotime($am_in)) / 3600;
    }
    
    if ($pm_in && $pm_out) {
        $total += (strtotime($pm_out) - strtotime($pm_in)) / 3600;
    }
    
    return round($total, 2);
}

// Format hours to "X hrs Y mins" format
function formatHoursMinutes($decimalHours) {
    if ($decimalHours == 0) return '0 hrs 0 mins';
    
    $hours = floor($decimalHours);
    $minutes = round(($decimalHours - $hours) * 60);
    
    // Handle rounding edge case
    if ($minutes >= 60) {
        $hours++;
        $minutes = 0;
    }
    
    return $hours . ' hrs ' . $minutes . ' mins';
}

// Get monthly summary
function getMonthlySummary($month = null) {
    try {
        $conn = getDBConnection();
        if (!$conn) return ['total_hours' => 0, 'ot_days' => 0];
        
        $selectedMonth = $month ?? date('Y-m');
        
        $sql = "SELECT SUM(daily_hours) as total_hours, SUM(is_overtime) as ot_days 
                FROM dtr_temp 
                WHERE DATE_FORMAT(date, '%Y-%m') = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $selectedMonth);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        $conn->close();
        
        return [
            'total_hours' => $data['total_hours'] ?? 0,
            'ot_days' => $data['ot_days'] ?? 0
        ];
    } catch (Exception $e) {
        return ['total_hours' => 0, 'ot_days' => 0];
    }
}

// Get monthly records (all entries for current month)
function getMonthlyRecords($month = null) {
    try {
        $conn = getDBConnection();
        if (!$conn) return [];
        
        $selectedMonth = $month ?? date('Y-m');
        
        $sql = "SELECT * FROM dtr_temp 
                WHERE DATE_FORMAT(date, '%Y-%m') = ? 
                ORDER BY date DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $selectedMonth);
        $stmt->execute();
        $result = $stmt->get_result();
        $records = [];
        
        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }
        
        $stmt->close();
        $conn->close();
        
        return $records;
    } catch (Exception $e) {
        return [];
    }
}

// Get comprehensive monthly report with computed totals
function getMonthlyReport($month = null) {
    try {
        $conn = getDBConnection();
        if (!$conn) return null;
        
        $selectedMonth = $month ?? date('Y-m');
        
        $sql = "SELECT 
                COUNT(*) as total_days,
                SUM(daily_hours) as total_hours,
                AVG(daily_hours) as avg_hours,
                SUM(CASE WHEN is_overtime = 1 THEN 1 ELSE 0 END) as ot_days,
                SUM(CASE WHEN is_overtime = 0 THEN 1 ELSE 0 END) as normal_days,
                SUM(CASE WHEN is_overtime = 1 THEN daily_hours ELSE 0 END) as ot_hours,
                SUM(CASE WHEN is_overtime = 0 THEN daily_hours ELSE 0 END) as regular_hours,
                MIN(date) as first_entry,
                MAX(date) as last_entry
                FROM dtr_temp 
                WHERE DATE_FORMAT(date, '%Y-%m') = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $selectedMonth);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        $conn->close();
        
        if ($data['total_days'] == 0) return null;
        
        $monthName = date('F Y', strtotime($selectedMonth . '-01'));
        
        return [
            'total_days' => $data['total_days'] ?? 0,
            'total_hours' => $data['total_hours'] ?? 0,
            'avg_hours' => $data['avg_hours'] ?? 0,
            'ot_days' => $data['ot_days'] ?? 0,
            'normal_days' => $data['normal_days'] ?? 0,
            'ot_hours' => $data['ot_hours'] ?? 0,
            'regular_hours' => $data['regular_hours'] ?? 0,
            'first_entry' => $data['first_entry'],
            'last_entry' => $data['last_entry'],
            'month_name' => $monthName,
            'selected_month' => $selectedMonth
        ];
    } catch (Exception $e) {
        return null;
    }
}

// Get OJT Total Progress (all-time hours)
function getOJTProgress() {
    try {
        $conn = getDBConnection();
        if (!$conn) return ['total_hours' => 0, 'total_days' => 0];
        
        $sql = "SELECT SUM(daily_hours) as total_hours, COUNT(*) as total_days 
                FROM dtr_temp";
        
        $result = $conn->query($sql);
        $data = $result->fetch_assoc();
        $conn->close();
        
        return [
            'total_hours' => $data['total_hours'] ?? 0,
            'total_days' => $data['total_days'] ?? 0
        ];
    } catch (Exception $e) {
        return ['total_hours' => 0, 'total_days' => 0];
    }
}

// Get month from query parameter or use current month
$selectedMonth = $_GET['month'] ?? date('Y-m');

$monthly = getMonthlySummary($selectedMonth);
$monthly_records = getMonthlyRecords($selectedMonth);
$monthly_report = getMonthlyReport($selectedMonth);
$ojt_progress = getOJTProgress();

// Check if today is marked as OT day in database
$is_ot = false;
$today_hours = 0;
try {
    $conn = getDBConnection();
    if ($conn) {
        $stmt = $conn->prepare("SELECT daily_hours, is_overtime FROM dtr_temp WHERE date = ?");
        $today = date('Y-m-d');
        $stmt->bind_param("s", $today);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $today_hours = $row['daily_hours'];
            $is_ot = (bool)$row['is_overtime'];
        } else {
            // Calculate from session if not in DB
            $today_hours = calculateDailyHours(
                $_SESSION['dtr']['am_in'],
                $_SESSION['dtr']['am_out'],
                $_SESSION['dtr']['pm_in'],
                $_SESSION['dtr']['pm_out']
            );
            $is_ot = $today_hours > 16;
        }
        $stmt->close();
        $conn->close();
    } else {
        // Fallback to session calculation
        $today_hours = calculateDailyHours(
            $_SESSION['dtr']['am_in'],
            $_SESSION['dtr']['am_out'],
            $_SESSION['dtr']['pm_in'],
            $_SESSION['dtr']['pm_out']
        );
        $is_ot = $today_hours > 16;
    }
} catch (Exception $e) {
    // Fallback to session calculation
    $today_hours = calculateDailyHours(
        $_SESSION['dtr']['am_in'],
        $_SESSION['dtr']['am_out'],
        $_SESSION['dtr']['pm_in'],
        $_SESSION['dtr']['pm_out']
    );
    $is_ot = $today_hours > 16;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DAILY TIME RECORD</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            /* Light mode colors */
            --bg-gradient-start: #667eea;
            --bg-gradient-end: #764ba2;
            --card-bg: #ffffff;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --border-color: #e5e7eb;
            --input-bg: #ffffff;
            --table-header-bg: #f3f4f6;
            --table-header-text: #374151;
            --manual-entry-bg: #eff6ff;
            --manual-entry-border: #3b82f6;
            --report-bg-start: #f3f4f6;
            --report-bg-end: #e5e7eb;
            --report-border: #9ca3af;
            --report-card-bg: #ffffff;
        }

        body.dark-mode {
            /* Dark mode colors */
            --bg-gradient-start: #1e1b4b;
            --bg-gradient-end: #312e81;
            --card-bg: #1f2937;
            --text-primary: #f9fafb;
            --text-secondary: #d1d5db;
            --border-color: #374151;
            --input-bg: #374151;
            --table-header-bg: #374151;
            --table-header-text: #f9fafb;
            --manual-entry-bg: #1e3a5f;
            --manual-entry-border: #3b82f6;
            --report-bg-start: #374151;
            --report-bg-end: #1f2937;
            --report-border: #4b5563;
            --report-card-bg: #374151;
        }

        body {
            background: linear-gradient(135deg, var(--bg-gradient-start) 0%, var(--bg-gradient-end) 100%);
            min-height: 100vh;
            padding: 20px 0;
            transition: background 0.3s ease;
        }
        .main-card {
            background: var(--card-bg);
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            overflow: hidden;
            transition: background 0.3s ease;
        }
        .header-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header-section h1 {
            font-size: 1.8rem;
            font-weight: bold;
            margin: 0;
        }
        .header-section .subtitle {
            font-size: 0.95rem;
            opacity: 0.9;
            margin-top: 5px;
        }
        .time-btn {
            font-size: 1rem;
            font-weight: 600;
            padding: 15px;
            border-radius: 12px;
            border: none;
            transition: all 0.3s;
            position: relative;
        }
        .time-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .time-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .btn-am-in { background: #10b981; color: white; }
        .btn-am-out { background: #f59e0b; color: white; }
        .btn-pm-in { background: #3b82f6; color: white; }
        .btn-pm-out { background: #8b5cf6; color: white; }
        
        .time-display {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-top: 8px;
            transition: color 0.3s ease;
        }
        .time-display .time-value {
            color: #667eea;
            font-family: 'Courier New', monospace;
        }
        body.dark-mode .time-display .time-value {
            color: #93c5fd;
        }
        .stats-card {
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .stats-card.today {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: white;
        }
        .stats-card.monthly {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        .stats-card.ojt {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            color: white;
        }
        .stats-card h5 {
            font-size: 1rem;
            margin-bottom: 10px;
            opacity: 0.9;
        }
        .stats-card .value {
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0;
        }
        .ot-badge {
            background: #ef4444;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 600;
            display: inline-block;
            margin-top: 10px;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        .log-table {
            margin-top: 20px;
        }
        .log-table th {
            background: var(--table-header-bg);
            color: var(--table-header-text);
            font-weight: 600;
            border: none;
            transition: background 0.3s ease, color 0.3s ease;
        }
        body.dark-mode .log-table {
            color: var(--text-primary);
        }
        body.dark-mode .table {
            color: var(--text-primary);
            border-color: var(--border-color);
        }
        body.dark-mode .table td {
            border-color: var(--border-color);
        }
        .remarks-section {
            margin-top: 20px;
            padding: 20px;
            background: #f9fafb;
            border-radius: 12px;
        }
        .manual-entry-section {
            margin-top: 20px;
            padding: 20px;
            background: var(--manual-entry-bg);
            border-radius: 12px;
            border: 2px dashed var(--manual-entry-border);
            transition: background 0.3s ease, border-color 0.3s ease;
        }
        .manual-entry-section h6 {
            color: #3b82f6;
            margin-bottom: 15px;
            transition: color 0.3s ease;
        }
        body.dark-mode .manual-entry-section h6 {
            color: #93c5fd;
        }
        body.dark-mode .manual-entry-section label {
            color: #93c5fd !important;
        }
        body.dark-mode .manual-entry-section .text-muted {
            color: #9ca3af !important;
        }
        
        /* Time button small labels */
        .time-btn small {
            line-height: 1;
        }
        
        /* Alert styling for dark mode */
        body.dark-mode .alert-info {
            background-color: #1e3a5f;
            border-color: #3b82f6;
            color: #93c5fd;
        }
        body.dark-mode .alert-info .text-muted {
            color: #9ca3af !important;
        }
        
        /* Truncated Remarks */
        .remarks-cell {
            max-width: 150px;
            cursor: pointer;
            position: relative;
        }
        .remarks-truncated {
            display: inline-block;
            max-width: 100%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: color 0.2s ease;
        }
        .remarks-truncated:hover {
            color: #3b82f6 !important;
            text-decoration: underline;
        }
        .remarks-icon {
            font-size: 0.8rem;
            opacity: 0.6;
            margin-left: 4px;
        }
        body.dark-mode .remarks-truncated:hover {
            color: #60a5fa !important;
        }
        
        /* Modal Dark Mode */
        body.dark-mode .modal-content {
            background: var(--card-bg);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }
        body.dark-mode .modal-header {
            border-bottom-color: var(--border-color);
        }
        body.dark-mode .modal-footer {
            border-top-color: var(--border-color);
        }
        body.dark-mode .btn-close {
            filter: invert(1);
        }
        
        .month-selector {
            padding: 15px 20px;
            background: #f0fdf4;
            border-radius: 12px;
            border: 2px solid #10b981;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .month-selector label {
            color: #065f46;
            margin-bottom: 0;
        }
        .month-selector select {
            border: 2px solid #10b981;
            border-radius: 8px;
            padding: 8px 15px;
            font-weight: 600;
            color: #065f46;
        }
        .month-selector select:focus {
            outline: none;
            border-color: #047857;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }
        .manual-time-input {
            border: 2px solid #3b82f6;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 0.95rem;
            font-family: 'Courier New', monospace;
            background: var(--input-bg);
            color: var(--text-primary);
            transition: background 0.3s ease, color 0.3s ease, border-color 0.3s ease;
            pointer-events: auto;
            cursor: text;
        }
        .manual-time-input:focus {
            outline: none;
            border-color: #1e40af;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .manual-time-input:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }
        body.dark-mode .manual-time-input {
            background: var(--input-bg);
            color: var(--text-primary);
            border-color: #60a5fa;
        }
        .btn-save-manual {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 600;
        }
        .btn-save-manual:hover {
            background: #1e40af;
        }
        .form-check-input:checked {
            background-color: #ef4444;
            border-color: #ef4444;
        }
        .form-check-input:focus {
            border-color: #ef4444;
            box-shadow: 0 0 0 0.25rem rgba(239, 68, 68, 0.25);
        }
        .form-check-label {
            user-select: none;
        }
        .report-section {
            margin-top: 20px;
            padding: 25px;
            background: linear-gradient(135deg, var(--report-bg-start) 0%, var(--report-bg-end) 100%);
            border-radius: 15px;
            border: 2px solid var(--report-border);
            transition: background 0.3s ease, border-color 0.3s ease;
        }
        .report-section h5 {
            color: var(--text-primary);
            font-weight: bold;
            margin-bottom: 20px;
            transition: color 0.3s ease;
        }
        .report-card {
            background: var(--report-card-bg);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            transition: background 0.3s ease;
        }
        .report-card h6 {
            color: var(--text-secondary);
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 8px;
            text-transform: uppercase;
            transition: color 0.3s ease;
        }
        .report-card .value {
            font-size: 2rem;
            font-weight: bold;
            color: var(--text-primary);
            transition: color 0.3s ease;
        }
        .report-card .sub-value {
            font-size: 0.95rem;
            color: var(--text-secondary);
            margin-top: 5px;
            transition: color 0.3s ease;
        }
        .report-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .btn-clear {
            background: #ef4444;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
        }
        .btn-clear:hover {
            background: #dc2626;
        }
        .footer-text {
            text-align: center;
            color: white;
            margin-top: 30px;
            font-size: 0.9rem;
            opacity: 0.8;
        }
        
        /* Dark Mode Toggle */
        .dark-mode-toggle {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50px;
            padding: 8px 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .dark-mode-toggle:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.05);
        }
        .dark-mode-toggle i {
            font-size: 1.2rem;
        }
        
        @media (max-width: 768px) {
            .header-section h1 {
                font-size: 1.4rem;
            }
            .stats-card .value {
                font-size: 2rem;
            }
            .dark-mode-toggle {
                position: static;
                margin: 15px auto 0;
                width: fit-content;
            }
        }
        
        /* ============================================
           VISUAL ENHANCEMENTS - Professional Look
           ============================================ */
        
        /* Fade-in animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.8; transform: scale(1.05); }
        }
        
        @keyframes glow {
            0%, 100% { box-shadow: 0 0 5px rgba(59, 130, 246, 0.4); }
            50% { box-shadow: 0 0 20px rgba(59, 130, 246, 0.8); }
        }
        
        @keyframes shimmer {
            0% { background-position: -1000px 0; }
            100% { background-position: 1000px 0; }
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        @keyframes countUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Ripple effect for buttons */
        .ripple {
            position: relative;
            overflow: hidden;
        }
        
        .ripple::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        .ripple:active::after {
            width: 300px;
            height: 300px;
        }
        
        /* Enhanced stats cards with animations */
        .stats-card {
            animation: fadeInUp 0.6s ease-out;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            will-change: transform;
        }
        
        .stats-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 12px 28px rgba(0,0,0,0.25);
        }
        
        .stats-card.today {
            animation-delay: 0.1s;
        }
        
        .stats-card.monthly {
            animation-delay: 0.2s;
        }
        
        .stats-card.ojt {
            animation-delay: 0.3s;
        }
        
        .stats-card.today:hover {
            box-shadow: 0 12px 28px rgba(251, 191, 36, 0.4);
        }
        
        .stats-card.monthly:hover {
            box-shadow: 0 12px 28px rgba(16, 185, 129, 0.4);
        }
        
        .stats-card.ojt:hover {
            box-shadow: 0 12px 28px rgba(139, 92, 246, 0.4);
        }
        
        .stats-card .value {
            animation: countUp 0.8s ease-out;
        }
        
        /* Circular progress indicator */
        .progress-ring {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 50px;
            height: 50px;
        }
        
        .progress-ring-circle {
            transition: stroke-dashoffset 0.8s ease;
            transform: rotate(-90deg);
            transform-origin: 50% 50%;
        }
        
        /* Enhanced time buttons */
        .time-btn {
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .time-btn:not(:disabled)::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        .time-btn:not(:disabled):active::before {
            width: 300px;
            height: 300px;
        }
        
        .time-btn:not(:disabled):hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.3);
        }
        
        .time-btn:disabled {
            opacity: 0.6;
        }
        
        /* Next action button pulse */
        .time-btn.next-action {
            animation: pulse 2s infinite;
            box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.7);
        }
        
        .time-btn.completed {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
        }
        
        /* Enhanced table with hover effects */
        .table-hover tbody tr {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .table-hover tbody tr:hover {
            transform: scale(1.01);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            background-color: var(--table-header-bg) !important;
            position: relative;
            z-index: 1;
        }
        
        .table tbody tr {
            animation: fadeIn 0.5s ease-out;
        }
        
        .table tbody tr:nth-child(even) {
            background-color: rgba(0,0,0,0.02);
        }
        
        body.dark-mode .table tbody tr:nth-child(even) {
            background-color: rgba(255,255,255,0.02);
        }
        
        /* Animated badges */
        .badge {
            transition: all 0.3s ease;
            animation: scaleIn 0.4s ease-out;
        }
        
        .badge:hover {
            transform: scale(1.1);
        }
        
        /* Enhanced dark mode effects */
        body.dark-mode .stats-card {
            border: 1px solid rgba(59, 130, 246, 0.3);
            background: linear-gradient(135deg, var(--card-bg) 0%, rgba(31, 41, 55, 1) 100%);
        }
        
        body.dark-mode .stats-card:hover {
            border-color: rgba(59, 130, 246, 0.6);
            box-shadow: 0 12px 28px rgba(59, 130, 246, 0.2);
        }
        
        body.dark-mode .time-btn:not(:disabled) {
            box-shadow: 0 0 10px rgba(59, 130, 246, 0.3);
        }
        
        /* Remarks cell enhanced */
        .remarks-cell {
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .remarks-cell:hover {
            background-color: #dbeafe !important;
            color: #1e40af;
            font-weight: 600;
        }
        
        body.dark-mode .remarks-cell:hover {
            background-color: rgba(59, 130, 246, 0.2) !important;
            color: #60a5fa;
        }
        
        .remarks-icon {
            transition: transform 0.3s ease;
        }
        
        .remarks-cell:hover .remarks-icon {
            transform: translateX(5px);
        }
        
        /* Floating Action Button (FAB) */
        .fab-container {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 15px;
        }
        
        .fab-button {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            animation: scaleIn 0.5s ease-out;
        }
        
        .fab-button:hover {
            transform: scale(1.1) rotate(90deg);
            box-shadow: 0 8px 25px rgba(0,0,0,0.4);
        }
        
        .fab-mini {
            width: 50px;
            height: 50px;
            font-size: 1.2rem;
            opacity: 0;
            transform: scale(0);
            transition: all 0.3s ease;
        }
        
        .fab-container.open .fab-mini {
            opacity: 1;
            transform: scale(1);
        }
        
        /* Enhanced modal */
        .modal-content {
            animation: scaleIn 0.3s ease-out;
            border: none;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        body.dark-mode .modal-content {
            background-color: var(--card-bg);
            color: var(--text-primary);
        }
        
        body.dark-mode .modal-header {
            border-bottom-color: var(--border-color);
        }
        
        body.dark-mode .modal-footer {
            border-top-color: var(--border-color);
        }
        
        body.dark-mode .btn-close {
            filter: invert(1);
        }
        
        /* Lock icon animation */
        @keyframes lockRotate {
            0% { transform: rotate(0deg); }
            25% { transform: rotate(-10deg); }
            75% { transform: rotate(10deg); }
            100% { transform: rotate(0deg); }
        }
        
        .bi-lock-fill {
            display: inline-block;
            animation: lockRotate 0.6s ease-in-out;
        }
        
        /* Checkbox flip animation */
        @keyframes checkFlip {
            0% { transform: scale(1) rotateY(0deg); }
            50% { transform: scale(1.2) rotateY(180deg); }
            100% { transform: scale(1) rotateY(360deg); }
        }
        
        .form-check-input:checked {
            animation: checkFlip 0.5s ease;
        }
        
        /* Loading skeleton */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
            border-radius: 4px;
        }
        
        body.dark-mode .skeleton {
            background: linear-gradient(90deg, #374151 25%, #4b5563 50%, #374151 75%);
            background-size: 200% 100%;
        }
        
        /* Mini calendar widget */
        .mini-calendar {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 15px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            animation: fadeInUp 0.5s ease-out;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
            margin-top: 10px;
        }
        
        .calendar-day {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            background: var(--input-bg);
            color: var(--text-secondary);
        }
        
        .calendar-day:hover {
            background: #3b82f6;
            color: white;
            transform: scale(1.1);
        }
        
        .calendar-day.today {
            background: #3b82f6;
            color: white;
            box-shadow: 0 0 10px rgba(59, 130, 246, 0.5);
        }
        
        .calendar-day.has-entry {
            background: #10b981;
            color: white;
        }
        
        .calendar-day.past-entry {
            background: #8b5cf6;
            color: white;
        }
        
        .calendar-day.future-entry {
            background: #06b6d4;
            color: white;
        }
        
        .calendar-day.absent-day {
            background: #eab308;
            color: white;
        }
        
        .calendar-day.holiday-day {
            background: #ec4899;
            color: white;
        }
        
        .calendar-day.ot-locked-day {
            background: #f97316;
            color: white;
            position: relative;
            font-weight: 700;
            box-shadow: 0 0 8px rgba(249, 115, 22, 0.5);
        }
        
        .calendar-day.ot-locked-day:hover {
            background: #ea580c;
            box-shadow: 0 0 12px rgba(249, 115, 22, 0.7);
            transform: scale(1.15);
        }
        
        .calendar-day.ot-locked-day .bi-lock-fill {
            animation: lockPulse 2s infinite;
        }
        
        @keyframes lockPulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
        
        .calendar-day-number {
            display: block;
        }
        
        .calendar-day.disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        
        /* Progress bar for hours */
        .hours-progress {
            height: 8px;
            background: var(--border-color);
            border-radius: 10px;
            overflow: hidden;
            margin-top: 10px;
            position: relative;
        }
        
        .hours-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #10b981 0%, #3b82f6 100%);
            border-radius: 10px;
            transition: width 1s ease-out;
            position: relative;
            overflow: hidden;
        }
        
        .hours-progress-bar::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 2s infinite;
        }
        
        /* Bottom navigation for mobile */
        @media (max-width: 768px) {
            .mobile-nav {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                background: var(--card-bg);
                border-top: 2px solid var(--border-color);
                padding: 10px 0;
                display: flex;
                justify-content: space-around;
                z-index: 1000;
                box-shadow: 0 -4px 12px rgba(0,0,0,0.1);
            }
            
            .mobile-nav-item {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 5px;
                color: var(--text-secondary);
                text-decoration: none;
                font-size: 0.7rem;
                transition: all 0.3s ease;
                padding: 5px 15px;
                border-radius: 8px;
            }
            
            .mobile-nav-item i {
                font-size: 1.5rem;
            }
            
            .mobile-nav-item.active,
            .mobile-nav-item:hover {
                color: #3b82f6;
                background: rgba(59, 130, 246, 0.1);
            }
            
            .container {
                padding-bottom: 80px;
            }
            
            .fab-container {
                bottom: 90px;
            }
        }
        
        /* Smooth transitions */
        * {
            transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
        }
        
        button, a, input, select, .badge, .card {
            transition: all 0.2s ease;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="main-card">
            <div class="header-section" style="position: relative;">
                <div class="dark-mode-toggle" onclick="toggleDarkMode()">
                    <i class="bi bi-moon-stars-fill" id="dark-mode-icon"></i>
                    <span id="dark-mode-text">Dark</span>
                </div>
                <h1><i class="bi bi-clock-history"></i> Daily Time Record</h1>
                <div class="subtitle">LazyDevDude</div>
                <div class="mt-2" style="font-size: 1.1rem;">
                    <i class="bi bi-calendar-check"></i> <?php echo date('F d, Y'); ?>
                </div>
            </div>
            
            <div class="p-4">
                <!-- Time Buttons (Only for Current Month) -->
                <?php if ($selectedMonth === date('Y-m')): ?>
                <?php if ($is_ot && $today_hours == 16.00 && !$_SESSION['dtr']['am_in'] && !$_SESSION['dtr']['am_out'] && !$_SESSION['dtr']['pm_in'] && !$_SESSION['dtr']['pm_out']): ?>
                <div class="alert alert-warning mb-3" id="ot-lock-alert" role="alert">
                    <i class="bi bi-lock-fill"></i> <strong>OT Day Mode:</strong> Time tracking buttons are locked. Today is marked as 16-hour OT day.
                </div>
                <?php else: ?>
                <div class="alert alert-info mb-3" role="alert" style="font-size: 0.9rem;">
                    <i class="bi bi-clock"></i> <strong>Office Hours Reference:</strong> 
                    AM: 7:30-12:00 | Lunch: 12:00-1:00 | PM: 1:00-5:00
                </div>
                <div class="alert alert-warning mb-3" id="ot-lock-alert" role="alert" style="display: none;">
                    <i class="bi bi-lock-fill"></i> <strong>OT Day Mode:</strong> Time tracking buttons are locked. Today is marked as 16-hour OT day.
                </div>
                <?php endif; ?>
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <button class="time-btn btn-am-in w-100" onclick="timeInOut('am_in')" 
                                id="btn-am-in" <?php echo ($_SESSION['dtr']['am_in'] || $is_ot) ? 'disabled' : ''; ?>>
                            <i class="bi bi-sunrise"></i> AM IN
                        </button>
                        <div class="time-display text-center">
                            <span id="time-am-in" class="time-value">
                                <?php echo $_SESSION['dtr']['am_in'] ? date('h:i A', strtotime($_SESSION['dtr']['am_in'])) : '--:--'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <button class="time-btn btn-am-out w-100" onclick="timeInOut('am_out')"
                                id="btn-am-out" <?php echo ($_SESSION['dtr']['am_out'] || !$_SESSION['dtr']['am_in'] || $is_ot) ? 'disabled' : ''; ?>>
                            <i class="bi bi-box-arrow-right"></i> AM OUT
                        </button>
                        <div class="time-display text-center">
                            <span id="time-am-out" class="time-value">
                                <?php echo $_SESSION['dtr']['am_out'] ? date('h:i A', strtotime($_SESSION['dtr']['am_out'])) : '--:--'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <button class="time-btn btn-pm-in w-100" onclick="timeInOut('pm_in')"
                                id="btn-pm-in" <?php echo ($_SESSION['dtr']['pm_in'] || !$_SESSION['dtr']['am_out'] || $is_ot) ? 'disabled' : ''; ?>>
                            <i class="bi bi-sunset"></i> PM IN
                        </button>
                        <div class="time-display text-center">
                            <span id="time-pm-in" class="time-value">
                                <?php echo $_SESSION['dtr']['pm_in'] ? date('h:i A', strtotime($_SESSION['dtr']['pm_in'])) : '--:--'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <button class="time-btn btn-pm-out w-100" onclick="timeInOut('pm_out')"
                                id="btn-pm-out" <?php echo ($_SESSION['dtr']['pm_out'] || !$_SESSION['dtr']['pm_in'] || $is_ot) ? 'disabled' : ''; ?>>
                            <i class="bi bi-moon-stars"></i> PM OUT
                        </button>
                        <div class="time-display text-center">
                            <span id="time-pm-out" class="time-value">
                                <?php echo $_SESSION['dtr']['pm_out'] ? date('h:i A', strtotime($_SESSION['dtr']['pm_out'])) : '--:--'; ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Manual Time Entry -->
                <div class="manual-entry-section">
                    <h6>
                        <i class="bi bi-pencil-fill"></i> Manual Time Entry (Including Past Days)
                        <span id="edit-mode-badge" class="badge bg-warning ms-2" style="display: none; animation: pulse 2s infinite;">
                            <i class="bi bi-pencil-square"></i> EDIT MODE
                        </span>
                    </h6>
                    <p class="text-muted mb-3" style="font-size: 0.9rem;">
                        <span id="manual-entry-instruction">
                            Forgot to clock in/out? Enter times manually for any date.<br>
                            <i class="bi bi-info-circle text-primary"></i> <strong>Note:</strong> Existing records can only have remarks edited. To change times, delete the record and create a new entry.
                        </span>
                    </p>
                    
                    <!-- Date Selector -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label" style="font-size: 0.85rem; font-weight: 600; color: #1e40af;">
                                <i class="bi bi-calendar3"></i> Select Date
                            </label>
                            <input type="date" class="form-control manual-time-input" id="manual-date"
                                   value="<?php echo date('Y-m-d'); ?>" 
                                   min="<?php echo date('Y-m-d', strtotime('-3 months')); ?>">
                            <small class="text-muted">Can enter any date (past 3 months and beyond)</small>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" style="font-size: 0.85rem; font-weight: 600; color: #ef4444;">
                                <i class="bi bi-clock-fill"></i> Quick OT Entry
                            </label>
                            <div class="form-check" style="margin-top: 8px;">
                                <input class="form-check-input" type="checkbox" id="ot-day-checkbox" 
                                       style="border: 2px solid #ef4444; cursor: pointer;" onchange="toggleOTDay()">
                                <label class="form-check-label" for="ot-day-checkbox" style="cursor: pointer; font-weight: 600; color: #ef4444;">
                                    <i class="bi bi-exclamation-triangle-fill"></i> Mark as 16 Hour OT Day (Locked)
                                </label>
                            </div>
                            <small class="text-muted">
                                Check this to set 16 hours OT without times. <strong>Click Save to apply.</strong><br>
                                <i class="bi bi-info-circle-fill text-warning"></i> <strong>Note:</strong> OT days are permanently locked and cannot be edited with manual times. Delete the record if you need to change it.
                            </small>
                            
                            <div class="form-check" style="margin-top: 12px;">
                                <input class="form-check-input" type="checkbox" id="absent-day-checkbox" 
                                       style="border: 2px solid #f59e0b; cursor: pointer;" onchange="toggleAbsentDay()">
                                <label class="form-check-label" for="absent-day-checkbox" style="cursor: pointer; font-weight: 600; color: #f59e0b;">
                                    <i class="bi bi-x-circle-fill"></i> Mark as Absent
                                </label>
                            </div>
                            <small class="text-muted">
                                Check this to mark the day as absent (0 hours, no times required).
                            </small>
                        </div>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <label class="form-label" style="font-size: 0.85rem; font-weight: 600; color: #374151;">
                                <i class="bi bi-sunrise"></i> AM IN
                            </label>
                            <input type="time" class="form-control manual-time-input" id="manual-am-in"
                                   tabindex="1">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" style="font-size: 0.85rem; font-weight: 600; color: #374151;">
                                <i class="bi bi-box-arrow-right"></i> AM OUT
                            </label>
                            <input type="time" class="form-control manual-time-input" id="manual-am-out"
                                   tabindex="2">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" style="font-size: 0.85rem; font-weight: 600; color: #374151;">
                                <i class="bi bi-sunset"></i> PM IN
                            </label>
                            <input type="time" class="form-control manual-time-input" id="manual-pm-in"
                                   tabindex="3">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" style="font-size: 0.85rem; font-weight: 600; color: #374151;">
                                <i class="bi bi-moon-stars"></i> PM OUT
                            </label>
                            <input type="time" class="form-control manual-time-input" id="manual-pm-out"
                                   tabindex="4">
                        </div>
                    </div>
                    
                    <!-- Remarks for manual entry -->
                    <div class="row g-3 mt-2">
                        <div class="col-md-12">
                            <label class="form-label" style="font-size: 0.85rem; font-weight: 600; color: #374151;">
                                <i class="bi bi-chat-left-text"></i> Remarks (Optional)
                            </label>
                            <input type="text" class="form-control manual-time-input" id="manual-remarks"
                                   placeholder="e.g., Web development, Database testing...">
                        </div>
                    </div>
                    
                    <div class="text-center mt-3">
                        <button class="btn-save-manual" onclick="saveManualEntry()">
                            <i class="bi bi-save"></i> Save Manual Entry
                        </button>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="row g-3">
                    <?php if ($selectedMonth === date('Y-m')): ?>
                    <div class="col-md-6">
                        <div class="stats-card today" style="position: relative;">
                            <!-- Circular Progress Indicator -->
                            <svg class="progress-ring" width="50" height="50">
                                <circle class="progress-ring-circle" 
                                        stroke="#3b82f6" 
                                        stroke-width="4" 
                                        fill="transparent" 
                                        r="18" 
                                        cx="25" 
                                        cy="25"
                                        style="stroke-dasharray: 113.1; stroke-dashoffset: <?php echo 113.1 - (113.1 * min($today_hours / 8, 1)); ?>;" />
                            </svg>
                            
                            <h5><i class="bi bi-calendar-day"></i> Today's Hours</h5>
                            <p class="value" id="today-hours" data-target="<?php echo $today_hours; ?>"><?php echo formatHoursMinutes($today_hours); ?></p>
                            
                            <!-- Progress bar -->
                            <div class="hours-progress">
                                <div class="hours-progress-bar" style="width: <?php echo min(($today_hours / 8) * 100, 100); ?>%;" id="today-progress"></div>
                            </div>
                            <small class="text-muted" style="display: block; margin-top: 5px;">
                                <?php 
                                $remaining = max(0, 8 - $today_hours);
                                echo $remaining > 0 ? formatHoursMinutes($remaining) . ' to 8 hrs' : 'Target reached!';
                                ?>
                            </small>
                            
                            <?php if ($is_ot): ?>
                                <span class="ot-badge">
                                    <i class="bi bi-exclamation-triangle"></i> OVERTIME (>16hrs)
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="<?php echo ($selectedMonth === date('Y-m')) ? 'col-md-6' : 'col-md-12'; ?>">
                        <div class="stats-card monthly">
                            <h5><i class="bi bi-calendar-month"></i> <?php echo date('F Y', strtotime($selectedMonth . '-01')); ?></h5>
                            <p class="value" data-target="<?php echo $monthly['total_hours']; ?>"><?php echo formatHoursMinutes($monthly['total_hours']); ?></p>
                            <div style="font-size: 0.95rem; margin-top: 10px;">
                                <i class="bi bi-flag-fill"></i> OT Days: <strong><?php echo $monthly['ot_days']; ?></strong>
                            </div>
                            
                            <!-- Monthly progress -->
                            <?php 
                            $expectedHours = date('d', strtotime($selectedMonth . '-01')) * 8;
                            $monthProgress = $expectedHours > 0 ? min(($monthly['total_hours'] / $expectedHours) * 100, 100) : 0;
                            ?>
                            <div class="hours-progress">
                                <div class="hours-progress-bar" style="width: <?php echo $monthProgress; ?>%;" id="month-progress"></div>
                            </div>
                            <small class="text-muted" style="display: block; margin-top: 5px;">
                                <?php echo round($monthProgress); ?>% of expected hours
                            </small>
                        </div>
                    </div>
                </div>

                <!-- OJT Progress Card -->
                <div class="row g-3 mt-2">
                    <div class="col-12">
                        <div class="stats-card ojt">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <h5 style="margin: 0;"><i class="bi bi-mortarboard-fill"></i> OJT Progress</h5>
                                <button onclick="editOJTRequirement()" class="btn btn-sm" style="background: rgba(255,255,255,0.2); color: white; border: none; padding: 5px 12px; border-radius: 5px; font-size: 0.85rem;">
                                    <i class="bi bi-gear-fill"></i> Set Target
                                </button>
                            </div>
                            <?php 
                            $ojt_completed = $ojt_progress['total_hours'];
                            ?>
                            <!-- Required Hours Input (hidden by default) -->
                            <div id="ojt-requirement-editor" style="display: none; background: rgba(255,255,255,0.15); padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                                <label style="display: block; margin-bottom: 8px; font-weight: 600;">
                                    <i class="bi bi-target"></i> Required OJT Hours:
                                </label>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <input type="number" id="ojt-required-input" min="1" max="10000" 
                                           onkeypress="if(event.key==='Enter') saveOJTRequirement()"
                                           style="flex: 1; padding: 8px 12px; border: 2px solid rgba(255,255,255,0.3); border-radius: 5px; background: rgba(255,255,255,0.9); color: #333; font-weight: bold; font-size: 1rem;" 
                                           placeholder="486">
                                    <button onclick="saveOJTRequirement()" class="btn btn-sm" style="background: rgba(255,255,255,0.9); color: #7c3aed; border: none; padding: 8px 16px; border-radius: 5px; font-weight: bold;">
                                        <i class="bi bi-check-lg"></i> Save
                                    </button>
                                    <button onclick="cancelEditOJTRequirement()" class="btn btn-sm" style="background: rgba(255,255,255,0.2); color: white; border: none; padding: 8px 16px; border-radius: 5px;">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>
                                <small style="opacity: 0.9; display: block; margin-top: 5px;">
                                    <i class="bi bi-info-circle"></i> Common values: 240 hrs (3 months), 486 hrs (6 months), 600 hrs
                                </small>
                            </div>
                            
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                                <div>
                                    <p class="value" style="font-size: 2rem; margin: 0;" id="ojt-completed-display">
                                        <?php echo formatHoursMinutes($ojt_completed); ?>
                                    </p>
                                    <small style="opacity: 0.9;">of <span id="ojt-required-display">486</span> hours required</small>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-size: 2.5rem; font-weight: bold; margin: 0;" id="ojt-percentage-display">
                                        0%
                                    </div>
                                    <small style="opacity: 0.9;">Complete</small>
                                </div>
                            </div>
                            
                            <!-- OJT Progress Bar -->
                            <div class="hours-progress" style="margin-top: 15px; height: 20px;">
                                <div class="hours-progress-bar" style="width: 0%; background: rgba(255,255,255,0.9); border-radius: 10px; transition: width 0.5s ease;" id="ojt-progress-bar"></div>
                            </div>
                            
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px; flex-wrap: wrap; gap: 10px;">
                                <small style="opacity: 0.9;">
                                    <i class="bi bi-calendar-check"></i> Total Days: <strong><?php echo $ojt_progress['total_days']; ?></strong>
                                </small>
                                <small style="opacity: 0.9;" id="ojt-remaining-display">
                                    <i class="bi bi-hourglass-split"></i> Remaining: <strong>--</strong>
                                </small>
                            </div>
                            
                            <div id="ojt-completion-badge" style="display: none; background: rgba(255,255,255,0.2); padding: 10px; border-radius: 8px; margin-top: 10px; text-align: center;">
                                <i class="bi bi-trophy-fill" style="font-size: 1.5rem;"></i>
                                <div style="font-weight: bold; margin-top: 5px;">Congratulations! You've completed your OJT hours!</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Mini Calendar Widget -->
                <div class="mini-calendar">
                    <h6 style="margin: 0 0 5px 0; color: var(--text-primary); font-weight: bold;">
                        <i class="bi bi-calendar3"></i> <?php echo date('F Y', strtotime($selectedMonth . '-01')); ?> Quick View
                    </h6>
                    <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; font-size: 0.7rem; text-align: center; margin-bottom: 5px; color: var(--text-secondary);">
                        <div>S</div><div>M</div><div>T</div><div>W</div><div>T</div><div>F</div><div>S</div>
                    </div>
                    <div class="calendar-grid" id="mini-calendar-grid">
                        <?php
                        $firstDay = date('N', strtotime($selectedMonth . '-01')) % 7; // 0 = Sunday
                        $daysInMonth = date('t', strtotime($selectedMonth . '-01'));
                        $today_day = date('j');
                        $isCurrentMonth = ($selectedMonth === date('Y-m'));
                        
                        // Get holidays for the selected year
                        $selectedYear = date('Y', strtotime($selectedMonth . '-01'));
                        $holidays = getHolidays($selectedYear);
                        $holidayDays = [];
                        foreach ($holidays as $date => $name) {
                            if (date('Y-m', strtotime($date)) === $selectedMonth) {
                                $holidayDays[(int)date('j', strtotime($date))] = $name;
                            }
                        }
                        
                        // Build array of days with records
                        $recordDays = [];
                        $otDays = [];
                        $otLockedDays = [];
                        $pastDays = [];
                        $futureDays = [];
                        $absentDays = [];
                        
                        foreach ($monthly_records as $record) {
                            $day = (int)date('j', strtotime($record['date']));
                            $recordDate = $record['date'];
                            $todayDate = date('Y-m-d');
                            
                            $recordDays[] = $day;
                            
                            // Check if absent
                            if (isset($record['is_absent']) && $record['is_absent']) {
                                $absentDays[] = $day;
                            }
                            
                            // Categorize by date relative to today
                            if ($recordDate < $todayDate) {
                                $pastDays[] = $day;
                            } elseif ($recordDate > $todayDate) {
                                $futureDays[] = $day;
                            }
                            
                            if ($record['is_overtime']) {
                                $otDays[] = $day;
                                // Check if it's OT locked (no times)
                                if ($record['daily_hours'] == 16.00 && 
                                    !$record['am_in'] && !$record['am_out'] && 
                                    !$record['pm_in'] && !$record['pm_out']) {
                                    $otLockedDays[] = $day;
                                }
                            }
                        }
                        
                        // Empty cells before first day
                        for ($i = 0; $i < $firstDay; $i++) {
                            echo '<div class="calendar-day disabled"></div>';
                        }
                        
                        // Days of the month
                        for ($day = 1; $day <= $daysInMonth; $day++) {
                            $classes = ['calendar-day'];
                            $content = $day;
                            $title = '';
                            
                            // Check if it's today (only in current month)
                            $isToday = $isCurrentMonth && ($day == $today_day);
                            
                            // Check if it's a holiday
                            $isHoliday = isset($holidayDays[$day]);
                            
                            if ($isToday) {
                                $classes[] = 'today';
                                $title = 'Today';
                            } elseif ($isHoliday) {
                                $classes[] = 'holiday-day';
                                $title = 'Holiday: ' . $holidayDays[$day];
                            } elseif (in_array($day, $absentDays)) {
                                $classes[] = 'absent-day';
                                $title = 'Absent (0 hours)';
                            } elseif (in_array($day, $otLockedDays)) {
                                $classes[] = 'ot-locked-day';
                                $title = 'OT Day (16hrs - Remarks Only)';
                                $content = '<span class="calendar-day-number">' . $day . '</span><i class="bi bi-lock-fill" style="font-size: 0.5rem; position: absolute; top: 2px; right: 2px;"></i>';
                            } elseif (in_array($day, $pastDays)) {
                                $classes[] = 'past-entry';
                                $title = 'Past Entry (Remarks Only)';
                            } elseif (in_array($day, $futureDays)) {
                                $classes[] = 'future-entry';
                                $title = 'Future Entry (Times Editable)';
                            }
                            
                            echo '<div class="' . implode(' ', $classes) . '" title="' . $title . '">' . $content . '</div>';
                        }
                        ?>
                    </div>
                    <div style="margin-top: 10px; font-size: 0.75rem; display: flex; gap: 12px; justify-content: center; color: var(--text-secondary); flex-wrap: wrap;">
                        <span><span style="display: inline-block; width: 12px; height: 12px; background: #3b82f6; border-radius: 3px; margin-right: 5px;"></span>Today</span>
                        <span><span style="display: inline-block; width: 12px; height: 12px; background: #ec4899; border-radius: 3px; margin-right: 5px;"></span>Holiday</span>
                        <span><span style="display: inline-block; width: 12px; height: 12px; background: #8b5cf6; border-radius: 3px; margin-right: 5px;"></span>Past</span>
                        <span><span style="display: inline-block; width: 12px; height: 12px; background: #06b6d4; border-radius: 3px; margin-right: 5px;"></span>Future</span>
                        <span><span style="display: inline-block; width: 12px; height: 12px; background: #eab308; border-radius: 3px; margin-right: 5px;"></span>Absent</span>
                        <span><span style="display: inline-block; width: 12px; height: 12px; background: #f97316; border-radius: 3px; margin-right: 5px;"></span><i class="bi bi-lock-fill" style="font-size: 0.6rem; margin-right: 2px;"></i>16hr OT</span>
                    </div>
                </div>

                <!-- Month Selector -->
                <div class="month-selector mb-4">
                    <label for="month-select" style="font-weight: 600; margin-right: 10px;">
                        <i class="bi bi-calendar3"></i> View Month:
                    </label>
                    <select id="month-select" class="form-select" style="display: inline-block; width: auto;" onchange="changeMonth(this.value)">
                        <?php
                        // Generate all months of 2026
                        for ($month = 1; $month <= 12; $month++) {
                            $monthDate = sprintf('2026-%02d', $month);
                            $monthName = date('F Y', strtotime($monthDate . '-01'));
                            $selected = ($monthDate === $selectedMonth) ? 'selected' : '';
                            echo "<option value='$monthDate' $selected>$monthName</option>";
                        }
                        ?>
                    </select>
                </div>

                <!-- Today's Log Table (Only show for current month) -->
                <?php if ($selectedMonth === date('Y-m')): ?>
                <div class="log-table">
                    <h5 class="mb-3"><i class="bi bi-table"></i> Today's Log</h5>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>AM IN</th>
                                    <th>AM OUT</th>
                                    <th>PM IN</th>
                                    <th>PM OUT</th>
                                    <th>Daily Hours</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?php echo date('Y-m-d'); ?></td>
                                    <td><?php echo $_SESSION['dtr']['am_in'] ? date('h:i A', strtotime($_SESSION['dtr']['am_in'])) : '--'; ?></td>
                                    <td><?php echo $_SESSION['dtr']['am_out'] ? date('h:i A', strtotime($_SESSION['dtr']['am_out'])) : '--'; ?></td>
                                    <td><?php echo $_SESSION['dtr']['pm_in'] ? date('h:i A', strtotime($_SESSION['dtr']['pm_in'])) : '--'; ?></td>
                                    <td><?php echo $_SESSION['dtr']['pm_out'] ? date('h:i A', strtotime($_SESSION['dtr']['pm_out'])) : '--'; ?></td>
                                    <td id="table-hours">
                                        <strong><?php echo formatHoursMinutes($today_hours); ?></strong>
                                        <?php if ($today_hours == 16.00 && !$_SESSION['dtr']['am_in'] && !$_SESSION['dtr']['am_out'] && !$_SESSION['dtr']['pm_in'] && !$_SESSION['dtr']['pm_out']): ?>
                                            <br><small class="text-danger"><i class="bi bi-lock-fill"></i> Locked</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_ot): ?>
                                            <span class="badge bg-danger"><i class="bi bi-exclamation-triangle"></i> OT</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Normal</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Monthly Records Table -->
                <?php if (count($monthly_records) > 0): ?>
                <div class="log-table mt-4">
                    <h5 class="mb-3">
                        <i class="bi bi-calendar-range"></i> <?php echo date('F Y', strtotime($selectedMonth . '-01')); ?> Records 
                        <span class="badge bg-primary"><?php echo count($monthly_records); ?> days</span>
                    </h5>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>AM IN</th>
                                    <th>AM OUT</th>
                                    <th>PM IN</th>
                                    <th>PM OUT</th>
                                    <th>Daily Hours</th>
                                    <th>Status</th>
                                    <th>Remarks</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($monthly_records as $record): ?>
                                <tr <?php echo $record['date'] === date('Y-m-d') ? 'class="table-active"' : ''; ?> 
                                    data-date="<?php echo $record['date']; ?>"
                                    data-remarks="<?php echo htmlspecialchars($record['remarks'], ENT_QUOTES); ?>"
                                    data-is-ot="<?php echo $record['is_overtime'] ? '1' : '0'; ?>"
                                    data-is-locked="<?php echo ($record['is_overtime'] && $record['daily_hours'] == 16.00 && !$record['am_in'] && !$record['am_out'] && !$record['pm_in'] && !$record['pm_out']) ? '1' : '0'; ?>">
                                    <td>
                                        <strong><?php echo date('M d, Y', strtotime($record['date'])); ?></strong>
                                        <?php if ($record['date'] === date('Y-m-d')): ?>
                                            <span class="badge bg-info ms-1">Today</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $record['am_in'] ? date('h:i A', strtotime($record['am_in'])) : '--'; ?></td>
                                    <td><?php echo $record['am_out'] ? date('h:i A', strtotime($record['am_out'])) : '--'; ?></td>
                                    <td><?php echo $record['pm_in'] ? date('h:i A', strtotime($record['pm_in'])) : '--'; ?></td>
                                    <td><?php echo $record['pm_out'] ? date('h:i A', strtotime($record['pm_out'])) : '--'; ?></td>
                                    <td>
                                        <strong><?php echo formatHoursMinutes($record['daily_hours']); ?></strong>
                                        <?php if ($record['daily_hours'] == 16.00 && !$record['am_in'] && !$record['am_out'] && !$record['pm_in'] && !$record['pm_out']): ?>
                                            <br><small class="text-danger"><i class="bi bi-lock-fill"></i> Locked</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (isset($record['is_absent']) && $record['is_absent']): ?>
                                            <span class="badge bg-warning"><i class="bi bi-x-circle-fill"></i> Absent</span>
                                        <?php elseif ($record['is_overtime']): ?>
                                            <span class="badge bg-danger"><i class="bi bi-exclamation-triangle"></i> OT</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Normal</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="<?php echo $record['remarks'] ? 'remarks-cell' : ''; ?>" 
                                        <?php if ($record['remarks']): ?>
                                        data-remarks="<?php echo htmlspecialchars($record['remarks'], ENT_QUOTES); ?>"
                                        onclick="showFullRemarks(this.dataset.remarks)" 
                                        title="Click to view full remarks"
                                        <?php endif; ?>>
                                        <small class="text-muted remarks-truncated">
                                            <?php 
                                            if ($record['remarks']) {
                                                $remarks = htmlspecialchars($record['remarks']);
                                                echo strlen($remarks) > 30 ? substr($remarks, 0, 30) . '...' : $remarks;
                                                if (strlen($remarks) > 30) {
                                                    echo '<i class="bi bi-chevron-double-right remarks-icon"></i>';
                                                }
                                            } else {
                                                echo '--';
                                            }
                                            ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php 
                                        // Check if this is an OT locked day (16 hours with no times)
                                        $isOTLocked = $record['is_overtime'] && $record['daily_hours'] == 16.00 && 
                                                     !$record['am_in'] && !$record['am_out'] && 
                                                     !$record['pm_in'] && !$record['pm_out'];
                                        ?>
                                        <button class="btn btn-sm btn-warning me-1" onclick="editRemarksOnly('<?php echo $record['date']; ?>', '<?php echo htmlspecialchars($record['remarks'], ENT_QUOTES); ?>', <?php echo $isOTLocked ? 'true' : 'false'; ?>)" title="Edit Remarks Only">
                                            <i class="bi bi-chat-left-text"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteRecord('<?php echo $record['date']; ?>')" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-warning mt-4" role="alert">
                    <i class="bi bi-exclamation-circle"></i> 
                    <strong>No records found for <?php echo date('F Y', strtotime($selectedMonth . '-01')); ?></strong>
                    <br>
                    <small>Select a different month or start adding time entries.</small>
                </div>
                <?php endif; ?>

                <!-- Monthly Report Section -->
                <?php if ($monthly_report): ?>
                <div class="report-section">
                    <h5 style="margin-bottom: 20px;">
                        <i class="bi bi-file-earmark-bar-graph"></i> Monthly Report - <?php echo $monthly_report['month_name']; ?>
                    </h5>
                    
                    <div class="report-grid">
                        <!-- Total Days Worked -->
                        <div class="report-card">
                            <h6><i class="bi bi-calendar-check"></i> Total Days</h6>
                            <div class="value"><?php echo $monthly_report['total_days']; ?></div>
                            <div class="sub-value">
                                <?php echo $monthly_report['normal_days']; ?> Normal / <?php echo $monthly_report['ot_days']; ?> OT
                            </div>
                        </div>
                        
                        <!-- Total Hours -->
                        <div class="report-card">
                            <h6><i class="bi bi-clock"></i> Total Hours</h6>
                            <div class="value"><?php echo formatHoursMinutes($monthly_report['total_hours']); ?></div>
                            <div class="sub-value">Rendered this month</div>
                        </div>
                        
                        <!-- Average Hours -->
                        <div class="report-card">
                            <h6><i class="bi bi-graph-up"></i> Average/Day</h6>
                            <div class="value"><?php echo formatHoursMinutes($monthly_report['avg_hours']); ?></div>
                            <div class="sub-value">Hours per day</div>
                        </div>
                        
                        <!-- Regular Hours -->
                        <div class="report-card">
                            <h6><i class="bi bi-check-circle"></i> Regular Hours</h6>
                            <div class="value"><?php echo formatHoursMinutes($monthly_report['regular_hours']); ?></div>
                            <div class="sub-value">Normal workdays</div>
                        </div>
                        
                        <!-- OT Hours -->
                        <div class="report-card">
                            <h6><i class="bi bi-exclamation-triangle"></i> OT Hours</h6>
                            <div class="value" style="color: #ef4444;"><?php echo formatHoursMinutes($monthly_report['ot_hours']); ?></div>
                            <div class="sub-value">Overtime hours</div>
                        </div>
                        
                        <!-- Date Range -->
                        <div class="report-card">
                            <h6><i class="bi bi-calendar-range"></i> Date Range</h6>
                            <div style="font-size: 0.95rem; font-weight: 600; color: #1f2937; margin-top: 8px;">
                                <?php echo date('M d', strtotime($monthly_report['first_entry'])); ?> - 
                                <?php echo date('M d, Y', strtotime($monthly_report['last_entry'])); ?>
                            </div>
                            <div class="sub-value">Entry period</div>
                        </div>
                    </div>
                    
                    <!-- Summary Box -->
                    <div class="alert alert-info mt-3" role="alert" style="background: #dbeafe; border: 1px solid #3b82f6; color: #1e40af;">
                        <h6 class="alert-heading" style="color: #1e40af;"><i class="bi bi-info-circle-fill"></i> Summary</h6>
                        <p class="mb-0" style="font-size: 0.95rem;">
                            <strong>Period:</strong> <?php echo $monthly_report['month_name']; ?> • 
                            <strong>Total Days:</strong> <?php echo $monthly_report['total_days']; ?> • 
                            <strong>Total Hours:</strong> <?php echo formatHoursMinutes($monthly_report['total_hours']); ?> • 
                            <strong>OT Days:</strong> <?php echo $monthly_report['ot_days']; ?> • 
                            <strong>Average:</strong> <?php echo formatHoursMinutes($monthly_report['avg_hours']); ?>/day
                        </p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Clear Button (Only for Current Month) -->
                <?php if ($selectedMonth === date('Y-m')): ?>
                <div class="text-center mt-4">
                    <button class="btn-clear" onclick="clearToday()">
                        <i class="bi bi-trash"></i> Clear Today's Record
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Remarks Modal -->
        <div class="modal fade" id="remarksModal" tabindex="-1" aria-labelledby="remarksModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="remarksModalLabel">
                            <i class="bi bi-chat-left-text-fill"></i> Full Remarks
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p id="remarksModalContent" style="margin: 0; white-space: pre-wrap; word-wrap: break-word;"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> Close
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Floating Action Buttons -->
        <div class="fab-container" id="fab-container">
            <button class="fab-button fab-mini" onclick="scrollToManualEntry()" title="Manual Entry">
                <i class="bi bi-pencil-fill"></i>
            </button>
            <button class="fab-button fab-mini" onclick="scrollToReports()" title="View Reports">
                <i class="bi bi-graph-up"></i>
            </button>
            <button class="fab-button" onclick="toggleFAB()" title="Quick Actions">
                <i class="bi bi-plus-lg" id="fab-icon"></i>
            </button>
        </div>

        <!-- Mobile Bottom Navigation -->
        <div class="mobile-nav d-md-none">
            <a href="#" class="mobile-nav-item active" onclick="scrollToTop(); return false;">
                <i class="bi bi-clock-history"></i>
                <span>Today</span>
            </a>
            <a href="#" class="mobile-nav-item" onclick="scrollToManualEntry(); return false;">
                <i class="bi bi-pencil-square"></i>
                <span>Manual</span>
            </a>
            <a href="#" class="mobile-nav-item" onclick="scrollToRecords(); return false;">
                <i class="bi bi-table"></i>
                <span>Records</span>
            </a>
            <a href="#" class="mobile-nav-item" onclick="scrollToReports(); return false;">
                <i class="bi bi-graph-up-arrow"></i>
                <span>Report</span>
            </a>
        </div>

        <div class="footer-text">
            <i class="bi bi-info-circle"></i> • For personal use only • 
        </div>
    </div>

    <!-- Bootstrap JS Bundle (Required for Modal) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Dark Mode Toggle
        function toggleDarkMode() {
            document.body.classList.toggle('dark-mode');
            const isDark = document.body.classList.contains('dark-mode');
            
            // Update icon and text
            const icon = document.getElementById('dark-mode-icon');
            const text = document.getElementById('dark-mode-text');
            
            if (isDark) {
                icon.className = 'bi bi-sun-fill';
                text.textContent = 'Light';
                localStorage.setItem('darkMode', 'enabled');
            } else {
                icon.className = 'bi bi-moon-stars-fill';
                text.textContent = 'Dark';
                localStorage.setItem('darkMode', 'disabled');
            }
        }
        
        // Initialize dark mode from localStorage
        document.addEventListener('DOMContentLoaded', function() {
            const darkMode = localStorage.getItem('darkMode');
            if (darkMode === 'enabled') {
                document.body.classList.add('dark-mode');
                document.getElementById('dark-mode-icon').className = 'bi bi-sun-fill';
                document.getElementById('dark-mode-text').textContent = 'Light';
            }
        });
        
        // Clear edit mode indicator
        function clearEditMode() {
            document.getElementById('edit-mode-badge').style.display = 'none';
            document.getElementById('edit-mode-badge').className = 'badge bg-warning ms-2';
            document.getElementById('edit-mode-badge').innerHTML = '<i class="bi bi-pencil-square"></i> EDIT MODE';
            document.getElementById('manual-entry-instruction').innerHTML = 'Forgot to clock in/out? Enter times manually for any date.<br><i class="bi bi-info-circle text-primary"></i> <strong>Note:</strong> Existing records can only have remarks edited. To change times, delete the record and create a new entry.';
            
            // Re-enable date input
            document.getElementById('manual-date').disabled = false;
            
            // Re-enable OT checkbox
            const otCheckbox = document.getElementById('ot-day-checkbox');
            otCheckbox.disabled = false;
            
            // Re-enable time inputs
            const timeInputs = [
                document.getElementById('manual-am-in'),
                document.getElementById('manual-am-out'),
                document.getElementById('manual-pm-in'),
                document.getElementById('manual-pm-out')
            ];
            
            timeInputs.forEach(input => {
                input.disabled = false;
                input.style.opacity = '1';
                input.style.cursor = 'text';
            });
            
            // Reset remarks field styling
            const remarksField = document.getElementById('manual-remarks');
            remarksField.disabled = false;
            remarksField.style.boxShadow = '';
            remarksField.style.borderColor = '';
            
            // Also reset OT checkbox if it was accidentally checked
            if (otCheckbox.checked) {
                otCheckbox.checked = false;
                toggleOTDay(); // This will re-enable the time inputs
            }
        }
        
        // Show full remarks in modal
        function showFullRemarks(remarks) {
            console.log('showFullRemarks called with:', remarks);
            if (!remarks || remarks === '' || remarks === '--') {
                console.log('No remarks to show');
                return;
            }
            
            const modalContent = document.getElementById('remarksModalContent');
            if (!modalContent) {
                console.error('remarksModalContent element not found');
                return;
            }
            
            modalContent.textContent = remarks;
            
            const modalElement = document.getElementById('remarksModal');
            if (!modalElement) {
                console.error('remarksModal element not found');
                return;
            }
            
            console.log('Showing modal...');
            const modal = new bootstrap.Modal(modalElement);
            modal.show();
        }
        
        // Change month view
        function changeMonth(month) {
            window.location.href = 'index.php?month=' + month;
        }
        
        // Time In/Out handler
        function timeInOut(type) {
            const btn = document.getElementById('btn-' + type.replace('_', '-'));
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Recording...';
            
            const icons = {
                'am_in': 'sunrise',
                'am_out': 'box-arrow-right',
                'pm_in': 'sunset',
                'pm_out': 'moon-stars'
            };
            
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=time_in_out&type=' + type
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Debug: Log the time received from server
                    console.log('Time from server:', data.time);
                    
                    // Update time display
                    const timeStr = formatTime(data.time);
                    console.log('Formatted time:', timeStr);
                    
                    document.getElementById('time-' + type.replace('_', '-')).textContent = timeStr;
                    
                    // Update button
                    btn.innerHTML = `<i class="bi bi-${icons[type]}"></i> ${type.toUpperCase().replace('_', ' ')}`;
                    
                    // Enable next button
                    enableNextButton(type);
                    
                    // Update calculations and table
                    updateCalculations(data.data);
                    updateTableDisplay(data.data);
                    
                    // Mark next action button
                    markNextActionButton();
                    
                    // Success feedback
                    showToast('Time recorded: ' + timeStr, 'success');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                btn.disabled = false;
                btn.innerHTML = `<i class="bi bi-${icons[type]}"></i> ${type.toUpperCase().replace('_', ' ')}`;
                showToast('Error recording time', 'error');
            });
        }

        // Enable next button in sequence
        function enableNextButton(type) {
            const sequence = {
                'am_in': 'am-out',
                'am_out': 'pm-in',
                'pm_in': 'pm-out'
            };
            
            if (sequence[type]) {
                const nextBtn = document.getElementById('btn-' + sequence[type]);
                if (nextBtn) {
                    nextBtn.disabled = false;
                    // Add pulse effect to next button
                    setTimeout(() => {
                        nextBtn.classList.add('next-action');
                    }, 300);
                }
            }
        }

        // Format time to 12-hour format
        function formatTime(time24) {
            if (!time24) return '--:--';
            const [hours, minutes, seconds] = time24.split(':');
            const hour = parseInt(hours);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            const hour12 = hour % 12 || 12;
            return hour12 + ':' + minutes + ' ' + ampm;
        }

        // Update calculations
        function updateCalculations(data) {
            let totalHours = 0;
            let isOTLocked = false;
            
            // Check if it's an OT locked day (16 hours with no times)
            if (!data.am_in && !data.am_out && !data.pm_in && !data.pm_out) {
                // Check if it's explicitly an OT day by checking daily_hours or is_overtime
                if (data.daily_hours == 16 || data.is_overtime) {
                    totalHours = 16;
                    isOTLocked = true;
                }
            } else {
                // Calculate AM hours
                if (data.am_in && data.am_out) {
                    totalHours += calculateHoursDiff(data.am_in, data.am_out);
                }
                
                // Calculate PM hours
                if (data.pm_in && data.pm_out) {
                    totalHours += calculateHoursDiff(data.pm_in, data.pm_out);
                }
            }
            
            // Format hours to "X hrs Y mins"
            const formattedHours = formatHoursMinutesJS(totalHours);
            
            // Update displays
            document.getElementById('today-hours').textContent = formattedHours;
            
            // Update table hours with locked indicator if OT locked
            if (isOTLocked) {
                document.getElementById('table-hours').innerHTML = '<strong>' + formattedHours + '</strong><br><small class="text-danger"><i class="bi bi-lock-fill"></i> Locked</small>';
            } else {
                document.getElementById('table-hours').innerHTML = '<strong>' + formattedHours + '</strong>';
            }
            
            // Update OT badge
            const todayCard = document.querySelector('.stats-card.today');
            const existingBadge = todayCard.querySelector('.ot-badge');
            
            if (totalHours >= 16 && (totalHours > 16 || isOTLocked)) {
                if (!existingBadge) {
                    const badge = document.createElement('span');
                    badge.className = 'ot-badge';
                    badge.innerHTML = '<i class="bi bi-exclamation-triangle"></i> ' + (isOTLocked ? 'OT DAY (16hrs Locked)' : 'OVERTIME (>16hrs)');
                    todayCard.appendChild(badge);
                }
                
                // Update table status
                const statusCell = document.querySelector('tbody td:last-child');
                statusCell.innerHTML = '<span class="badge bg-danger"><i class="bi bi-exclamation-triangle"></i> OT</span>';
            } else {
                if (existingBadge) {
                    existingBadge.remove();
                }
                
                const statusCell = document.querySelector('tbody td:last-child');
                statusCell.innerHTML = '<span class="badge bg-success">Normal</span>';
            }
        }

        // Calculate hours difference
        function calculateHoursDiff(time1, time2) {
            const [h1, m1] = time1.split(':').map(Number);
            const [h2, m2] = time2.split(':').map(Number);
            
            const minutes1 = h1 * 60 + m1;
            const minutes2 = h2 * 60 + m2;
            
            return (minutes2 - minutes1) / 60;
        }
        
        // Format hours to "X hrs Y mins"
        function formatHoursMinutesJS(decimalHours) {
            if (decimalHours === 0) return '0 hrs 0 mins';
            
            let hours = Math.floor(decimalHours);
            let minutes = Math.round((decimalHours - hours) * 60);
            
            // Handle rounding edge case
            if (minutes >= 60) {
                hours++;
                minutes = 0;
            }
            
            return hours + ' hrs ' + minutes + ' mins';
        }

        // Save remarks
        function saveRemarks() {
            const remarks = document.getElementById('remarks-input').value;
            
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=save_remarks&remarks=' + encodeURIComponent(remarks)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Remarks saved successfully', 'success');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error saving remarks', 'error');
            });
        }

        // Toggle OT Day feature
        function toggleOTDay() {
            const isOT = document.getElementById('ot-day-checkbox').checked;
            const isAbsent = document.getElementById('absent-day-checkbox').checked;
            
            const timeInputs = [
                document.getElementById('manual-am-in'),
                document.getElementById('manual-am-out'),
                document.getElementById('manual-pm-in'),
                document.getElementById('manual-pm-out')
            ];
            
            // Check if we're in locked edit mode (badge is visible)
            const editBadge = document.getElementById('edit-mode-badge');
            const isLockedMode = editBadge && editBadge.style.display !== 'none';
            
            if (isOT) {
                // Uncheck absent if checked
                if (isAbsent) {
                    document.getElementById('absent-day-checkbox').checked = false;
                }
                
                // Disable and clear time inputs (preview only - not saved yet)
                timeInputs.forEach(input => {
                    input.disabled = true;
                    input.value = '';
                    input.style.opacity = '0.5';
                });
                if (!isLockedMode) {
                    showToast('Preview: Will log 16 hours when saved (times locked)', 'info');
                }
            } else {
                // Only enable time inputs if NOT in locked edit mode
                if (!isLockedMode && !isAbsent) {
                    timeInputs.forEach(input => {
                        input.disabled = false;
                        input.style.opacity = '1';
                    });
                }
            }
        }
        
        // Toggle absent day
        function toggleAbsentDay() {
            const isAbsent = document.getElementById('absent-day-checkbox').checked;
            const isOT = document.getElementById('ot-day-checkbox').checked;
            
            const timeInputs = [
                document.getElementById('manual-am-in'),
                document.getElementById('manual-am-out'),
                document.getElementById('manual-pm-in'),
                document.getElementById('manual-pm-out')
            ];
            
            // Check if we're in locked edit mode
            const editBadge = document.getElementById('edit-mode-badge');
            const isLockedMode = editBadge && editBadge.style.display !== 'none';
            
            if (isAbsent) {
                // Uncheck OT if checked
                if (isOT) {
                    document.getElementById('ot-day-checkbox').checked = false;
                }
                
                // Disable and clear time inputs
                timeInputs.forEach(input => {
                    input.disabled = true;
                    input.value = '';
                    input.style.opacity = '0.5';
                });
                
                if (!isLockedMode) {
                    showToast('Preview: Will mark as absent (0 hours)', 'info');
                }
            } else {
                // Only enable time inputs if NOT in locked edit mode and OT not checked
                if (!isLockedMode && !isOT) {
                    timeInputs.forEach(input => {
                        input.disabled = false;
                        input.style.opacity = '1';
                    });
                }
            }
        }
        
        // Save manual time entry
        async function saveManualEntry() {
            // Clear edit mode indicator when saving
            document.getElementById('edit-mode-badge').style.display = 'none';
            document.getElementById('manual-entry-instruction').innerHTML = 'Forgot to clock in/out? Enter times manually for any date.<br><i class="bi bi-info-circle text-primary"></i> <strong>Note:</strong> Existing records can only have remarks edited. To change times, delete the record and create a new entry.';
            
            const entryDate = document.getElementById('manual-date').value;
            const isOTDay = document.getElementById('ot-day-checkbox').checked;
            const isAbsentDay = document.getElementById('absent-day-checkbox').checked;
            const amIn = document.getElementById('manual-am-in').value;
            const amOut = document.getElementById('manual-am-out').value;
            const pmIn = document.getElementById('manual-pm-in').value;
            const pmOut = document.getElementById('manual-pm-out').value;
            const remarks = document.getElementById('manual-remarks').value;
            
            // Check if time inputs are disabled (indicates locked/edit mode)
            const timeInputsDisabled = document.getElementById('manual-am-in').disabled;
            
            // Check if this date has an existing record - use server check for reliability
            const existingRecord = await checkDateExistsInDB(entryDate);
            
            // Check if entry date is in the future
            const today = '<?php echo date('Y-m-d'); ?>';
            const isFutureDate = entryDate > today;
            
            // If record exists (and not future date), FORCE remarks-only mode regardless of input
            const isRemarksOnlyEdit = (existingRecord.exists && !isFutureDate) || (timeInputsDisabled && !amIn && !amOut && !pmIn && !pmOut);
            
            // Block any attempt to save times on existing records (except future dates)
            if (existingRecord.exists && !isFutureDate && (amIn || amOut || pmIn || pmOut)) {
                showToast('Cannot modify times on existing records! Only remarks can be edited. Delete the record first if you need to change times.', 'error');
                return;
            }
            
            // Block any attempt to change OT status on existing records (past or future)
            if (existingRecord.exists && existingRecord.isOT !== isOTDay) {
                const currentType = existingRecord.isOT ? 'OT day' : 'regular day';
                const attemptedType = isOTDay ? 'OT day' : 'regular day';
                showToast(`Cannot change ${currentType} to ${attemptedType}! Delete the record first if you need to change the day type.`, 'error');
                return;
            }
            
            // Block any attempt to change absent status on existing records (past or future)
            const existingIsAbsent = existingRecord.isAbsent || false;
            if (existingRecord.exists && existingIsAbsent !== isAbsentDay) {
                const currentType = existingIsAbsent ? 'absent day' : 'present day';
                const attemptedType = isAbsentDay ? 'absent day' : 'present day';
                showToast(`Cannot change ${currentType} to ${attemptedType}! Delete the record first if you need to change the day type.`, 'error');
                return;
            }
            
            // If in remarks-only edit mode, allow save without time validation
            if (!isRemarksOnlyEdit) {
                // If OT day or Absent day is checked, skip time validation
                if (!isOTDay && !isAbsentDay) {
                    // Validate at least one time field is filled
                    if (!amIn && !amOut && !pmIn && !pmOut) {
                        showToast('Please enter at least one time, check OT Day, or mark as Absent', 'error');
                        return;
                    }
                }
            } else {
                // Remarks-only edit: ensure we're actually in edit mode
                console.log('Remarks-only edit mode - allowing save for date:', entryDate);
            }
            
            // Re-enable date input before sending (in case it was disabled for remarks-only edit)
            document.getElementById('manual-date').disabled = false;
            document.getElementById('ot-day-checkbox').disabled = false;
            
            // Build form data
            const formData = new URLSearchParams();
            formData.append('action', 'manual_entry');
            formData.append('entry_date', entryDate);
            formData.append('is_ot_day', isOTDay.toString());
            formData.append('is_absent_day', isAbsentDay.toString());
            if (!isOTDay && !isAbsentDay) {
                if (amIn) formData.append('am_in', amIn);
                if (amOut) formData.append('am_out', amOut);
                if (pmIn) formData.append('pm_in', pmIn);
                if (pmOut) formData.append('pm_out', pmOut);
            }
            if (remarks) formData.append('remarks_manual', remarks);
            
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData.toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const isToday = entryDate === '<?php echo date('Y-m-d'); ?>';
                    
                    if (isAbsentDay) {
                        if (isToday) {
                            if (isRemarksOnlyEdit) {
                                showToast('Remarks updated for absent day! Refreshing...', 'success');
                            } else {
                                showToast('Absent day marked! Refreshing...', 'success');
                            }
                        } else {
                            if (isRemarksOnlyEdit) {
                                showToast('Remarks updated for absent day! Refreshing...', 'success');
                            } else {
                                showToast('Absent day marked! Refreshing...', 'success');
                            }
                        }
                        setTimeout(() => location.reload(), 1500);
                        return;
                    }
                    
                    if (isOTDay) {
                        if (isToday) {
                            if (isRemarksOnlyEdit) {
                                showToast('Remarks updated for OT day! Refreshing...', 'success');
                            } else {
                                showToast('16 Hour OT Day saved! Time tracking is now locked. Refreshing...', 'success');
                            }
                        } else {
                            if (isRemarksOnlyEdit) {
                                showToast('Remarks updated for OT day! Refreshing...', 'success');
                            } else {
                                showToast('16 Hour OT Day saved! Refreshing...', 'success');
                            }
                        }
                        setTimeout(() => location.reload(), 1500);
                        return;
                    }
                    
                    if (isToday) {
                        if (isRemarksOnlyEdit) {
                            showToast('Remarks updated successfully! Refreshing...', 'success');
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            showToast('Today\'s times saved successfully', 'success');
                        
                        // Update time displays for today
                        if (data.data && data.data.am_in) {
                            document.getElementById('time-am-in').textContent = formatTime(data.data.am_in);
                            document.getElementById('btn-am-in').disabled = true;
                        }
                        if (data.data && data.data.am_out) {
                            document.getElementById('time-am-out').textContent = formatTime(data.data.am_out);
                            document.getElementById('btn-am-out').disabled = true;
                        }
                        if (data.data && data.data.pm_in) {
                            document.getElementById('time-pm-in').textContent = formatTime(data.data.pm_in);
                            document.getElementById('btn-pm-in').disabled = true;
                        }
                        if (data.data && data.data.pm_out) {
                            document.getElementById('time-pm-out').textContent = formatTime(data.data.pm_out);
                            document.getElementById('btn-pm-out').disabled = true;
                        }
                        
                        // Update calculations
                        if (data.data) {
                            updateCalculations(data.data);
                            updateTableDisplay(data.data);
                            markNextActionButton();
                        }
                        }
                    } else {
                        if (isRemarksOnlyEdit) {
                            showToast('Remarks updated successfully! Refreshing...', 'success');
                        } else {
                            showToast('Past day entry saved! Refreshing...', 'success');
                        }
                        setTimeout(() => location.reload(), 1500);
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error saving entry', 'error');
            });
        }

        // Update table display
        function updateTableDisplay(data) {
            const tableCells = document.querySelectorAll('tbody tr td');
            
            // Check if it's OT locked (no times)
            const isOTLocked = !data.am_in && !data.am_out && !data.pm_in && !data.pm_out && 
                               (data.daily_hours == 16 || data.is_overtime);
            
            if (isOTLocked) {
                // Show "--" for all times when OT locked
                tableCells[1].textContent = '--';
                tableCells[2].textContent = '--';
                tableCells[3].textContent = '--';
                tableCells[4].textContent = '--';
            } else {
                // Update with actual times
                tableCells[1].textContent = data.am_in ? formatTime(data.am_in) : '--';
                tableCells[2].textContent = data.am_out ? formatTime(data.am_out) : '--';
                tableCells[3].textContent = data.pm_in ? formatTime(data.pm_in) : '--';
                tableCells[4].textContent = data.pm_out ? formatTime(data.pm_out) : '--';
            }
        }

        // Check if a date is an OT day
        function checkIfOTDay(date) {
            // Check against the monthly records in the table
            const tableRows = document.querySelectorAll('.log-table tbody tr');
            for (let row of tableRows) {
                const rowDate = row.getAttribute('data-date');
                if (rowDate === date) {
                    const isLocked = row.getAttribute('data-is-locked') === '1';
                    if (isLocked) {
                        return true;
                    }
                }
            }
            return false;
        }
        
        // Check if a date has any existing record (OT or regular) - client-side check
        function getExistingRecordForDate(date) {
            const tableRows = document.querySelectorAll('.log-table tbody tr');
            for (let row of tableRows) {
                const rowDate = row.getAttribute('data-date');
                if (rowDate === date) {
                    return {
                        exists: true,
                        date: rowDate,
                        remarks: row.getAttribute('data-remarks') || '',
                        isOT: row.getAttribute('data-is-ot') === '1',
                        isLocked: row.getAttribute('data-is-locked') === '1'
                    };
                }
            }
            return { exists: false };
        }
        
        // Check if a date has existing record via server - for reliable checking across all dates
        async function checkDateExistsInDB(date) {
            try {
                const formData = new URLSearchParams();
                formData.append('action', 'check_date_exists');
                formData.append('check_date', date);
                
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: formData.toString()
                });
                
                const data = await response.json();
                if (data.success && data.exists) {
                    return {
                        exists: true,
                        date: data.date,
                        remarks: data.remarks || '',
                        isOT: data.isOT || false,
                        isLocked: data.isLocked || false
                    };
                }
                return { exists: false };
            } catch (error) {
                console.error('Error checking date:', error);
                return { exists: false };
            }
        }
        
        // Edit remarks only (for all existing records)
        function editRemarksOnly(date, remarks, isOTLocked = false) {
            console.log('Edit Remarks Only called:', {date, remarks, isOTLocked});
            
            // Show special edit mode indicator
            document.getElementById('edit-mode-badge').style.display = 'inline-block';
            if (isOTLocked) {
                document.getElementById('edit-mode-badge').className = 'badge bg-danger ms-2';
                document.getElementById('edit-mode-badge').innerHTML = '<i class="bi bi-lock-fill"></i> EDITING REMARKS (OT LOCKED)';
                document.getElementById('manual-entry-instruction').innerHTML = '<strong style="color: #ef4444;"><i class="bi bi-info-circle-fill"></i> Editing remarks for OT locked day - Times are permanently locked. Delete to recreate if needed:</strong>';
            } else {
                document.getElementById('edit-mode-badge').className = 'badge bg-info ms-2';
                document.getElementById('edit-mode-badge').innerHTML = '<i class="bi bi-chat-left-text"></i> EDITING REMARKS ONLY';
                document.getElementById('manual-entry-instruction').innerHTML = '<strong style="color: #0ea5e9;"><i class="bi bi-info-circle-fill"></i> Editing remarks only - Times cannot be changed. Delete the record and create new entry if you need to change times:</strong>';
            }
            
            // Scroll to manual entry section
            document.querySelector('.manual-entry-section').scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // Populate the date
            document.getElementById('manual-date').value = date;
            document.getElementById('manual-date').disabled = true;
            
            // Get all inputs
            const timeInputs = [
                document.getElementById('manual-am-in'),
                document.getElementById('manual-am-out'),
                document.getElementById('manual-pm-in'),
                document.getElementById('manual-pm-out')
            ];
            
            // Disable OT checkbox
            const otCheckbox = document.getElementById('ot-day-checkbox');
            otCheckbox.checked = isOTLocked;
            otCheckbox.disabled = true;
            
            // Clear and disable all time inputs
            timeInputs.forEach(input => {
                input.value = '';
                input.disabled = true;
                input.style.opacity = '0.5';
                input.style.cursor = 'not-allowed';
            });
            
            // Populate remarks and focus on it
            const remarksInput = document.getElementById('manual-remarks');
            remarksInput.value = remarks && remarks !== 'null' ? remarks : '';
            remarksInput.disabled = false;
            remarksInput.focus();
            
            // Highlight the manual entry section
            const section = document.querySelector('.manual-entry-section');
            section.style.boxShadow = isOTLocked ? '0 0 20px rgba(239, 68, 68, 0.5)' : '0 0 20px rgba(14, 165, 233, 0.5)';
            setTimeout(() => {
                section.style.boxShadow = '';
            }, 2000);
            
            // Show toast
            const dateFormatted = new Date(date).toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric' 
            });
            if (isOTLocked) {
                showToast(`Editing remarks for ${dateFormatted} (OT locked - times permanently locked)`, 'info');
            } else {
                showToast(`Editing remarks for ${dateFormatted} (times protected - delete to change)`, 'info');
            }
        }
        
        // Edit a record
        function editRecord(date, amIn, amOut, pmIn, pmOut, remarks, isOT) {
            console.log('Edit Record called:', {date, amIn, amOut, pmIn, pmOut, remarks, isOT});
            
            // Check if this is an OT day (locked) - should not reach here but safety check
            if (isOT && !amIn && !amOut && !pmIn && !pmOut) {
                editRemarksOnly(date, remarks);
                return;
            }
            
            // Show edit mode indicator
            document.getElementById('edit-mode-badge').style.display = 'inline-block';
            document.getElementById('manual-entry-instruction').innerHTML = '<strong style="color: #f59e0b;"><i class="bi bi-info-circle-fill"></i> Editing existing record - Adjust times as needed and click Save to update:</strong>';
            
            // Scroll to manual entry section
            document.querySelector('.manual-entry-section').scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // Populate the date
            document.getElementById('manual-date').value = date;
            
            // Get all time inputs
            const timeInputs = [
                document.getElementById('manual-am-in'),
                document.getElementById('manual-am-out'),
                document.getElementById('manual-pm-in'),
                document.getElementById('manual-pm-out')
            ];
            
            // Check if it's an OT day (16 hours with no times)
            if (isOT && !amIn && !amOut && !pmIn && !pmOut) {
                // OT Day - check the checkbox and lock times
                console.log('OT Day detected - locking times');
                document.getElementById('ot-day-checkbox').checked = true;
                toggleOTDay();
            } else {
                // Regular day - FORCE ENABLE all time inputs
                console.log('Regular day - enabling all inputs');
                document.getElementById('ot-day-checkbox').checked = false;
                
                // Force enable and make inputs editable
                timeInputs.forEach((input, idx) => {
                    input.disabled = false;
                    input.readOnly = false;
                    input.style.opacity = '1';
                    input.style.cursor = 'text';
                    input.style.pointerEvents = 'auto';
                    input.removeAttribute('readonly');
                    input.removeAttribute('disabled');
                });
                
                // Convert time values (HH:MM:SS to HH:MM, handle empty/null)
                const convertTime = (timeStr) => {
                    if (!timeStr || timeStr === 'null' || timeStr === '') return '';
                    return timeStr.substring(0, 5);
                };
                
                // Populate time values
                document.getElementById('manual-am-in').value = convertTime(amIn);
                document.getElementById('manual-am-out').value = convertTime(amOut);
                document.getElementById('manual-pm-in').value = convertTime(pmIn);
                document.getElementById('manual-pm-out').value = convertTime(pmOut);
                
                console.log('Populated values:', {
                    amIn: document.getElementById('manual-am-in').value,
                    amOut: document.getElementById('manual-am-out').value,
                    pmIn: document.getElementById('manual-pm-in').value,
                    pmOut: document.getElementById('manual-pm-out').value
                });
            }
            
            // Populate remarks
            document.getElementById('manual-remarks').value = remarks && remarks !== 'null' ? remarks : '';
            
            // Highlight the manual entry section temporarily
            const section = document.querySelector('.manual-entry-section');
            section.style.boxShadow = '0 0 20px rgba(59, 130, 246, 0.5)';
            setTimeout(() => {
                section.style.boxShadow = '';
            }, 2000);
            
            // Focus on first time input (or first empty one) to show it's editable
            setTimeout(() => {
                const firstInput = timeInputs[0];
                if (firstInput && !firstInput.disabled) {
                    firstInput.focus();
                    firstInput.click(); // Trigger time picker on some browsers
                }
            }, 500);
            
            // Show detailed toast message
            const dateFormatted = new Date(date).toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric' 
            });
            showToast(`Editing ${dateFormatted} - Adjust times and click Save`, 'success');
        }

        // Delete a record
        function deleteRecord(date) {
            const dateFormatted = new Date(date).toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric' 
            });
            
            if (!confirm(`Are you sure you want to delete the record for ${dateFormatted}? This cannot be undone.`)) {
                return;
            }
            
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=delete_record&date=' + encodeURIComponent(date)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Record deleted successfully. Refreshing...', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('Error deleting record', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error deleting record', 'error');
            });
        }

        // Clear today's record
        function clearToday() {
            if (!confirm('Are you sure you want to clear today\'s record? This cannot be undone.')) {
                return;
            }
            
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=clear_today'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Record cleared. Refreshing...', 'success');
                    setTimeout(() => location.reload(), 1000);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error clearing record', 'error');
            });
        }

        // Simple toast notification
        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 25px;
                background: ${type === 'success' ? '#10b981' : '#ef4444'};
                color: white;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
                z-index: 9999;
                font-weight: 600;
                animation: slideIn 0.3s ease-out;
            `;
            toast.textContent = message;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease-in';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // Add animation styles
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);

        // ============================================
        // VISUAL ENHANCEMENTS - New Functions
        // ============================================
        
        // FAB Toggle
        function toggleFAB() {
            const container = document.getElementById('fab-container');
            const icon = document.getElementById('fab-icon');
            container.classList.toggle('open');
            
            if (container.classList.contains('open')) {
                icon.style.transform = 'rotate(45deg)';
            } else {
                icon.style.transform = 'rotate(0deg)';
            }
        }
        
        // Scroll functions
        function scrollToTop() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        
        function scrollToManualEntry() {
            const element = document.querySelector('.manual-entry-section');
            if (element) {
                element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                // Highlight effect
                element.style.boxShadow = '0 0 30px rgba(59, 130, 246, 0.6)';
                setTimeout(() => {
                    element.style.boxShadow = '';
                }, 2000);
            }
        }
        
        function scrollToRecords() {
            const element = document.querySelector('.log-table');
            if (element) {
                element.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
        
        function scrollToReports() {
            const element = document.querySelector('.report-section');
            if (element) {
                element.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
        
        // Number Count-Up Animation
        function animateValue(element, start, end, duration) {
            if (!element) return;
            
            const range = end - start;
            const increment = range / (duration / 16.67); // 60fps
            let current = start;
            
            const timer = setInterval(() => {
                current += increment;
                if ((increment > 0 && current >= end) || (increment < 0 && current <= end)) {
                    current = end;
                    clearInterval(timer);
                }
                
                // Format the number
                const hours = Math.floor(current);
                const minutes = Math.round((current - hours) * 60);
                element.textContent = `${hours} hrs ${minutes} mins`;
            }, 16.67);
        }
        
        // Initialize count-up animations on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Animate stats values
            const todayEl = document.getElementById('today-hours');
            if (todayEl && todayEl.dataset.target) {
                animateValue(todayEl, 0, parseFloat(todayEl.dataset.target), 1000);
            }
            
            // Add ripple effect to buttons
            document.querySelectorAll('.time-btn').forEach(btn => {
                btn.classList.add('ripple');
            });
            
            // Mark next action button
            markNextActionButton();
            
            // Animate progress rings
            animateProgressRings();
            
            // Update OJT progress display
            updateOJTProgress();
            
            // Check if initial date has existing record on page load
            setTimeout(async function() {
                const initialDate = document.getElementById('manual-date').value;
                const initialRecord = await checkDateExistsInDB(initialDate);
                
                if (initialRecord.exists) {
                    // Trigger the date change logic to lock fields
                    const dateInput = document.getElementById('manual-date');
                    const changeEvent = new Event('change', { bubbles: true });
                    dateInput.dispatchEvent(changeEvent);
                }
            }, 100);
            
            // Add table row animations
            animateTableRows();
        });
        
        // Mark the next action button
        function markNextActionButton() {
            const amIn = document.getElementById('btn-am-in');
            const amOut = document.getElementById('btn-am-out');
            const pmIn = document.getElementById('btn-pm-in');
            const pmOut = document.getElementById('btn-pm-out');
            
            // Remove all next-action classes
            [amIn, amOut, pmIn, pmOut].forEach(btn => {
                if (btn) {
                    btn.classList.remove('next-action');
                    btn.classList.remove('completed');
                }
            });
            
            // Mark completed buttons
            if (amIn && amIn.disabled && !amIn.innerHTML.includes('Recording')) {
                amIn.classList.add('completed');
            }
            if (amOut && amOut.disabled && !amOut.innerHTML.includes('Recording')) {
                amOut.classList.add('completed');
            }
            if (pmIn && pmIn.disabled && !pmIn.innerHTML.includes('Recording')) {
                pmIn.classList.add('completed');
            }
            if (pmOut && pmOut.disabled && !pmOut.innerHTML.includes('Recording')) {
                pmOut.classList.add('completed');
            }
            
            // Find next action
            if (amIn && !amIn.disabled) {
                amIn.classList.add('next-action');
            } else if (amOut && !amOut.disabled) {
                amOut.classList.add('next-action');
            } else if (pmIn && !pmIn.disabled) {
                pmIn.classList.add('next-action');
            } else if (pmOut && !pmOut.disabled) {
                pmOut.classList.add('next-action');
            }
        }
        
        // Animate progress rings
        function animateProgressRings() {
            const rings = document.querySelectorAll('.progress-ring-circle');
            rings.forEach(ring => {
                const finalOffset = ring.style.strokeDashoffset;
                ring.style.strokeDashoffset = '113.1';
                setTimeout(() => {
                    ring.style.strokeDashoffset = finalOffset;
                }, 100);
            });
        }
        
        // OJT Progress Management
        function getOJTRequirement() {
            const stored = localStorage.getItem('ojt_required_hours');
            return stored ? parseInt(stored) : 486; // Default 486 hours
        }
        
        function updateOJTProgress() {
            const completed = <?php echo $ojt_progress['total_hours']; ?>;
            const required = getOJTRequirement();
            const percentage = Math.min((completed / required) * 100, 100);
            const remaining = Math.max(0, required - completed);
            
            // Update displays
            document.getElementById('ojt-required-display').textContent = required;
            document.getElementById('ojt-percentage-display').textContent = Math.round(percentage) + '%';
            document.getElementById('ojt-progress-bar').style.width = percentage + '%';
            
            // Format remaining hours
            const remainingHours = Math.floor(remaining);
            const remainingMins = Math.round((remaining - remainingHours) * 60);
            const remainingText = remaining > 0 
                ? `${remainingHours} hrs ${remainingMins} mins`
                : 'Complete!';
            
            // Update remaining display
            const remainingEl = document.getElementById('ojt-remaining-display');
            if (remaining > 0) {
                remainingEl.innerHTML = `<i class="bi bi-hourglass-split"></i> Remaining: <strong>${remainingText}</strong>`;
            } else {
                remainingEl.innerHTML = `<i class="bi bi-check-circle-fill"></i> <strong>OJT Complete!</strong>`;
            }
            
            // Show/hide completion badge
            const badge = document.getElementById('ojt-completion-badge');
            if (percentage >= 100) {
                badge.style.display = 'block';
            } else {
                badge.style.display = 'none';
            }
        }
        
        function editOJTRequirement() {
            const editor = document.getElementById('ojt-requirement-editor');
            const input = document.getElementById('ojt-required-input');
            const current = getOJTRequirement();
            
            input.value = current;
            editor.style.display = 'block';
            input.focus();
            input.select();
        }
        
        function saveOJTRequirement() {
            const input = document.getElementById('ojt-required-input');
            const value = parseInt(input.value);
            
            if (isNaN(value) || value < 1 || value > 10000) {
                showToast('Please enter a valid number between 1 and 10000', 'error');
                return;
            }
            
            localStorage.setItem('ojt_required_hours', value);
            updateOJTProgress();
            cancelEditOJTRequirement();
            showToast('OJT requirement updated successfully!', 'success');
        }
        
        function cancelEditOJTRequirement() {
            document.getElementById('ojt-requirement-editor').style.display = 'none';
        }
        
        // Animate table rows on scroll
        function animateTableRows() {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry, index) => {
                    if (entry.isIntersecting) {
                        setTimeout(() => {
                            entry.target.style.animation = `fadeInUp 0.5s ease-out ${index * 0.05}s both`;
                        }, 0);
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1 });
            
            document.querySelectorAll('.table tbody tr').forEach(row => {
                observer.observe(row);
            });
        }
        
        // Enhanced showToast with icons
        const originalShowToast = showToast;
        function showToast(message, type) {
            const toast = document.createElement('div');
            const icon = type === 'success' ? '✓' : type === 'error' ? '✗' : 'ℹ';
            const bgColor = type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6';
            
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 25px;
                background: ${bgColor};
                color: white;
                border-radius: 12px;
                box-shadow: 0 8px 24px rgba(0,0,0,0.3);
                z-index: 9999;
                font-weight: 600;
                animation: slideIn 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
                display: flex;
                align-items: center;
                gap: 10px;
                max-width: 400px;
            `;
            
            toast.innerHTML = `
                <span style="font-size: 1.2rem; background: rgba(255,255,255,0.2); border-radius: 50%; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;">${icon}</span>
                <span>${message}</span>
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.4s cubic-bezier(0.6, -0.28, 0.735, 0.045)';
                setTimeout(() => toast.remove(), 400);
            }, 3000);
        }

        // Auto-save remarks on blur
        document.getElementById('remarks-input').addEventListener('blur', function() {
            if (this.value) {
                saveRemarks();
            }
        });

        // When date changes in manual entry, clear time fields and check for existing record
        document.getElementById('manual-date').addEventListener('change', async function() {
            const selectedDate = this.value;
            const today = '<?php echo date('Y-m-d'); ?>';
            
            // Check if this date has an existing record - use server-side check for reliability
            const existingRecord = await checkDateExistsInDB(selectedDate);
            
            // Check if selected date is in the future
            const isFutureDate = selectedDate > today;
            
            if (existingRecord.exists && !isFutureDate) {
                // Record exists for past/today - lock time inputs, enable only remarks
                document.getElementById('edit-mode-badge').style.display = 'inline-block';
                if (existingRecord.isLocked) {
                    document.getElementById('edit-mode-badge').className = 'badge bg-danger ms-2';
                    document.getElementById('edit-mode-badge').innerHTML = '<i class="bi bi-lock-fill"></i> EDITING REMARKS (OT LOCKED)';
                    document.getElementById('manual-entry-instruction').innerHTML = '<strong style="color: #ef4444;"><i class="bi bi-info-circle-fill"></i> This date has an OT locked record. Only remarks can be edited. Delete to recreate if needed.</strong>';
                } else {
                    document.getElementById('edit-mode-badge').className = 'badge bg-info ms-2';
                    document.getElementById('edit-mode-badge').innerHTML = '<i class="bi bi-chat-left-text"></i> EDITING REMARKS ONLY';
                    document.getElementById('manual-entry-instruction').innerHTML = '<strong style="color: #0ea5e9;"><i class="bi bi-info-circle-fill"></i> This date has an existing record. Only remarks can be edited. Delete to change times.</strong>';
                }
                
                // Disable date input, time inputs, and OT checkbox
                document.getElementById('manual-date').disabled = true;
                document.getElementById('manual-am-in').disabled = true;
                document.getElementById('manual-am-out').disabled = true;
                document.getElementById('manual-pm-in').disabled = true;
                document.getElementById('manual-pm-out').disabled = true;
                document.getElementById('ot-day-checkbox').disabled = true;
                
                // Style disabled inputs
                ['manual-am-in', 'manual-am-out', 'manual-pm-in', 'manual-pm-out'].forEach(id => {
                    const el = document.getElementById(id);
                    el.style.opacity = '0.5';
                    el.style.cursor = 'not-allowed';
                });
                
                // Clear time fields
                document.getElementById('manual-am-in').value = '';
                document.getElementById('manual-am-out').value = '';
                document.getElementById('manual-pm-in').value = '';
                document.getElementById('manual-pm-out').value = '';
                
                // Load remarks and enable remarks field
                document.getElementById('manual-remarks').value = existingRecord.remarks;
                document.getElementById('manual-remarks').disabled = false;
                document.getElementById('manual-remarks').focus();
                
                // Uncheck OT and Absent checkboxes
                document.getElementById('ot-day-checkbox').checked = false;
                document.getElementById('absent-day-checkbox').checked = false;
                
                // Add glow effect
                const remarksField = document.getElementById('manual-remarks');
                const glowColor = existingRecord.isLocked ? '#ef4444' : '#0ea5e9';
                remarksField.style.boxShadow = `0 0 0 3px ${glowColor}40, 0 0 20px ${glowColor}60`;
                remarksField.style.borderColor = glowColor;
                
                showToast('Existing record detected. Only remarks can be edited.', 'info');
            } else if (existingRecord.exists && isFutureDate) {
                // Future date with existing record - allow editing but lock OT status
                clearEditMode();
                
                // Show info badge for future date
                document.getElementById('edit-mode-badge').style.display = 'inline-block';
                document.getElementById('edit-mode-badge').className = 'badge bg-success ms-2';
                document.getElementById('edit-mode-badge').innerHTML = '<i class="bi bi-calendar-check"></i> EDITING FUTURE ENTRY';
                document.getElementById('manual-entry-instruction').innerHTML = '<strong style="color: #10b981;"><i class="bi bi-info-circle-fill"></i> This is a future date entry. Times and remarks can be edited, but OT status is locked.</strong>';
                
                // Load existing data for editing
                const recordData = await checkDateExistsInDB(selectedDate);
                if (recordData.exists) {
                    document.getElementById('manual-remarks').value = recordData.remarks;
                    
                    // Set and lock OT/Absent checkbox to match existing record
                    document.getElementById('ot-day-checkbox').checked = recordData.isOT;
                    document.getElementById('ot-day-checkbox').disabled = true;
                    document.getElementById('absent-day-checkbox').checked = recordData.isAbsent || false;
                    document.getElementById('absent-day-checkbox').disabled = true;
                    
                    // Update time fields state based on OT/Absent status
                    if (recordData.isAbsent) {
                        toggleAbsentDay();
                    } else {
                        toggleOTDay();
                    }
                }
                
                showToast('Future date entry - times/remarks editable, OT status locked', 'success');
            } else {
                // No record exists - clear edit mode and enable all fields
                clearEditMode();
                
                // Uncheck OT and Absent day when changing date
                document.getElementById('ot-day-checkbox').checked = false;
                document.getElementById('absent-day-checkbox').checked = false;
                toggleOTDay();
                
                // Always clear all input fields when switching dates
                document.getElementById('manual-am-in').value = '';
                document.getElementById('manual-am-out').value = '';
                document.getElementById('manual-pm-in').value = '';
                document.getElementById('manual-pm-out').value = '';
                document.getElementById('manual-remarks').value = '';
                
                showToast('Selected: ' + selectedDate + '. Enter times for this date.', 'success');
            }
        });
    </script>
</body>
</html>
