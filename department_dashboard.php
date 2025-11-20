<?php
require_once 'config.php';
requireRole('admin');

// Get current admin's department
$admin_department = $_SESSION['department'] ?? '';
$admin_name = $_SESSION['full_name'] ?? $_SESSION['username'];

// Get department statistics
try {
    // Get students in this department
    $stmt = $pdo->prepare("SELECT COUNT(*) as student_count FROM users WHERE role = 'student' AND department = ?");
    $stmt->execute([$admin_department]);
    $dept_students = $stmt->fetch()['student_count'] ?? 0;
    
    // Get faculty in this department
    $stmt = $pdo->prepare("SELECT COUNT(*) as faculty_count FROM users WHERE role = 'faculty' AND department = ?");
    $stmt->execute([$admin_department]);
    $dept_faculty = $stmt->fetch()['faculty_count'] ?? 0;
    
    // Get recent enrollments (last 30 days)
    $stmt = $pdo->prepare("SELECT COUNT(*) as recent_enrollments FROM users WHERE role = 'student' AND department = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute([$admin_department]);
    $recent_enrollments = $stmt->fetch()['recent_enrollments'] ?? 0;
    
    // Get recent students list
    $stmt = $pdo->prepare("SELECT u.*, s.student_id, s.year_level, s.program 
                           FROM users u 
                           LEFT JOIN students s ON u.id = s.user_id 
                           WHERE u.role = 'student' AND u.department = ? 
                           ORDER BY u.created_at DESC LIMIT 5");
    $stmt->execute([$admin_department]);
    $recent_students = $stmt->fetchAll();
    
    // Get department faculty
    $stmt = $pdo->prepare("SELECT u.*, f.employee_id, f.position 
                           FROM users u 
                           LEFT JOIN faculty f ON u.id = f.user_id 
                           WHERE u.role = 'faculty' AND u.department = ? 
                           ORDER BY u.full_name");
    $stmt->execute([$admin_department]);
    $dept_faculty_list = $stmt->fetchAll();

    // Department Rating: average of submitted evaluations' overall_rating for faculty in this department
    $dept_avg_rating = null; // null => N/A
    $dept_eval_count = 0;
    try {
        $stmt = $pdo->prepare("SELECT AVG(e.overall_rating) AS avg_rating, COUNT(*) AS cnt
                                FROM evaluations e
                                JOIN users u ON e.faculty_id = u.id
                                WHERE u.department = ? AND e.status = 'submitted'");
        $stmt->execute([$admin_department]);
        $row = $stmt->fetch();
        if ($row) {
            $dept_avg_rating = $row['avg_rating'] !== null ? (float)$row['avg_rating'] : null;
            $dept_eval_count = (int)($row['cnt'] ?? 0);
        }
    } catch (PDOException $e) {
        // Leave rating as N/A on error
        $dept_avg_rating = null; $dept_eval_count = 0;
    }
    
} catch (PDOException $e) {
    $dept_students = 0;
    $dept_faculty = 0;
    $recent_enrollments = 0;
    $recent_students = [];
    $dept_faculty_list = [];
}

// Get department color scheme
$dept_colors = [
    'Technology' => ['primary' => '#800020', 'secondary' => '#a0002a', 'accent' => '#d4002f'],
    'Education' => ['primary' => '#1e3a8a', 'secondary' => '#3b82f6', 'accent' => '#60a5fa'],
    'Business' => ['primary' => '#ea580c', 'secondary' => '#f97316', 'accent' => '#fb923c']
];
$colors = $dept_colors[$admin_department] ?? $dept_colors['Technology'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($admin_department); ?> Department Dashboard</title>
    <link rel="icon" href="img/loginlogo.png?v=2" type="image/png">
    <link rel="stylesheet" href="styles.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --dept-primary: <?php echo $colors['primary']; ?>;
            --dept-secondary: <?php echo $colors['secondary']; ?>;
            --dept-accent: <?php echo $colors['accent']; ?>;
            --neutral-bg: #f5f7fb;
            --card-bg: #ffffff;
            --muted: #6b7280;
            --text: #1f2937;
        }

        /* Header media (right-side image) */
        .header-media {
            position: absolute;
            right: 20px;
            top: 20px;
            width: 110px;
            height: 110px;
            opacity: 0.18;
            pointer-events: none;
        }
        .header-media img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            filter: drop-shadow(0 6px 16px rgba(0,0,0,0.2));
        }
        
        body { font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; }
        .dept-dashboard {
            min-height: 100vh;
            background: var(--neutral-bg);
            padding: 24px;
        }
        
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .dept-header {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 28px;
            margin-bottom: 28px;
            box-shadow: 0 10px 24px rgba(0,0,0,0.06);
            position: relative;
            overflow: hidden;
        }
        
        .dept-title {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 10px;
        }
        
        .dept-icon {
            width: 60px;
            height: 60px;
            background: var(--dept-primary);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        
        .dept-info h1 {
            margin: 0;
            color: var(--text);
            font-size: 2.5rem;
            font-weight: 700;
        }
        
        .dept-info p {
            margin: 6px 0 0 0;
            color: var(--muted);
            font-size: 1.05rem;
        }
        
        .quick-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            background: var(--dept-primary);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .action-btn:hover {
            background: var(--dept-secondary);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 28px;
            text-align: center;
            box-shadow: 0 10px 24px rgba(0,0,0,0.06);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            background: var(--dept-primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            margin: 0 auto 15px;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--dept-primary);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #666;
            font-weight: 500;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .content-card {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 28px;
            box-shadow: 0 10px 24px rgba(0,0,0,0.06);
        }
        
        .card-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .card-icon {
            width: 40px;
            height: 40px;
            background: var(--dept-primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }
        
        .card-title {
            margin: 0;
            color: var(--dept-primary);
            font-size: 1.3rem;
            font-weight: 600;
        }
        
        .student-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: #f3f4f6;
            border-radius: 12px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        
        .student-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        
        .student-avatar {
            width: 40px;
            height: 40px;
            background: var(--dept-accent);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }
        
        .student-info h4 {
            margin: 0 0 5px 0;
            color: #333;
        }
        
        .student-info p {
            margin: 0;
            color: #666;
            font-size: 0.9rem;
        }
        
        .faculty-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: #f3f4f6;
            border-radius: 12px;
            margin-bottom: 10px;
        }
        
        .faculty-avatar {
            width: 40px;
            height: 40px;
            background: var(--dept-secondary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }
        
        .no-data {
            text-align: center;
            color: #666;
            font-style: italic;
            padding: 20px;
        }
        
        /* Sidebar tweaks for department pages */
        .dashboard .sidebar {
            width: 200px;
            background: #ffffff;
            color: #374151;
            border-right: 1px solid #e5e7eb;
        }
        .dashboard .sidebar a { color: #4b5563; }
        .dashboard .sidebar a:hover { background: #f3f4f6; color: #111827; }
        .dashboard .main-content { background: var(--neutral-bg); }

        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .dept-title {
                flex-direction: column;
                text-align: center;
            }
            
            .quick-actions {
                justify-content: center;
            }
            .header-media { display: none; }
        }
    </style>
</head>
<body>
    <button class="sidebar-toggle" id="deptSidebarToggle" aria-label="Toggle navigation">☰ Menu</button>
    <div class="dashboard">
        <!-- Department Sidebar -->
        <div class="sidebar">
            <h2><?php echo htmlspecialchars($admin_department); ?> Admin</h2>
            <a href="department_dashboard.php">
                <i class="fas fa-gauge-high"></i> Dashboard
            </a>
            <a href="departments/<?php echo strtolower($admin_department); ?>/enrollment.php">
                <i class="fas fa-user-plus"></i> Enroll Student
            </a>
            <a href="bulk_upload.php">
                <i class="fas fa-file-upload"></i> Bulk Upload
            </a>
            <a href="departments/<?php echo strtolower($admin_department); ?>/student_management.php">
                <i class="fas fa-users-cog"></i> Manage Students
            </a>
            <a href="departments/<?php echo strtolower($admin_department); ?>/faculty_management.php">
                <i class="fas fa-chalkboard-teacher"></i> Manage Faculty
            </a>
            <a href="#" onclick="generateDeptReport()">
                <i class="fas fa-chart-bar"></i> Department Report
            </a>
            <button class="logout-btn" onclick="logout()"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>

        <div class="main-content">
            <div class="dept-dashboard">
                <div class="dashboard-container">
            <!-- Department Header -->
            <div class="dept-header">
                <div class="dept-title">
                    <div class="dept-icon">
                        <?php if ($admin_department === 'Technology'): ?>
                            <i class="fas fa-laptop-code"></i>
                        <?php elseif ($admin_department === 'Education'): ?>
                            <i class="fas fa-graduation-cap"></i>
                        <?php elseif ($admin_department === 'Business'): ?>
                            <i class="fas fa-briefcase"></i>
                        <?php else: ?>
                            <i class="fas fa-building"></i>
                        <?php endif; ?>
                    </div>
                    <div class="dept-info">
                        <h1><?php echo htmlspecialchars($admin_department); ?> Department</h1>
                        <p>Welcome back, <?php echo htmlspecialchars($admin_name); ?></p>
                    </div>
                </div>
                <div class="header-media">
                    <img src="assets/department-hero.svg" alt="Department visual" loading="lazy">
                </div>
                
                <!-- Removed header quick actions to avoid duplicate navigation; sidebar now handles primary actions. -->
            </div>

            <!-- Statistics Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-number"><?php echo $dept_students; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div class="stat-number"><?php echo $dept_faculty; ?></div>
                    <div class="stat-label">Faculty Members</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-number"><?php echo $recent_enrollments; ?></div>
                    <div class="stat-label">New Enrollments (30 days)</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="stat-number">
                        <?php echo $dept_avg_rating !== null ? number_format($dept_avg_rating, 1) : 'N/A'; ?>
                    </div>
                    <div class="stat-label">Department Rating<?php if ($dept_eval_count>0): ?> (<?php echo (int)$dept_eval_count; ?> evals)<?php endif; ?></div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Recent Students -->
                <div class="content-card">
                    <div class="card-header">
                        <div class="card-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <h3 class="card-title">Recent Student Enrollments</h3>
                    </div>
                    
                    <?php if (empty($recent_students)): ?>
                        <div class="no-data">No recent student enrollments</div>
                    <?php else: ?>
                        <?php foreach ($recent_students as $student): ?>
                            <div class="student-item">
                                <div class="student-avatar">
                                    <?php echo strtoupper(substr($student['full_name'] ?? $student['username'], 0, 1)); ?>
                                </div>
                                <div class="student-info">
                                    <h4><?php echo htmlspecialchars($student['full_name'] ?? $student['username']); ?></h4>
                                    <p>
                                        ID: <?php echo htmlspecialchars($student['student_id'] ?? 'N/A'); ?> | 
                                        <?php echo htmlspecialchars($student['year_level'] ?? 'N/A'); ?>
                                        <?php if ($student['program']): ?>
                                            | <?php echo htmlspecialchars($student['program']); ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Department Faculty -->
                <div class="content-card">
                    <div class="card-header">
                        <div class="card-icon">
                            <i class="fas fa-users-cog"></i>
                        </div>
                        <h3 class="card-title">Department Faculty</h3>
                    </div>
                    
                    <?php if (empty($dept_faculty_list)): ?>
                        <div class="no-data">No faculty members found</div>
                    <?php else: ?>
                        <?php foreach ($dept_faculty_list as $faculty): ?>
                            <div class="faculty-item">
                                <div class="faculty-avatar">
                                    <?php echo strtoupper(substr($faculty['full_name'] ?? $faculty['username'], 0, 1)); ?>
                                </div>
                                <div class="student-info">
                                    <h4><?php echo htmlspecialchars($faculty['full_name'] ?? $faculty['username']); ?></h4>
                                    <p>
                                        <?php echo htmlspecialchars($faculty['position'] ?? 'Faculty'); ?>
                                        <?php if ($faculty['employee_id']): ?>
                                            | ID: <?php echo htmlspecialchars($faculty['employee_id']); ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function(){
            var btn = document.getElementById('deptSidebarToggle');
            var sidebar = document.querySelector('.sidebar');
            if (btn && sidebar) {
                btn.addEventListener('click', function(){
                    sidebar.classList.toggle('active');
                });
            }
        })();

        
        function logout() {
            fetch('auth.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=logout'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = data.redirect;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                window.location.href = 'index.php';
            });
        }

        function generateDeptReport() {
            window.open('reports/department_report.php?dept=' + encodeURIComponent('<?php echo $admin_department; ?>'), '_blank');
        }

        // Add some interactive animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.stat-card, .content-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html>
