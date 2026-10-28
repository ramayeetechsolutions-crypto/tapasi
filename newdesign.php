<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        :root {
            --primary: #3498db;
            --primary-dark: #2980b9;
            --secondary: #2c3e50;
            --light: #f5f7fa;
            --dark: #34495e;
            --success: #2ecc71;
            --warning: #f39c12;
            --danger: #e74c3c;
            --gray: #95a5a6;
            --white: #ffffff;
            --sidebar-width: 250px;
        }

        body {
            background-color: var(--light);
            color: var(--secondary);
            line-height: 1.6;
            display: flex;
            min-height: 100vh;
        }

        /* Header Styles */
        header {
            background-color: var(--white);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 5%;
        }

        .logo {
            font-size: 24px;
            font-weight: 700;
            color: var(--secondary);
        }

        .logo span {
            color: var(--primary);
        }

        /* Navigation Menu */
        nav {
            display: flex;
            align-items: center;
        }

        .nav-menu {
            display: flex;
            list-style: none;
        }

        .nav-menu li {
            margin-right: 30px;
        }

        .nav-menu a {
            text-decoration: none;
            color: var(--secondary);
            font-weight: 500;
            font-size: 16px;
            transition: color 0.3s ease;
            position: relative;
            padding: 5px 0;
            display: flex;
            align-items: center;
        }

        .nav-menu a i {
            margin-right: 8px;
            font-size: 18px;
        }

        .nav-menu a:hover {
            color: var(--primary);
        }

        .nav-menu a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background-color: var(--primary);
            transition: width 0.3s ease;
        }

        .nav-menu a:hover::after {
            width: 100%;
        }

        .nav-menu a.active {
            color: var(--primary);
        }

        .nav-menu a.active::after {
            width: 100%;
        }

        /* Sidebar Styles */
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--white);
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.05);
            padding: 80px 0 20px 0;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0;
        }

        .sidebar-menu li {
            margin-bottom: 5px;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 15px 25px;
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }

        .sidebar-menu a:hover {
            background-color: rgba(52, 152, 219, 0.1);
            color: var(--primary);
            border-left-color: var(--primary);
        }

        .sidebar-menu a.active {
            background-color: rgba(52, 152, 219, 0.1);
            color: var(--primary);
            border-left-color: var(--primary);
        }

        .sidebar-menu i {
            margin-right: 12px;
            font-size: 18px;
            width: 24px;
            text-align: center;
        }

        .menu-section {
            padding: 20px 25px 10px;
            font-size: 14px;
            color: var(--gray);
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 1px;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            margin-top: 70px;
            padding: 30px;
            transition: margin-left 0.3s ease;
        }

        .page-title {
            font-size: 28px;
            margin-bottom: 10px;
            color: var(--secondary);
            display: flex;
            align-items: center;
        }

        .page-title i {
            margin-right: 10px;
            color: var(--primary);
        }

        .page-description {
            color: var(--gray);
            margin-bottom: 30px;
        }

        /* Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background-color: var(--white);
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 24px;
        }

        .stat-icon.leads {
            background-color: rgba(52, 152, 219, 0.1);
            color: var(--primary);
        }

        .stat-icon.customers {
            background-color: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }

        .stat-icon.deals {
            background-color: rgba(155, 89, 182, 0.1);
            color: #9b59b6;
        }

        .stat-icon.revenue {
            background-color: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }

        .stat-info h3 {
            font-size: 24px;
            margin-bottom: 5px;
        }

        .stat-info p {
            color: var(--gray);
            font-size: 14px;
        }

        /* Content Sections */
        .content-section {
            background-color: var(--white);
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 20px;
            color: var(--secondary);
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
        }

        .btn i {
            margin-right: 8px;
        }

        .btn-primary {
            background-color: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background-color: #f8f9fa;
        }

        th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 1px solid #e0e0e0;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        tbody tr {
            transition: background-color 0.2s ease;
        }

        tbody tr:hover {
            background-color: #f8f9fa;
        }

        .status {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }

        .status-active {
            background-color: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }

        .status-pending {
            background-color: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }

        .status-closed {
            background-color: rgba(149, 165, 166, 0.1);
            color: var(--gray);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .action-edit {
            background-color: rgba(52, 152, 219, 0.1);
            color: var(--primary);
        }

        .action-edit:hover {
            background-color: var(--primary);
            color: var(--white);
        }

        .action-delete {
            background-color: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }

        .action-delete:hover {
            background-color: var(--danger);
            color: var(--white);
        }

        /* Mobile Menu Toggle */
        .menu-toggle {
            display: none;
            flex-direction: column;
            cursor: pointer;
            width: 30px;
            height: 25px;
            justify-content: space-between;
        }

        .menu-toggle span {
            height: 3px;
            width: 100%;
            background-color: var(--secondary);
            border-radius: 2px;
            transition: all 0.3s ease;
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                z-index: 999;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .menu-toggle {
                display: flex;
            }

            .nav-menu {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 20px 15px;
            }
            
            .content-section {
                padding: 15px;
            }
            
            th, td {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="menu-toggle" id="menuToggle">
            <span></span>
            <span></span>
            <span></span>
        </div>
        <nav>
            <ul class="nav-menu">
                <li><a href="#" class="active"><i class="fas fa-home"></i> Home</a></li>
                <li><a href="#"><i class="fas fa-chart-bar"></i> Analytics</a></li>
                <li><a href="#"><i class="fas fa-cog"></i> Settings</a></li>
                <li><a href="#"><i class="fas fa-question-circle"></i> Help</a></li>
            </ul>
        </nav>
        <div class="logo">CRM<span>Pro</span></div>
    </header>

    <aside class="sidebar" id="sidebar">
        <ul class="sidebar-menu">
            <div class="menu-section">Main</div>
            <li><a href="#" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="#"><i class="fas fa-users"></i> Customers</a></li>
            <li><a href="#"><i class="fas fa-user-friends"></i> Contacts</a></li>
            <li><a href="#"><i class="fas fa-bullseye"></i> Leads</a></li>
            <li><a href="#"><i class="fas fa-handshake"></i> Deals</a></li>
            
            <div class="menu-section">Business</div>
            <li><a href="#"><i class="fas fa-tasks"></i> Tasks</a></li>
            <li><a href="#"><i class="fas fa-calendar-alt"></i> Calendar</a></li>
            <li><a href="#"><i class="fas fa-file-invoice"></i> Invoices</a></li>
            <li><a href="#"><i class="fas fa-chart-line"></i> Reports</a></li>
            
            <div class="menu-section">Administration</div>
            <li><a href="#"><i class="fas fa-cogs"></i> Settings</a></li>
            <li><a href="#"><i class="fas fa-user-shield"></i> Admin</a></li>
            <li><a href="#"><i class="fas fa-life-ring"></i> Support</a></li>
        </ul>
    </aside>

    <main class="main-content">
        <h1 class="page-title"><i class="fas fa-tachometer-alt"></i> Dashboard</h1>
        <p class="page-description">Welcome back! Here's what's happening with your business today.</p>
        
        <div class="stats-cards">
            <div class="stat-card">
                <div class="stat-icon leads">
                    <i class="fas fa-bullseye"></i>
                </div>
                <div class="stat-info">
                    <h3>142</h3>
                    <p>New Leads</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon customers">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3>1,248</h3>
                    <p>Total Customers</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon deals">
                    <i class="fas fa-handshake"></i>
                </div>
                <div class="stat-info">
                    <h3>86</h3>
                    <p>Active Deals</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon revenue">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stat-info">
                    <h3>$24,580</h3>
                    <p>Revenue</p>
                </div>
            </div>
        </div>
        
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Recent Leads</h2>
                <button class="btn btn-primary"><i class="fas fa-plus"></i> Add New Lead</button>
            </div>
            
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Company</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Last Contact</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>John Smith</td>
                            <td>Acme Corp</td>
                            <td>john.smith@example.com</td>
                            <td>(555) 123-4567</td>
                            <td><span class="status status-active">Active</span></td>
                            <td>2023-06-15</td>
                            <td>
                                <div class="action-buttons">
                                    <div class="action-btn action-edit">
                                        <i class="fas fa-edit"></i>
                                    </div>
                                    <div class="action-btn action-delete">
                                        <i class="fas fa-trash"></i>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td>Sarah Johnson</td>
                            <td>Global Solutions</td>
                            <td>sarah.j@example.com</td>
                            <td>(555) 987-6543</td>
                            <td><span class="status status-pending">Pending</span></td>
                            <td>2023-06-10</td>
                            <td>
                                <div class="action-buttons">
                                    <div class="action-btn action-edit">
                                        <i class="fas fa-edit"></i>
                                    </div>
                                    <div class="action-btn action-delete">
                                        <i class="fas fa-trash"></i>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td>Michael Brown</td>
                            <td>Tech Innovations</td>
                            <td>m.brown@example.com</td>
                            <td>(555) 456-7890</td>
                            <td><span class="status status-active">Active</span></td>
                            <td>2023-06-12</td>
                            <td>
                                <div class="action-buttons">
                                    <div class="action-btn action-edit">
                                        <i class="fas fa-edit"></i>
                                    </div>
                                    <div class="action-btn action-delete">
                                        <i class="fas fa-trash"></i>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td>Emily Davis</td>
                            <td>Marketing Pro</td>
                            <td>emily.davis@example.com</td>
                            <td>(555) 234-5678</td>
                            <td><span class="status status-closed">Closed</span></td>
                            <td>2023-05-28</td>
                            <td>
                                <div class="action-buttons">
                                    <div class="action-btn action-edit">
                                        <i class="fas fa-edit"></i>
                                    </div>
                                    <div class="action-btn action-delete">
                                        <i class="fas fa-trash"></i>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Recent Activities</h2>
                <button class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
            </div>
            
            <div class="activities">
                <div class="activity-item">
                    <div class="activity-icon">
                        <i class="fas fa-phone"></i>
                    </div>
                    <div class="activity-content">
                        <h4>Call with John Smith</h4>
                        <p>Discussed new project requirements and timeline</p>
                        <span class="activity-time">Today, 10:30 AM</span>
                    </div>
                </div>
                
                <div class="activity-item">
                    <div class="activity-icon">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div class="activity-content">
                        <h4>Email sent to Sarah Johnson</h4>
                        <p>Sent proposal for marketing campaign</p>
                        <span class="activity-time">Yesterday, 3:45 PM</span>
                    </div>
                </div>
                
                <div class="activity-item">
                    <div class="activity-icon">
                        <i class="fas fa-file-contract"></i>
                    </div>
                    <div class="activity-content">
                        <h4>Contract signed</h4>
                        <p>Michael Brown signed the service agreement</p>
                        <span class="activity-time">June 14, 2023</span>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Mobile menu toggle
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');

        menuToggle.addEventListener('click', () => {
            menuToggle.classList.toggle('active');
            sidebar.classList.toggle('active');
        });

        // Close sidebar when clicking on a link (for mobile)
        const sidebarLinks = document.querySelectorAll('.sidebar-menu a');
        sidebarLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 992) {
                    menuToggle.classList.remove('active');
                    sidebar.classList.remove('active');
                }
            });
        });

        // Add active class to clicked sidebar items
        sidebarLinks.forEach(link => {
            link.addEventListener('click', function() {
                sidebarLinks.forEach(l => l.classList.remove('active'));
                this.classList.add('active');
            });
        });
    </script>
</body>
</html>