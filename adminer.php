<?php
// ===== KONFIGURASI =====
$DB_HOST = 'sql110.infinityfree.com';
$DB_USER = 'if0_40199145';
$DB_PASS = '12rizqi3';
$DB_NAME = 'if0_40199145_masjid';

// ===== KONEKSI =====
try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (Exception $e) {
    die("DB Connection Failed");
}

// ===== LIST TABLE =====
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

// ===== VIEW TABLE =====
$table = null;
$rows = [];
$columns = [];
$totalRows = 0;

if (!empty($_GET['table'])) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['table']);
    $stmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
    $totalRows = $stmt->fetchColumn();
    $rows = $pdo->query("SELECT * FROM `$table` LIMIT 50")->fetchAll();
    if ($rows) {
        $columns = array_keys($rows[0]);
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tiny DB Manager</title>
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.10.3/sweetalert2.min.js"></script>
    <style>
        /* ===== DESIGN TOKENS ===== */
        :root {
            /* Colors - Dark Theme */
            --bg-primary: #0a0a0f;
            --bg-secondary: #12121a;
            --bg-card: #1a1a25;
            --bg-hover: #252533;
            --bg-active: #1f1f2a;

            /* Text */
            --text-primary: #f8f8fc;
            --text-secondary: #a0a0b0;
            --text-muted: #606070;

            /* Border */
            --border-subtle: rgba(255, 255, 255, 0.06);
            --border-medium: rgba(255, 255, 255, 0.1);
            --border-strong: rgba(255, 255, 255, 0.15);

            /* Accent */
            --accent-primary: #6366f1;
            --accent-primary-hover: #818cf8;
            --accent-primary-glow: rgba(99, 102, 241, 0.3);
            --accent-secondary: #8b5cf6;
            --accent-success: #10b981;
            --accent-warning: #f59e0b;
            --accent-danger: #ef4444;
            --accent-info: #3b82f6;

            /* Spacing */
            --space-xs: 4px;
            --space-sm: 8px;
            --space-md: 16px;
            --space-lg: 24px;
            --space-xl: 32px;
            --space-2xl: 48px;

            /* Radius */
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;

            /* Shadow */
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.4), 0 2px 4px -1px rgba(0, 0, 0, 0.3);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.4), 0 4px 6px -2px rgba(0, 0, 0, 0.3);
            --shadow-glow: 0 0 20px -5px var(--accent-primary-glow);

            /* Motion */
            --duration-fast: 150ms;
            --duration-base: 220ms;
            --duration-slow: 300ms;
            --ease-out: cubic-bezier(0.4, 0, 0.2, 1);
            --ease-in-out: cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* ===== BASE STYLES ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* ===== LAYOUT ===== */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: var(--space-xl);
        }

        /* ===== HEADER ===== */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--space-lg);
            margin-bottom: var(--space-xl);
            padding-bottom: var(--space-lg);
            border-bottom: 1px solid var(--border-subtle);
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: var(--space-md);
        }

        .header-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            box-shadow: var(--shadow-glow);
        }

        .header-text h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -0.02em;
        }

        .header-text p {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-top: 2px;
        }

        .header-badge {
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            padding: var(--space-sm) var(--space-md);
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: var(--space-sm);
        }

        .header-badge i {
            color: var(--accent-primary);
        }

        /* ===== STATS CARDS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-md);
            margin-bottom: var(--space-xl);
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            padding: var(--space-lg);
            display: flex;
            align-items: center;
            gap: var(--space-md);
            transition: all var(--duration-base) var(--ease-out);
        }

        .stat-card:hover {
            border-color: var(--border-medium);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .stat-icon.primary {
            background: rgba(99, 102, 241, 0.1);
            color: var(--accent-primary);
        }

        .stat-icon.success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--accent-success);
        }

        .stat-icon.info {
            background: rgba(59, 130, 246, 0.1);
            color: var(--accent-info);
        }

        .stat-content {
            flex: 1;
        }

        .stat-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            font-weight: 600;
            margin-bottom: 2px;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -0.02em;
        }

        /* ===== TABLE LIST ===== */
        .section {
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            overflow: hidden;
            margin-bottom: var(--space-xl);
        }

        .section-header {
            padding: var(--space-lg);
            border-bottom: 1px solid var(--border-subtle);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--space-md);
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .section-title i {
            color: var(--accent-primary);
        }

        .table-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: var(--space-md);
            padding: var(--space-lg);
        }

        .table-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            padding: var(--space-lg);
            display: flex;
            align-items: center;
            gap: var(--space-md);
            text-decoration: none;
            transition: all var(--duration-base) var(--ease-out);
            position: relative;
            overflow: hidden;
        }

        .table-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 3px;
            height: 100%;
            background: linear-gradient(180deg, var(--accent-primary), var(--accent-secondary));
            opacity: 0;
            transition: opacity var(--duration-base) var(--ease-out);
        }

        .table-card:hover {
            background: var(--bg-hover);
            border-color: var(--border-medium);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .table-card:hover::before {
            opacity: 1;
        }

        .table-card.active {
            background: var(--bg-hover);
            border-color: var(--accent-primary);
            box-shadow: var(--shadow-glow);
        }

        .table-card.active::before {
            opacity: 1;
        }

        .table-card-icon {
            width: 40px;
            height: 40px;
            background: rgba(99, 102, 241, 0.1);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            color: var(--accent-primary);
            flex-shrink: 0;
        }

        .table-card-content {
            flex: 1;
            min-width: 0;
        }

        .table-card-name {
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 2px;
            word-break: break-all;
        }

        .table-card-link {
            font-size: 0.75rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .table-card-arrow {
            color: var(--text-muted);
            transition: transform var(--duration-base) var(--ease-out);
        }

        .table-card:hover .table-card-arrow {
            transform: translateX(3px);
            color: var(--accent-primary);
        }

        /* ===== DATA TABLE ===== */
        .table-view {
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }

        .table-view-header {
            padding: var(--space-lg);
            border-bottom: 1px solid var(--border-subtle);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--space-md);
            flex-wrap: wrap;
        }

        .table-view-title {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .table-view-title i {
            color: var(--accent-primary);
        }

        .table-view-info {
            display: flex;
            align-items: center;
            gap: var(--space-md);
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .table-view-info span {
            display: flex;
            align-items: center;
            gap: var(--space-xs);
        }

        .table-view-info i {
            color: var(--accent-info);
        }

        .table-wrapper {
            overflow-x: auto;
            max-height: 600px;
            overflow-y: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table thead {
            position: sticky;
            top: 0;
            background: var(--bg-secondary);
            z-index: 10;
        }

        .data-table th {
            padding: var(--space-md) var(--space-lg);
            text-align: left;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-subtle);
            white-space: nowrap;
        }

        .data-table td {
            padding: var(--space-md) var(--space-lg);
            font-size: 0.875rem;
            color: var(--text-primary);
            border-bottom: 1px solid var(--border-subtle);
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .data-table tbody tr {
            transition: background var(--duration-fast) var(--ease-out);
        }

        .data-table tbody tr:hover {
            background: var(--bg-hover);
        }

        .data-table tbody tr:last-child td {
            border-bottom: none;
        }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: var(--space-2xl) var(--space-xl);
            color: var(--text-secondary);
        }

        .empty-state-icon {
            font-size: 3rem;
            color: var(--text-muted);
            margin-bottom: var(--space-md);
            opacity: 0.5;
        }

        .empty-state-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: var(--space-sm);
        }

        .empty-state-text {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        /* ===== FOOTER ===== */
        .footer {
            text-align: center;
            padding: var(--space-xl);
            color: var(--text-muted);
            font-size: 0.875rem;
        }

        .footer a {
            color: var(--accent-primary);
            text-decoration: none;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        /* ===== BACK BUTTON ===== */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: var(--space-sm);
            padding: var(--space-sm) var(--space-md);
            background: var(--bg-secondary);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-sm);
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.875rem;
            transition: all var(--duration-base) var(--ease-out);
        }

        .back-link:hover {
            background: var(--bg-hover);
            border-color: var(--border-medium);
            color: var(--text-primary);
        }

        .back-link i {
            transition: transform var(--duration-base) var(--ease-out);
        }

        .back-link:hover i {
            transform: translateX(-3px);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .container {
                padding: var(--space-md);
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .table-grid {
                grid-template-columns: 1fr;
            }

            .table-view-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .data-table th,
            .data-table td {
                padding: var(--space-sm) var(--space-md);
            }
        }

        /* ===== SCROLLBAR ===== */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--bg-secondary);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--border-medium);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--border-strong);
        }

        /* ===== ANIMATIONS ===== */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .container {
            animation: fadeIn 0.4s var(--ease-out);
        }

        /* ===== SWEETALERT2 CUSTOM ===== */
        div:where(.swal2-container) div:where(.swal2-popup) {
            background: var(--bg-card) !important;
            border: 1px solid var(--border-subtle) !important;
            border-radius: var(--radius-lg) !important;
            color: var(--text-primary) !important;
        }

        div:where(.swal2-icon) {
            border-color: var(--accent-primary) !important;
            color: var(--accent-primary) !important;
        }

        div:where(.swal2-title) {
            color: var(--text-primary) !important;
        }

        div:where(.swal2-html-container) {
            color: var(--text-secondary) !important;
        }

        div:where(.swal2-confirm) {
            background: var(--accent-primary) !important;
            border-radius: var(--radius-sm) !important;
        }

        div:where(.swal2-cancel) {
            background: var(--bg-secondary) !important;
            border: 1px solid var(--border-subtle) !important;
            color: var(--text-secondary) !important;
            border-radius: var(--radius-sm) !important;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <header class="header">
            <div class="header-title">
                <div class="header-icon">
                    <i class="fas fa-database"></i>
                </div>
                <div class="header-text">
                    <h1>Tiny DB Manager</h1>
                    <p>Manage your database with ease</p>
                </div>
            </div>
            <div class="header-badge">
                <i class="fas fa-server"></i>
                <span><?=htmlspecialchars($DB_NAME)?></span>
            </div>
        </header>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon primary">
                    <i class="fas fa-table"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Total Tables</div>
                    <div class="stat-value"><?=count($tables)?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Status</div>
                    <div class="stat-value">Connected</div>
                </div>
            </div>
            <?php if ($table): ?>
            <div class="stat-card">
                <div class="stat-icon info">
                    <i class="fas fa-list"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Total Rows</div>
                    <div class="stat-value"><?=number_format($totalRows)?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($table): ?>
            <!-- Table View -->
            <div class="table-view">
                <div class="table-view-header">
                    <div class="table-view-title">
                        <i class="fas fa-table"></i>
                        <span><?=htmlspecialchars($table)?></span>
                    </div>
                    <div class="table-view-info">
                        <span>
                            <i class="fas fa-columns"></i>
                            <?=count($columns)?> columns
                        </span>
                        <span>
                            <i class="fas fa-list-ol"></i>
                            <?=number_format($totalRows)?> total rows
                        </span>
                        <a href="?" class="back-link">
                            <i class="fas fa-arrow-left"></i>
                            Back to Tables
                        </a>
                    </div>
                </div>
                <div class="table-wrapper">
                    <?php if ($rows): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <?php foreach ($columns as $col): ?>
                                        <th>
                                            <i class="fas fa-columns" style="margin-right: 6px; opacity: 0.5;"></i>
                                            <?=htmlspecialchars($col)?>
                                        </th>
                                    <?php endforeach ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <?php foreach ($r as $v): ?>
                                            <td><?=htmlspecialchars((string)$v)?></td>
                                        <?php endforeach ?>
                                    </tr>
                                <?php endforeach ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-inbox"></i>
                            </div>
                            <div class="empty-state-title">No Data Found</div>
                            <div class="empty-state-text">This table is empty or contains no data</div>
                        </div>
                    <?php endif ?>
                </div>
            </div>
        <?php else: ?>
            <!-- Tables List -->
            <div class="section">
                <div class="section-header">
                    <div class="section-title">
                        <i class="fas fa-layer-group"></i>
                        Database Tables
                    </div>
                </div>
                <?php if ($tables): ?>
                    <div class="table-grid">
                        <?php foreach ($tables as $t): ?>
                            <a href="?table=<?=$t?>" class="table-card">
                                <div class="table-card-icon">
                                    <i class="fas fa-table"></i>
                                </div>
                                <div class="table-card-content">
                                    <div class="table-card-name"><?=htmlspecialchars($t)?></div>
                                    <div class="table-card-link">
                                        <i class="fas fa-external-link-alt"></i>
                                        View data
                                    </div>
                                </div>
                                <div class="table-card-arrow">
                                    <i class="fas fa-chevron-right"></i>
                                </div>
                            </a>
                        <?php endforeach ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-database"></i>
                        </div>
                        <div class="empty-state-title">No Tables Found</div>
                        <div class="empty-state-text">Database is empty or contains no tables</div>
                    </div>
                <?php endif ?>
            </div>
        <?php endif ?>

        <!-- Footer -->
        <footer class="footer">
            <p>Tiny DB Manager &copy; 2024</p>
        </footer>
    </div>

    <script>
        // Welcome message on first visit
        if (!sessionStorage.getItem('visited')) {
            Swal.fire({
                icon: 'success',
                title: 'Welcome!',
                text: 'Tiny DB Manager is ready to use',
                confirmButtonText: 'Get Started',
                timer: 3000,
                timerProgressBar: true,
                showClass: {
                    popup: 'swal2-show',
                    backdrop: 'swal2-backdrop-show',
                    icon: 'swal2-icon-show'
                },
                hideClass: {
                    popup: 'swal2-hide',
                    backdrop: 'swal2-backdrop-hide',
                    icon: 'swal2-icon-hide'
                }
            });
            sessionStorage.setItem('visited', 'true');
        }

        // Table click animation
        document.querySelectorAll('.table-card').forEach(card => {
            card.addEventListener('click', function(e) {
                e.preventDefault();
                const tableName = this.querySelector('.table-card-name').textContent;
                const url = this.href;

                Swal.fire({
                    icon: 'info',
                    title: 'Loading Table',
                    text: `Opening ${tableName}...`,
                    timer: 500,
                    showConfirmButton: false
                }).then(() => {
                    window.location.href = url;
                });
            });
        });
    </script>
</body>
</html>
