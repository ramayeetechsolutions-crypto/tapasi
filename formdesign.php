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
        }

        body {
            background-color: var(--light);
            color: var(--secondary);
            line-height: 1.6;
        }

        /* Header Styles */
        header {
            background-color: var(--white);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
        }

        .header-container {
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

        /* Main Content */
        .main-content {
            margin-top: 80px;
            padding: 20px 5%;
        }

        .page-title {
            font-size: 28px;
            margin-bottom: 10px;
            color: var(--secondary);
        }

        .page-description {
            color: var(--gray);
            margin-bottom: 30px;
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid #e0e0e0;
            margin-bottom: 20px;
        }

        .tab {
            padding: 12px 24px;
            cursor: pointer;
            font-weight: 500;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
        }

        .tab.active {
            color: var(--primary);
            border-bottom: 3px solid var(--primary);
        }

        .tab:hover:not(.active) {
            color: var(--primary-dark);
            background-color: rgba(52, 152, 219, 0.05);
        }

        /* Form Styles */
        .form-container {
            background-color: var(--white);
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .form-title {
            font-size: 20px;
            margin-bottom: 20px;
            color: var(--secondary);
            display: flex;
            align-items: center;
        }

        .form-title i {
            margin-right: 10px;
            color: var(--primary);
        }

        .form-row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -10px;
        }

        .form-group {
            flex: 1 0 300px;
            padding: 0 10px;
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            font-size: 16px;
            transition: border 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 10px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background-color: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-secondary {
            background-color: var(--gray);
            color: var(--white);
        }

        .btn-secondary:hover {
            background-color: #7f8c8d;
        }

        /* Data Table Styles */
        .table-container {
            background-color: var(--white);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid #e0e0e0;
        }

        .table-title {
            font-size: 20px;
            color: var(--secondary);
        }

        .table-actions {
            display: flex;
            gap: 10px;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 10px 15px 10px 40px;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            width: 250px;
            font-size: 16px;
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

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

        .status-inactive {
            background-color: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }

        .status-pending {
            background-color: rgba(243, 156, 18, 0.1);
            color: var(--warning);
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

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .pagination-info {
            color: var(--gray);
        }

        .pagination-controls {
            display: flex;
            gap: 5px;
        }

        .pagination-btn {
            width: 36px;
            height: 36px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--white);
            border: 1px solid #e0e0e0;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .pagination-btn.active {
            background-color: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        .pagination-btn:hover:not(.active) {
            background-color: #f8f9fa;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .form-group {
                flex: 1 0 100%;
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .search-box input {
                width: 100%;
            }
            
            .table-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .pagination {
                flex-direction: column;
                gap: 15px;
            }
        }

        @media (max-width: 576px) {
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
            
            .tabs {
                overflow-x: auto;
                white-space: nowrap;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="header-container">
            <div class="logo">CRM<span>Pro</span></div>
        </div>
    </header>

    <main class="main-content">
        <h1 class="page-title">Customer Management</h1>
        <p class="page-description">Manage your customer data and interactions in one place</p>
        
        <div class="tabs">
            <div class="tab active">Customers</div>
            <div class="tab">Leads</div>
            <div class="tab">Contacts</div>
            <div class="tab">Analytics</div>
        </div>
        
        <div class="form-container">
            <h2 class="form-title"><i class="fas fa-user-plus"></i> Add New Customer</h2>
            <form id="customerForm">
                <div class="form-row">
                    <div class="form-group">
                        <label for="firstName">First Name</label>
                        <input type="text" id="firstName" class="form-control" placeholder="Enter first name">
                    </div>
                    <div class="form-group">
                        <label for="lastName">Last Name</label>
                        <input type="text" id="lastName" class="form-control" placeholder="Enter last name">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" class="form-control" placeholder="Enter email address">
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" class="form-control" placeholder="Enter phone number">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="company">Company</label>
                        <input type="text" id="company" class="form-control" placeholder="Enter company name">
                    </div>
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" class="form-control">
                            <option value="">Select status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="notes">Notes</label>
                    <textarea id="notes" class="form-control" rows="3" placeholder="Enter any additional notes"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="reset" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Customer</button>
                </div>
            </form>
        </div>
        
        <div class="table-container">
            <div class="table-header">
                <h2 class="table-title">Customer List</h2>
                <div class="table-actions">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search customers...">
                    </div>
                    <button class="btn btn-primary">
                        <i class="fas fa-download"></i> Export
                    </button>
                </div>
            </div>
            
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Company</th>
                            <th>Status</th>
                            <th>Last Contact</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>John Smith</td>
                            <td>john.smith@example.com</td>
                            <td>(555) 123-4567</td>
                            <td>Acme Corp</td>
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
                            <td>sarah.j@example.com</td>
                            <td>(555) 987-6543</td>
                            <td>Global Solutions</td>
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
                            <td>m.brown@example.com</td>
                            <td>(555) 456-7890</td>
                            <td>Tech Innovations</td>
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
                            <td>emily.davis@example.com</td>
                            <td>(555) 234-5678</td>
                            <td>Marketing Pro</td>
                            <td><span class="status status-inactive">Inactive</span></td>
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
                        <tr>
                            <td>Robert Wilson</td>
                            <td>robert.w@example.com</td>
                            <td>(555) 876-5432</td>
                            <td>Wilson & Co</td>
                            <td><span class="status status-active">Active</span></td>
                            <td>2023-06-14</td>
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
            
            <div class="pagination">
                <div class="pagination-info">Showing 1 to 5 of 24 entries</div>
                <div class="pagination-controls">
                    <div class="pagination-btn"><i class="fas fa-chevron-left"></i></div>
                    <div class="pagination-btn active">1</div>
                    <div class="pagination-btn">2</div>
                    <div class="pagination-btn">3</div>
                    <div class="pagination-btn">4</div>
                    <div class="pagination-btn">5</div>
                    <div class="pagination-btn"><i class="fas fa-chevron-right"></i></div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Form submission handler
        document.getElementById('customerForm').addEventListener('submit', function(e) {
            e.preventDefault();
            alert('Customer data saved successfully!');
            this.reset();
        });
        
        // Tab switching functionality
        const tabs = document.querySelectorAll('.tab');
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                tabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
            });
        });
        
        // Search functionality
        const searchInput = document.querySelector('.search-box input');
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
    </script>
</body>
</html>