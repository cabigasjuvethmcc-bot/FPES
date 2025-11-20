<?php
require_once '../config.php';
requireRole('admin');

// Set headers for file download
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="4fpes_database_export_' . date('Y-m-d_H-i-s') . '.sql"');
header('Cache-Control: must-revalidate');
header('Pragma: public');

try {
    // Get database connection details
    $host = DB_HOST;
    $dbname = DB_NAME;
    $username = DB_USER;
    $password = DB_PASS;

    // Create mysqldump command
    $command = "mysqldump --host={$host} --user={$username} --password={$password} --single-transaction --routines --triggers {$dbname}";
    
    // Execute mysqldump and output directly
    $output = shell_exec($command);
    
    if ($output === null) {
        // Fallback: Manual export using PHP
        echo "-- Faculty Performance Evaluation System Database Export\n";
        echo "-- Generated on: " . date('Y-m-d H:i:s') . "\n\n";
        
        // Get all tables
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($tables as $table) {
            echo "-- Table structure for table `{$table}`\n";
            echo "DROP TABLE IF EXISTS `{$table}`;\n";
            
            // Get CREATE TABLE statement
            $stmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
            $createTable = $stmt->fetch(PDO::FETCH_ASSOC);
            echo $createTable['Create Table'] . ";\n\n";
            
            // Get table data
            echo "-- Dumping data for table `{$table}`\n";
            $stmt = $pdo->query("SELECT * FROM `{$table}`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($rows)) {
                $columns = array_keys($rows[0]);
                $columnList = '`' . implode('`, `', $columns) . '`';
                
                foreach ($rows as $row) {
                    $values = array_map(function($value) use ($pdo) {
                        return $value === null ? 'NULL' : $pdo->quote($value);
                    }, array_values($row));
                    
                    echo "INSERT INTO `{$table}` ({$columnList}) VALUES (" . implode(', ', $values) . ");\n";
                }
            }
            echo "\n";
        }
    } else {
        echo $output;
    }
    
} catch (Exception $e) {
    // Clear any previous output
    if (ob_get_level()) {
        ob_clean();
    }
    
    // Reset headers for error display
    header('Content-Type: text/html');
    header_remove('Content-Disposition');
    
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Database Export Error</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; }
            .error { background: #f8d7da; color: #721c24; padding: 20px; border-radius: 5px; }
            .back-btn { background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 20px; }
        </style>
    </head>
    <body>
        <h1>Database Export Error</h1>
        <div class='error'>
            <strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "
        </div>
        <a href='admin.php' class='back-btn'>Back to Admin Dashboard</a>
    </body>
    </html>";
}
?>
