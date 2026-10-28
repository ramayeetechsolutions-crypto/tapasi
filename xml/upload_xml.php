<?php
// Start a session if one is not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php';
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ==========================================
// AJAX & EXCEL EXPORT HANDLERS
// ==========================================

// 1. Export Job to Excel (.xls format)
if (isset($_GET['action']) && $_GET['action'] === 'download_excel') {
    $job_id = (int)$_GET['job_id'];

    $stmt = $mysqli->prepare("
    SELECT j.*, 
           COALESCE(NULLIF(c.team_client_name, ''), NULLIF(c.client_name, ''), NULLIF(j.journal_name_client, ''), '-') as full_client_name,
           CASE 
               WHEN j.journal_name_client IS NOT NULL AND j.journal_name_client != '' AND j.issue IS NOT NULL AND j.issue != '' 
               THEN CONCAT(j.journal_name_client, '-', j.issue)
               ELSE j.issue 
           END as full_issue
    FROM unique_xml_jobs j
    LEFT JOIN jobs jb ON j.job_id = jb.job_id
    LEFT JOIN client_master c ON jb.client_id = c.client_id
    WHERE j.id = ?
");
    $stmt->bind_param("i", $job_id);
    $stmt->execute();
    $job = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$job) { die("Job not found."); }

    // Fetch associated articles
    $stmt = $mysqli->prepare("SELECT * FROM xml_job_articles WHERE xml_job_id = ? ORDER BY id ASC");
    $stmt->bind_param("i", $job_id);
    $stmt->execute();
    $articles = $stmt->get_result();
    $stmt->close();

    $client_name = !empty($job['full_client_name']) ? $job['full_client_name'] : 'Unknown_Client';
    $client_name_clean = preg_replace('/[^A-Za-z0-9_\- ]/', '_', $client_name); 
    $current_month = date('F'); 
    $current_year = date('Y'); 
    
    $filename = $client_name_clean . "_XML_" . $current_month . "_" . $current_year . ".xls";

    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta http-equiv="content-type" content="text/plain; charset=UTF-8"/></head>';
    echo '<body>';
    
    // FORMAT DATES AND COMMON INFO
    $rec_date_fmt = !empty($job['rec_date']) ? date('j-M-y', strtotime($job['rec_date'])) : '-'; 
    $job_client = !empty($job['full_client_name']) ? $job['full_client_name'] : '-';
    $job_issue = !empty($job['issue']) ? $job['issue'] : '-';
    $job_arts_fallback = !empty($job['articles']) ? $job['articles'] : '-';

    // MAPPING DB COLUMNS TO EXCEL DISPLAY NAMES (Scielo Excluded!)
    $process_map = [
        'nlm' => 'XML',
        'new_html' => 'New XML',
        'direct_html' => 'Direct HTML',
        'jats' => 'JATS',
        'clockss' => 'Clockss',
        'ebsco' => 'EBSCO',
        'pubmed' => 'PubMed',
        'biblat' => 'Biblat',
        'doaj' => 'DOAJ',
        'html_1' => 'HTML'
    ];

    $process_groups = [];

    // GROUP ARTICLES BY PROCESS
    if ($articles->num_rows > 0) {
        while ($art = $articles->fetch_assoc()) {
            $art_name = !empty($art['article_name']) ? $art['article_name'] : '-';
            
            foreach ($process_map as $db_col => $proc_name) {
                $val = isset($art[$db_col]) ? trim($art[$db_col]) : '';
                if (!empty($val) && $val !== '-' && $val !== '0') {
                    if (!isset($process_groups[$proc_name])) {
                        $process_groups[$proc_name] = [];
                    }
                    $process_groups[$proc_name][] = [
                        'article_name' => $art_name,
                        'pages' => $val
                    ];
                }
            }
        }
    } else {
        // Fallback for parent job
        $parent_map = [
            'xml_status' => 'XML',
            'new_html' => 'New XML',
            'direct_html' => 'Direct HTML',
            'html' => 'HTML',
            'pubmed_biblat_status' => 'PubMed'
        ];
        foreach ($parent_map as $db_col => $proc_name) {
            $val = isset($job[$db_col]) ? trim($job[$db_col]) : '';
            if (!empty($val) && $val !== '-' && $val !== '0') {
                if (!isset($process_groups[$proc_name])) {
                    $process_groups[$proc_name] = [];
                }
                $process_groups[$proc_name][] = [
                    'article_name' => $job_arts_fallback,
                    'pages' => $val
                ];
            }
        }
    }

    // START EXCEL TABLE
    echo '<table border="1" cellpadding="3" cellspacing="0" style="font-family: Calibri, sans-serif; font-size: 11pt;">';
    echo '<tr style="background-color: #bcbcbc; font-weight: bold; text-align: center;">';
    echo '<th>Rec. Date</th><th>Journal Name</th><th>Issue</th><th>Article Name</th><th>Pages</th>';
    echo '</tr>';

    if (empty($process_groups)) {
        echo '<tr style="text-align: center;">';
        echo '<td>' . h($rec_date_fmt) . '</td>';
        echo '<td>' . h($job_client) . '</td>';
        echo '<td>' . h($job_issue) . '</td>';
        echo '<td>' . h($job_arts_fallback) . '</td>';
        echo '<td>-</td>';
        echo '</tr>';
    } else {
        // LOOP THROUGH EACH PROCESS
        foreach ($process_groups as $proc_name => $arts) {
            $total_pages = 0;
            
            foreach ($arts as $a) {
                // Sum logic for Total Row
                $pages_count = is_numeric($a['pages']) ? (int)$a['pages'] : $a['pages'];
                if (is_numeric($pages_count)) {
                    $total_pages += $pages_count;
                }
                
                // Append Process Name to Issue
                $display_issue = $job_issue . '_' . $proc_name;

                echo '<tr style="text-align: center;">';
                echo '<td>' . h($rec_date_fmt) . '</td>';
                echo '<td>' . h($job_client) . '</td>';
                echo '<td>' . h($display_issue) . '</td>';
                echo '<td>' . h($a['article_name']) . '</td>';
                echo '<td>' . h($a['pages']) . '</td>';
                echo '</tr>';
            }
            
            // TOTAL ROW
            echo '<tr style="text-align: center;">';
            echo '<td></td><td></td>';
            echo '<td style="font-weight: bold;">Total</td>';
            echo '<td></td>';
            echo '<td style="font-weight: bold;">' . $total_pages . '</td>';
            echo '</tr>';
        }
    }

    echo '</table>';
    echo '</body></html>';
    exit; 
}

// 1b. Export Filtered Client & Month Jobs to Excel (.xls format)
if (isset($_GET['action']) && $_GET['action'] === 'download_client_month_excel') {
    $client = trim($_GET['client'] ?? '');
    $month = trim($_GET['month'] ?? '');
    $year = trim($_GET['year'] ?? '');

    if (empty($client)) {
        die("Please select a client.");
    }

    $where = "j.job_status = 'Despatched' AND (COALESCE(NULLIF(c.team_client_name, ''), NULLIF(c.client_name, ''), NULLIF(j.journal_name_client, ''), '-') COLLATE utf8mb4_bin) = ?";
    $params = [$client];
    $types = "s";

    if (!empty($month)) {
        $where .= " AND DATE_FORMAT(j.despatched_date, '%b') = ?";
        $params[] = $month;
        $types .= "s";
    }

    if (!empty($year)) {
        $where .= " AND YEAR(j.despatched_date) = ?";
        $params[] = $year;
        $types .= "s";
    }

    $sql = "
        SELECT j.*, 
               COALESCE(NULLIF(c.team_client_name, ''), NULLIF(c.client_name, ''), NULLIF(j.journal_name_client, ''), '-') as full_client_name,
               CASE 
                   WHEN j.journal_name_client IS NOT NULL AND j.journal_name_client != '' AND j.issue IS NOT NULL AND j.issue != '' 
                   THEN CONCAT(j.journal_name_client, '-', j.issue)
                   ELSE COALESCE(NULLIF(jb.journal_issue, ''), NULLIF(j.issue, ''), NULLIF(j.journal_name_client, ''), '-')
               END as full_issue
        FROM unique_xml_jobs j
        LEFT JOIN jobs jb ON j.job_id = jb.job_id
        LEFT JOIN client_master c ON jb.client_id = c.client_id
        WHERE $where
        ORDER BY j.despatched_date ASC, j.id ASC
    ";

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $jobs_res = $stmt->get_result();
    $stmt->close();

    $client_clean = preg_replace('/[^A-Za-z0-9_\- ]/', '_', $client);
    $filename = $client_clean . "_XML_" . (!empty($month) ? $month . "_" : "") . (!empty($year) ? $year : date('Y')) . ".xls";

    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta http-equiv="content-type" content="text/plain; charset=UTF-8"/></head>';
    echo '<body>';
    echo '<table border="1" cellpadding="3" cellspacing="0" style="font-family: Calibri, sans-serif; font-size: 11pt;">';
    echo '<tr style="background-color: #bcbcbc; font-weight: bold; text-align: center;">';
    echo '<th>Rec. Date</th><th>Journal Name</th><th>Issue</th><th>Article Name</th><th>Pages</th>';
    echo '</tr>';

    $process_map = [
        'nlm' => 'XML',
        'new_html' => 'New XML',
        'direct_html' => 'Direct HTML',
        'jats' => 'JATS',
        'clockss' => 'Clockss',
        'ebsco' => 'EBSCO',
        'pubmed' => 'PubMed',
        'biblat' => 'Biblat',
        'doaj' => 'DOAJ',
        'html_1' => 'HTML'
    ];

    if ($jobs_res->num_rows === 0) {
        echo '<tr><td colspan="5" style="text-align:center;">No records found for the selected client and month.</td></tr>';
    } else {
        while ($job = $jobs_res->fetch_assoc()) {
            $job_id = (int)$job['id'];
            $rec_date_fmt = !empty($job['rec_date']) ? date('j-M-y', strtotime($job['rec_date'])) : '-'; 
            $job_client = !empty($job['full_client_name']) ? $job['full_client_name'] : '-';
            $job_issue = !empty($job['full_issue']) ? $job['full_issue'] : (!empty($job['issue']) ? $job['issue'] : '-');
            $job_arts_fallback = !empty($job['articles']) ? $job['articles'] : '-';

            // Fetch associated articles
            $art_stmt = $mysqli->prepare("SELECT * FROM xml_job_articles WHERE xml_job_id = ? ORDER BY id ASC");
            $art_stmt->bind_param("i", $job_id);
            $art_stmt->execute();
            $articles = $art_stmt->get_result();
            $art_stmt->close();

            $process_groups = [];

            if ($articles->num_rows > 0) {
                while ($art = $articles->fetch_assoc()) {
                    $art_name = !empty($art['article_name']) ? $art['article_name'] : '-';
                    foreach ($process_map as $db_col => $proc_name) {
                        $val = isset($art[$db_col]) ? trim($art[$db_col]) : '';
                        if (!empty($val) && $val !== '-' && $val !== '0') {
                            if (!isset($process_groups[$proc_name])) {
                                $process_groups[$proc_name] = [];
                            }
                            $process_groups[$proc_name][] = [
                                'article_name' => $art_name,
                                'pages' => $val
                            ];
                        }
                    }
                }
            } else {
                $parent_map = [
                    'xml_status' => 'XML',
                    'new_html' => 'New XML',
                    'direct_html' => 'Direct HTML',
                    'html' => 'HTML',
                    'pubmed_biblat_status' => 'PubMed'
                ];
                foreach ($parent_map as $db_col => $proc_name) {
                    $val = isset($job[$db_col]) ? trim($job[$db_col]) : '';
                    if (!empty($val) && $val !== '-' && $val !== '0') {
                        if (!isset($process_groups[$proc_name])) {
                            $process_groups[$proc_name] = [];
                        }
                        $process_groups[$proc_name][] = [
                            'article_name' => $job_arts_fallback,
                            'pages' => $val
                        ];
                    }
                }
            }

            if (!empty($process_groups)) {
                foreach ($process_groups as $proc_name => $arts) {
                    $total_pages = 0;
                    $display_issue = $job_issue . '_' . $proc_name;

                    foreach ($arts as $a) {
                        $pages_count = is_numeric($a['pages']) ? (int)$a['pages'] : $a['pages'];
                        if (is_numeric($pages_count)) {
                            $total_pages += $pages_count;
                        }

                        echo '<tr style="text-align: center;">';
                        echo '<td>' . h($rec_date_fmt) . '</td>';
                        echo '<td>' . h($job_client) . '</td>';
                        echo '<td>' . h($display_issue) . '</td>';
                        echo '<td>' . h($a['article_name']) . '</td>';
                        echo '<td>' . h($a['pages']) . '</td>';
                        echo '</tr>';
                    }

                    // Total row for each process group (Fourth image format)
                    echo '<tr style="text-align: center;">';
                    echo '<td></td><td></td>';
                    echo '<td style="font-weight: bold;">Total</td>';
                    echo '<td></td>';
                    echo '<td style="font-weight: bold;">' . $total_pages . '</td>';
                    echo '</tr>';
                }
            }
        }
    }

    echo '</table>';
    echo '</body></html>';
    exit; 
}

// 2. Fetch Despatched Jobs & Articles for DataTables
if (isset($_GET['action']) && $_GET['action'] === 'fetch_jobs') {
    header('Content-Type: application/json');
    try {
        // Query retrieving both article-level and parent UQ process page details automatically
        $query = "
            SELECT 
                j.id as job_id, j.job_id as job_title, j.rec_date, 
                COALESCE(NULLIF(c.team_client_name, ''), NULLIF(c.client_name, ''), NULLIF(j.journal_name_client, ''), '-') as client_name,
                CASE 
                    WHEN j.journal_name_client IS NOT NULL AND j.journal_name_client != '' AND j.issue IS NOT NULL AND j.issue != '' 
                    THEN CONCAT(j.journal_name_client, '-', j.issue)
                    ELSE COALESCE(NULLIF(jb.journal_issue, ''), NULLIF(j.issue, ''), NULLIF(j.journal_name_client, ''), '-')
                END as issue_display,
                j.despatched_date, j.remarks, j.Billed, j.scielo_ebsco_status as job_scielo_status,
                (CASE 
                    WHEN (j.scielo_ebsco_status IS NOT NULL AND TRIM(j.scielo_ebsco_status) != '' AND TRIM(j.scielo_ebsco_status) != '-' AND TRIM(j.scielo_ebsco_status) != 'N/A' AND TRIM(j.scielo_ebsco_status) != 'NA' AND TRIM(j.scielo_ebsco_status) != '0')
                         OR EXISTS (
                             SELECT 1 FROM xml_job_articles ja 
                             WHERE ja.xml_job_id = j.id 
                               AND ja.scielo IS NOT NULL 
                               AND TRIM(ja.scielo) != '' 
                               AND TRIM(ja.scielo) != '-' 
                               AND TRIM(ja.scielo) != '0'
                         )
                    THEN 1 
                    ELSE 0 
                END) as has_scielo,
                a.id as article_id, 
                COALESCE(NULLIF(a.article_name, ''), NULLIF(j.articles, ''), '-') as article_name, 
                CASE WHEN a.id IS NOT NULL THEN COALESCE(NULLIF(a.nlm, ''), '-') ELSE COALESCE(NULLIF(j.xml_status, ''), '-') END as nlm, 
                CASE WHEN a.id IS NOT NULL THEN COALESCE(NULLIF(a.jats, ''), '-') ELSE '-' END as jats, 
                CASE WHEN a.id IS NOT NULL THEN COALESCE(NULLIF(a.clockss, ''), '-') ELSE '-' END as clockss, 
                CASE WHEN a.id IS NOT NULL THEN COALESCE(NULLIF(a.scielo, ''), '-') ELSE COALESCE(NULLIF(j.scielo_ebsco_status, ''), '-') END as scielo, 
                CASE WHEN a.id IS NOT NULL THEN COALESCE(NULLIF(a.ebsco, ''), '-') ELSE '-' END as ebsco, 
                CASE WHEN a.id IS NOT NULL THEN COALESCE(NULLIF(a.pubmed, ''), '-') ELSE COALESCE(NULLIF(j.pubmed_biblat_status, ''), '-') END as pubmed, 
                CASE WHEN a.id IS NOT NULL THEN COALESCE(NULLIF(a.biblat, ''), '-') ELSE '-' END as biblat, 
                CASE WHEN a.id IS NOT NULL THEN COALESCE(NULLIF(a.doaj, ''), '-') ELSE '-' END as doaj, 
                CASE WHEN a.id IS NOT NULL THEN COALESCE(NULLIF(a.html_1, ''), '-') ELSE COALESCE(NULLIF(j.html, ''), '-') END as html_1, 
                CASE WHEN a.id IS NOT NULL THEN COALESCE(NULLIF(a.new_html, ''), '-') ELSE COALESCE(NULLIF(j.new_html, ''), '-') END as new_html, 
                CASE WHEN a.id IS NOT NULL THEN COALESCE(NULLIF(a.direct_html, ''), '-') ELSE COALESCE(NULLIF(j.direct_html, ''), '-') END as direct_html, 
                a.table_data, a.wp, a.content_data, a.html_2, a.scielo_2, a.qc
            FROM unique_xml_jobs j
            LEFT JOIN jobs jb ON j.job_id = jb.job_id
            LEFT JOIN client_master c ON jb.client_id = c.client_id
            LEFT JOIN xml_job_articles a ON j.id = a.xml_job_id
            WHERE j.job_status = 'Despatched' 
            ORDER BY 
                j.updated_at DESC, 
                j.id DESC, 
                CASE WHEN a.nlm IS NOT NULL AND a.nlm != '' THEN 1 ELSE 2 END,
                CASE WHEN a.jats IS NOT NULL AND a.jats != '' THEN 1 ELSE 2 END,
                CASE WHEN a.clockss IS NOT NULL AND a.clockss != '' THEN 1 ELSE 2 END,
                CASE WHEN a.scielo IS NOT NULL AND a.scielo != '' THEN 1 ELSE 2 END,
                CASE WHEN a.ebsco IS NOT NULL AND a.ebsco != '' THEN 1 ELSE 2 END,
                CASE WHEN a.pubmed IS NOT NULL AND a.pubmed != '' THEN 1 ELSE 2 END,
                CASE WHEN a.biblat IS NOT NULL AND a.biblat != '' THEN 1 ELSE 2 END,
                CASE WHEN a.doaj IS NOT NULL AND a.doaj != '' THEN 1 ELSE 2 END,
                CASE WHEN a.html_1 IS NOT NULL AND a.html_1 != '' THEN 1 ELSE 2 END,
                CASE WHEN a.new_html IS NOT NULL AND a.new_html != '' THEN 1 ELSE 2 END,
                CASE WHEN a.table_data IS NOT NULL AND a.table_data != '' THEN 1 ELSE 2 END,
                CASE WHEN a.wp IS NOT NULL AND a.wp != '' THEN 1 ELSE 2 END,
                CASE WHEN a.content_data IS NOT NULL AND a.content_data != '' THEN 1 ELSE 2 END,
                CASE WHEN a.html_2 IS NOT NULL AND a.html_2 != '' THEN 1 ELSE 2 END,
                CASE WHEN a.scielo_2 IS NOT NULL AND a.scielo_2 != '' THEN 1 ELSE 2 END,
                CASE WHEN a.qc IS NOT NULL AND a.qc != '' THEN 1 ELSE 2 END,
                a.id ASC
        ";
        
        $result = $mysqli->query($query);
        
        if (!$result) { throw new Exception($mysqli->error); }

        $data = [];
        $last_job_id = null;
        
        while ($row = $result->fetch_assoc()) {
            // Setup flag to only display job-level data on the first article row of the job
            if ($row['job_id'] !== $last_job_id) {
                $row['is_first'] = true;
                $last_job_id = $row['job_id'];
            } else {
                $row['is_first'] = false;
            }

            // Force pubmed/biblat/doaj value to display as 1 if present
            if (!empty($row['pubmed']) && $row['pubmed'] !== '-') $row['pubmed'] = '1';
            if (!empty($row['biblat']) && $row['biblat'] !== '-') $row['biblat'] = '1';
            if (!empty($row['doaj']) && $row['doaj'] !== '-') $row['doaj'] = '1';

            $data[] = $row;
        }
        echo json_encode(['data' => $data]);
        
    } catch (Exception $e) {
        echo json_encode(['error' => 'Database Error: ' . $e->getMessage()]);
    }
    exit;
}

// 3. Fetch specific Job Details & Articles text for the Modal
if (isset($_GET['action']) && $_GET['action'] === 'get_job_details') {
    header('Content-Type: application/json');
    try {
        $job_id = (int)$_GET['job_id'];
        
        $stmt = $mysqli->prepare("SELECT id, job_id, Billed, despatched_date FROM unique_xml_jobs WHERE id = ?");
        $stmt->bind_param("i", $job_id);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$job) {
            echo json_encode(['success' => false, 'error' => 'Job not found']);
            exit;
        }
        
        $articles_text = "";
        // Only load NON-SCIELO articles in the main edit modal
        $stmt2 = $mysqli->prepare("SELECT article_name, article_count FROM xml_job_articles WHERE xml_job_id = ? AND (scielo IS NULL OR scielo = '' OR scielo = '-') GROUP BY article_name, article_count ORDER BY MIN(id) ASC");
        $stmt2->bind_param("i", $job_id);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        
        while ($row = $res2->fetch_assoc()) {
            $count = $row['article_count'] ? $row['article_count'] : "";
            $articles_text .= $row['article_name'] . "\t" . $count . "\n";
        }
        $stmt2->close();
        
        $stmt3 = $mysqli->prepare("
            SELECT 
                MAX(nlm) as p_nlm, MAX(jats) as p_jats, MAX(clockss) as p_clockss, 
                MAX(scielo) as p_sci, MAX(ebsco) as p_ebsco, 
                MAX(pubmed) as p_pub, MAX(biblat) as p_biblat, MAX(doaj) as p_doaj, 
                MAX(html_1) as p_html1, MAX(new_html) as p_nhtml,  MAX(direct_html) as p_dirhtml, MAX(table_data) as p_tab, 
                MAX(wp) as p_wp, MAX(content_data) as p_cont, MAX(html_2) as p_html2, 
                MAX(scielo_2) as p_sci2, MAX(qc) as p_qc
            FROM xml_job_articles 
            WHERE xml_job_id = ? AND (scielo IS NULL OR scielo = '' OR scielo = '-')
        ");
        $stmt3->bind_param("i", $job_id);
        $stmt3->execute();
        $procs = $stmt3->get_result()->fetch_assoc();
        $stmt3->close();
        
        $job['checked_processes'] = [];
        $map = [
            'p_nlm' => 'nlm', 'p_jats' => 'jats', 'p_clockss' => 'clockss',
            'p_sci' => 'scielo', 'p_ebsco' => 'ebsco',
            'p_pub' => 'pubmed', 'p_biblat' => 'biblat', 'p_doaj' => 'doaj',
            'p_html1' => 'html_1', 'p_nhtml' => 'new_html', 'p_dirhtml' => 'direct_html', 'p_tab' => 'table_data',
            'p_wp' => 'wp', 'p_cont' => 'content_data', 'p_html2' => 'html_2',
            'p_sci2' => 'scielo_2', 'p_qc' => 'qc'
        ];
        
        if ($procs) {
            foreach ($map as $db_key => $form_val) {
                if (!empty($procs[$db_key])) {
                    $job['checked_processes'][] = $form_val;
                }
            }
        }

        $job['articles_text'] = trim($articles_text);
        echo json_encode(['success' => true, 'data' => $job]);
    } catch (Exception $e) {
         echo json_encode(['success' => false, 'error' => 'Database Error: ' . $e->getMessage()]);
    }
    exit;
}

// 4. Save Updated Details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_job_details') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    $record_id = (int)$_POST['job_id'];
    $billed = $_POST['billed'] ?? '';
    $despatched_date = !empty($_POST['despatched_date']) ? $_POST['despatched_date'] : NULL;
    $articles_text = $_POST['articles_text'] ?? '';
    $selected_procs_raw = $_POST['processes'] ?? []; 
    
    // Force the correct column order from left to right
    $ordered_process_list = [
        'nlm', 'jats', 'clockss', 'scielo', 'ebsco', 'pubmed', 'biblat', 'doaj', 
        'html_1', 'new_html', 'direct_html', 'table_data', 'wp', 'content_data', 
        'html_2', 'scielo_2', 'qc'
    ];
    $selected_procs = array_intersect($ordered_process_list, $selected_procs_raw);
    
    try {
        $mysqli->begin_transaction();
        
        // 1. Update Parent Job
        $stmt = $mysqli->prepare("UPDATE unique_xml_jobs SET Billed = ?, despatched_date = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("ssi", $billed, $despatched_date, $record_id);
        $stmt->execute();
        $stmt->close();
        
        // 2. Clear old NON-SCIELO articles (Preserve Scielo articles!)
        $del_stmt = $mysqli->prepare("DELETE FROM xml_job_articles WHERE xml_job_id = ? AND (scielo IS NULL OR scielo = '' OR scielo = '-')");
        $del_stmt->bind_param("i", $record_id);
        $del_stmt->execute();
        $del_stmt->close();
        
        // 3. Pre-Parse the Articles Text
        $parsed_articles = [];
        if (!empty(trim($articles_text))) {
            $lines = explode("\n", str_replace("\r", "", $articles_text));
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                $name = ""; $count = "";
                
                $parts = explode("\t", $line);
                if (count($parts) >= 2) {
                    $name = trim($parts[0]);
                    $count = trim($parts[1]);
                } else {
                    $parts = explode(",", $line);
                    if (count($parts) >= 2) {
                        $name = trim($parts[0]);
                        $count = trim($parts[1]);
                    } else {
                        $name = $line;
                    }
                }
                
                // Fallback for space separation (e.g. "A 10")
                if (empty($count)) {
                    $lastSpace = strrpos($name, ' ');
                    if ($lastSpace !== false) {
                        $potentialCount = substr($name, $lastSpace + 1);
                        if (is_numeric($potentialCount)) {
                            $count = $potentialCount;
                            $name = substr($name, 0, $lastSpace);
                        }
                    }
                }
                
                if (!empty($name)) {
                    $parsed_articles[] = ['name' => $name, 'count' => $count];
                }
            }
        }

        // 4. Insert rows GROUPED BY PROCESS (This forces NLM to show up first!)
        if (!empty($parsed_articles)) {
            $ins_stmt = $mysqli->prepare("
                INSERT INTO xml_job_articles 
                (xml_job_id, article_name, article_count, nlm, jats, clockss, scielo, ebsco, pubmed, biblat, doaj, html_1, new_html, direct_html, table_data, wp, content_data, html_2, scielo_2, qc) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (empty($selected_procs)) {
                foreach ($parsed_articles as $art) {
                    $empty = '';
                    $ins_stmt->bind_param("isssssssssssssssssss", 
                        $record_id, $art['name'], $art['count'], 
                        $empty, $empty, $empty, $empty, $empty, $empty, $empty, $empty, 
                        $empty, $empty, $empty, $empty, $empty, $empty, $empty, $empty, $empty
                    );
                    $ins_stmt->execute();
                }
            } else {
                foreach ($selected_procs as $proc) {
                    foreach ($parsed_articles as $art) {
                        $p_nlm    = ($proc === 'nlm') ? (string)$art['count'] : '';
                        $p_jats   = ($proc === 'jats') ? (string)$art['count'] : '';
                        $p_clockss= ($proc === 'clockss') ? (string)$art['count'] : '';
                        $p_sci    = ($proc === 'scielo') ? (string)$art['count'] : '';
                        $p_ebsco  = ($proc === 'ebsco') ? (string)$art['count'] : '';
                        $p_pub    = ($proc === 'pubmed') ? '1' : '';
                        $p_biblat = ($proc === 'biblat') ? '1' : '';
                        $p_doaj   = ($proc === 'doaj') ? '1' : '';
                        
                        $p_html1  = ($proc === 'html_1') ? (string)$art['count'] : '';
                        $p_nhtml  = ($proc === 'new_html') ? (string)$art['count'] : '';
                        $p_dirhtml = ($proc === 'direct_html') ? (string)$art['count'] : '';
                        $p_table  = ($proc === 'table_data') ? (string)$art['count'] : '';
                        $p_wp     = ($proc === 'wp') ? (string)$art['count'] : '';
                        $p_cont   = ($proc === 'content_data') ? (string)$art['count'] : '';
                        $p_html2  = ($proc === 'html_2') ? (string)$art['count'] : '';
                        $p_sci2   = ($proc === 'scielo_2') ? (string)$art['count'] : '';
                        $p_qc     = ($proc === 'qc') ? (string)$art['count'] : '';

                        $ins_stmt->bind_param("isssssssssssssssssss", 
                            $record_id, $art['name'], $art['count'], 
                            $p_nlm, $p_jats, $p_clockss, $p_sci, $p_ebsco, $p_pub, $p_biblat, $p_doaj, 
                            $p_html1, $p_nhtml, $p_dirhtml, 
                            $p_table, $p_wp, $p_cont, $p_html2, $p_sci2, $p_qc
                        );
                        $ins_stmt->execute();
                    }
                }
            }
            $ins_stmt->close();
        }

        $mysqli->commit();
        $response['success'] = true;
        $response['message'] = 'Job details and individual article processes updated successfully!';
    } catch (Exception $e) {
        $mysqli->rollback();
        $response['error'] = 'Database Error: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}
// 5. Fetch ALL Original Clients directly from client_master table for Dropdown
if (isset($_GET['action']) && $_GET['action'] === 'get_all_clients') {
    header('Content-Type: application/json');
    try {
        // We use the same COALESCE logic you used in main query so the filter matches perfectly
        $query = "SELECT DISTINCT COALESCE(NULLIF(team_client_name, ''), NULLIF(client_name, '')) as c_name 
                  FROM client_master 
                  WHERE client_name IS NOT NULL AND client_name != '' 
                  ORDER BY c_name ASC";
                  
        $result = $mysqli->query($query);
        $clients = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                if (!empty($row['c_name']) && $row['c_name'] !== '-') {
                    $clients[] = $row['c_name'];
                }
            }
        }
        echo json_encode(['success' => true, 'clients' => $clients]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
// ==========================================
// SCIELO SPECIFIC HANDLERS
// ==========================================

// A. Download Scielo Excel
if (isset($_GET['action']) && $_GET['action'] === 'download_scielo_excel') {
    $job_id = (int)$_GET['job_id'];

    $stmt = $mysqli->prepare("SELECT j.*, COALESCE(NULLIF(c.team_client_name, ''), NULLIF(c.client_name, ''), NULLIF(j.journal_name_client, ''), '-') as full_client_name, CASE WHEN j.journal_name_client IS NOT NULL AND j.journal_name_client != '' AND j.issue IS NOT NULL AND j.issue != '' THEN CONCAT(j.journal_name_client, '-', j.issue) ELSE j.issue END as full_issue FROM unique_xml_jobs j LEFT JOIN jobs jb ON j.job_id = jb.job_id LEFT JOIN client_master c ON jb.client_id = c.client_id WHERE j.id = ?");
    $stmt->bind_param("i", $job_id);
    $stmt->execute();
    $job = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$job) { die("Job not found."); }

    // Fetch ONLY SCIELO articles
    $stmt = $mysqli->prepare("SELECT * FROM xml_job_articles WHERE xml_job_id = ? AND scielo IS NOT NULL AND scielo != '' AND scielo != '-' ORDER BY id ASC");
    $stmt->bind_param("i", $job_id);
    $stmt->execute();
    $articles = $stmt->get_result();
    $stmt->close();

    $client_name = !empty($job['full_client_name']) ? $job['full_client_name'] : 'Unknown_Client';
    $client_name_clean = preg_replace('/[^A-Za-z0-9_\- ]/', '_', $client_name); 
    $filename = $client_name_clean . "_SCIELO_" . date('F_Y') . ".xls";

    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta http-equiv="content-type" content="text/plain; charset=UTF-8"/></head><body>';
    echo '<table border="1" cellpadding="3" cellspacing="0" style="font-family: Calibri, sans-serif; font-size: 11pt;">';
    echo '<tr style="background-color: #f0ad4e; font-weight: bold; text-align: center;">';
    echo '<th>Rec. Date</th><th>Journal Name</th><th>Issue</th><th>Article Name</th><th>Pages</th>';
    echo '</tr>';

    $rec_date_fmt = !empty($job['rec_date']) ? date('j-M-y', strtotime($job['rec_date'])) : '-'; 
    $job_client = !empty($job['full_client_name']) ? $job['full_client_name'] : '-';
    $job_issue = !empty($job['issue']) ? $job['issue'] : '-';

    if ($articles->num_rows > 0) {
        $total_pages = 0;
        
        while ($art = $articles->fetch_assoc()) {
            // Sum logic for Total Row
            $pages_count = is_numeric($art['scielo']) ? (int)$art['scielo'] : $art['scielo'];
            if (is_numeric($pages_count)) {
                $total_pages += $pages_count;
            }
            
            // Append Scielo to Issue
            $display_issue = $job_issue . '_Scielo';
            
            echo '<tr style="text-align: center;">';
            echo '<td>' . h($rec_date_fmt) . '</td>';
            echo '<td>' . h($job_client) . '</td>';
            echo '<td>' . h($display_issue) . '</td>';
            echo '<td>' . h($art['article_name']) . '</td>';
            echo '<td>' . h($art['scielo']) . '</td>';
            echo '</tr>';
        }
        
        // TOTAL ROW
        echo '<tr style="text-align: center;">';
        echo '<td></td><td></td>';
        echo '<td style="font-weight: bold;">Total</td>';
        echo '<td></td>';
        echo '<td style="font-weight: bold;">' . $total_pages . '</td>';
        echo '</tr>';
        
    } else {
        echo '<tr><td colspan="5" style="text-align:center;">No Scielo articles found for this job.</td></tr>';
    }
    echo '</table></body></html>';
    exit; 
}

// B. Get Scielo Details for Modal
if (isset($_GET['action']) && $_GET['action'] === 'get_scielo_details') {
    header('Content-Type: application/json');
    $job_id = (int)$_GET['job_id'];
    $articles_text = "";
    $stmt = $mysqli->prepare("SELECT article_name, article_count FROM xml_job_articles WHERE xml_job_id = ? AND scielo IS NOT NULL AND scielo != '' AND scielo != '-' ORDER BY id ASC");
    $stmt->bind_param("i", $job_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $count = $row['article_count'] ? $row['article_count'] : "";
        $articles_text .= $row['article_name'] . "\t" . $count . "\n";
    }
    $stmt->close();
    echo json_encode(['success' => true, 'data' => ['articles_text' => trim($articles_text)]]);
    exit;
}

// C. Save Scielo Details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_scielo_details') {
    header('Content-Type: application/json');
    $record_id = (int)$_POST['job_id'];
    $articles_text = $_POST['scielo_articles_text'] ?? '';
    
    try {
        $mysqli->begin_transaction();
        
        // Delete ONLY Scielo articles
        $del_stmt = $mysqli->prepare("DELETE FROM xml_job_articles WHERE xml_job_id = ? AND scielo IS NOT NULL AND scielo != '' AND scielo != '-'");
        $del_stmt->bind_param("i", $record_id);
        $del_stmt->execute();
        $del_stmt->close();
        
        $parsed_articles = [];
        if (!empty(trim($articles_text))) {
            $lines = explode("\n", str_replace("\r", "", $articles_text));
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                $name = ""; $count = "";
                $parts = explode("\t", $line);
                if (count($parts) >= 2) { $name = trim($parts[0]); $count = trim($parts[1]); } 
                else {
                    $parts = explode(",", $line);
                    if (count($parts) >= 2) { $name = trim($parts[0]); $count = trim($parts[1]); } 
                    else { $name = $line; }
                }
                if (empty($count)) {
                    $lastSpace = strrpos($name, ' ');
                    if ($lastSpace !== false) {
                        $potentialCount = substr($name, $lastSpace + 1);
                        if (is_numeric($potentialCount)) { $count = $potentialCount; $name = substr($name, 0, $lastSpace); }
                    }
                }
                if (!empty($name)) { $parsed_articles[] = ['name' => $name, 'count' => $count]; }
            }
        }

        if (!empty($parsed_articles)) {
            $ins_stmt = $mysqli->prepare("INSERT INTO xml_job_articles (xml_job_id, article_name, article_count, nlm, jats, clockss, scielo, ebsco, pubmed, biblat, doaj, html_1, new_html, direct_html, table_data, wp, content_data, html_2, scielo_2, qc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $empty = '';
            foreach ($parsed_articles as $art) {
                $sci_count = (string)$art['count'];
                $ins_stmt->bind_param("isssssssssssssssssss", $record_id, $art['name'], $art['count'], $empty, $empty, $empty, $sci_count, $empty, $empty, $empty, $empty, $empty, $empty, $empty, $empty, $empty, $empty, $empty, $empty, $empty);
                $ins_stmt->execute();
            }
            $ins_stmt->close();
        }

        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => 'Scielo articles updated successfully!']);
    } catch (Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => 'Database Error: ' . $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Add this just before </head> -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        /* Making Select2 match Bootstrap small input size */
        .select2-container .select2-selection--single { height: 31px !important; border: 1px solid #dee2e6 !important; border-radius: 0.25rem !important; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 29px !important; font-size: 0.875rem !important; color: #334155; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 29px !important; }
    </style>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Despatched Jobs Delivery & Articles</title>
    <!-- <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet"> -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        body { 
            background-color: #f8fafc; 
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; 
            font-size: 12.5px; 
            color: #334155; 
        }
        .card { border: none; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border-radius: 0.75rem; }
        .card-header { background: #ffffff; border-bottom: 1px solid #e2e8f0; border-radius: 0.75rem 0.75rem 0 0 !important; }
        
        /* Table scroll container for vertical freezing */
        .dataTables_wrapper .dataTables_scrollBody { max-height: 65vh; overflow-y: auto; }
        
        table.dataTable { white-space: nowrap; width: 100% !important; } 
        
        /* HEADER STICKY & GREY BACKGROUND */
        table.dataTable thead th { 
            background-color: #5e6b77 !important; 
            color: #ffffff !important;
            text-align: center !important;  
            font-weight: 600; 
            text-transform: uppercase; 
            font-size: 12.5px;
            position: sticky !important; 
            top: 0; 
            z-index: 20; 
        }

        /* 1. Force Border Collapse to Remove ALL Gaps */
        table.dataTable {
            border-collapse: collapse !important; /
            border-spacing: 0 !important;
            margin: 0 !important;
            width: 100% !important;
        }

        /* 2. STICKY HEADERS (Top Layer) */
        table.dataTable thead th {
            position: sticky !important;
            top: 0;
            z-index: 100 !important; 
            background-color: #5e6b77 !important;
            border: 1px solid #dee2e6 !important;
        }

        /* 3. STICKY COLUMNS (First 3 Columns Locked with Exact Widths) */
        table.dataTable tbody td:nth-child(1), table.dataTable thead th:nth-child(1) { 
            position: sticky !important; left: 0; z-index: 50 !important; border: 1px solid #dee2e6 !important;
            width: 110px !important; min-width: 110px !important; max-width: 110px !important; box-sizing: border-box !important;
            overflow: hidden; text-overflow: ellipsis;
        }
        table.dataTable tbody td:nth-child(2), table.dataTable thead th:nth-child(2) { 
            position: sticky !important; left: 110px; z-index: 50 !important; border: 1px solid #dee2e6 !important;
            width: 200px !important; min-width: 200px !important; max-width: 200px !important; box-sizing: border-box !important;
            overflow: hidden; text-overflow: ellipsis;
        }
        table.dataTable tbody td:nth-child(3), table.dataTable thead th:nth-child(3) { 
            position: sticky !important; left: 310px; z-index: 50 !important; border: 1px solid #dee2e6 !important; border-right: 2px solid #a0a5ab !important;
            width: 180px !important; min-width: 180px !important; max-width: 180px !important; box-sizing: border-box !important;
            overflow: hidden; text-overflow: ellipsis;
        }
        
        table.dataTable thead th:nth-child(-n+3) { z-index: 110 !important; background-color: #5e6b77 !important; }

        /* 4. Group differentiation styling (Issue-wise colors) */
        .job-group-even td { background-color: #ffffff !important; }
        .job-group-odd td { background-color: #f1f5f9 !important; }
        
        /* Thick line between different Issues */
        .job-group-start td { border-top: 2px solid #475569 !important; }
        
        /* Fixed colors for sticky columns */
        .job-group-even td:nth-child(-n+3) { background-color: #ffffff !important; }
        .job-group-odd td:nth-child(-n+3) { background-color: #f1f5f9 !important; }

        /* 5. FULL ROW HOVER EFFECT (No Gaps) */
        table.dataTable tbody tr:hover td {
            background-color: #c4cbd3 !important; 
            cursor: pointer;
        }

        /* 6. Base Cell Controls */
        table.dataTable tbody td { 
            font-size: 12.5px !important; 
            padding: 8px 10px !important;
            border: 1px solid #dee2e6 !important; /* All borders enabled to fill gaps */
            white-space: nowrap;
        }

        textarea.excel-paste { font-family: monospace; font-size: 0.85rem; white-space: pre; overflow-x: auto; border: 2px dashed #cbd5e1; }
        textarea.excel-paste:focus { border-color: #3b82f6; box-shadow: 0 0 0 0.25rem rgba(59, 130, 246, 0.25); }
        #notificationContainer { position: fixed; top: 20px; right: 20px; z-index: 2000; min-width: 300px; }
        .process-grid .form-check { margin-bottom: 0.5rem; }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    <br><br><br>

    <div id="notificationContainer"></div>

    <div class="container-fluid mt-4 px-4 pb-5">
        <div class="card mb-4">
            <div class="card-header py-3">
            <h5 class="mb-0 fw-bold" style="color: #0f172a;"><i class="bi bi-send-check-fill text-success me-2"></i> Upload Status</h5>
        </div>
        
        <div class="card-body p-4">
            <!-- NEW MULTI-FILTER BAR -->
            <div class="row g-2 mb-4 bg-light p-3 rounded border shadow-sm">
                <div class="col-md-2">
                    <label class="form-label text-muted small fw-bold mb-1">Client Name</label>
                    <select id="clientFilter" class="form-select form-select-sm" style="width: 100%;">
                        <option value="">All Clients</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label text-muted small fw-bold mb-1">Issue</label>
                    <input type="text" id="issueFilter" class="form-control form-control-sm" placeholder="Search Issue...">
                </div>
                <div class="col-md-2">
                    <label class="form-label text-muted small fw-bold mb-1">Process</label>
                    <select id="processFilterDropdown" class="form-select form-select-sm">
                        <option value="">All Processes</option>
                        <option value="4">NLM</option>
                        <option value="5">JATS</option>
                        <option value="6">Clockss</option>
                        <option value="7">Scielo</option>
                        <option value="8">EBSCO</option>
                        <option value="9">PubMed</option>
                        <option value="10">Biblat</option>
                        <option value="11">DOAJ</option>
                        <option value="12">HTML</option>
                        <option value="13">New HTML</option>
                        <option value="14">Direct HTML</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label text-muted small fw-bold mb-1">Billed Status</label>
                    <select id="billedFilter" class="form-select form-select-sm">
                        <option value="">All Data</option>
                        <option value="Billed">Billed</option>
                        <option value="Pending">Pending</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label text-muted small fw-bold mb-1">Month (Dispatch)</label>
                    <select id="monthFilter" class="form-select form-select-sm">
                        <option value="">All Months</option>
                        <option value="Jan">January</option>
                        <option value="Feb">February</option>
                        <option value="Mar">March</option>
                        <option value="Apr">April</option>
                        <option value="May">May</option>
                        <option value="Jun">June</option>
                        <option value="Jul">July</option>
                        <option value="Aug">August</option>
                        <option value="Sep">September</option>
                        <option value="Oct">October</option>
                        <option value="Nov">November</option>
                        <option value="Dec">December</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label text-muted small fw-bold mb-1">Year (Dispatch)</label>
                    <select id="yearFilter" class="form-select form-select-sm">
                        <option value="">All Years</option>
                        <option value="2024">2024</option>
                        <option value="2025">2025</option>
                        <option value="2026">2026</option>
                        <option value="2027">2027</option>
                    </select>
                </div>
                
                <!-- Filter & Clear Buttons -->
                <div class="col-md-12 d-flex justify-content-end gap-2 mt-3 border-top pt-3">
                    <button type="button" id="clearFilterBtn" class="btn btn-secondary btn-sm px-4 fw-bold shadow-sm">
                        <i class="bi bi-x-circle me-1"></i> Clear
                    </button>
                    <button type="button" id="applyFilterBtn" class="btn btn-primary btn-sm px-4 fw-bold shadow-sm">
                        <i class="bi bi-funnel-fill me-1"></i> Filter
                    </button>
                    <button type="button" id="downloadMonthlyExcelBtn" class="btn btn-success btn-sm px-4 fw-bold shadow-sm text-white">
                        <i class="bi bi-file-earmark-excel me-1"></i> Download Client Excel
                    </button>
                </div>
            </div>
            <!-- FILTER BAR END -->
            
            <div class="card-body p-4">
                <table id="completedJobsTable" class="table table-hover w-100 mb-0 border-top">
                    <thead>
                        <tr>
                            <!-- Removed Job ID column from display so it perfectly matches your screenshot format -->
                            <th>Rec. Date</th>
                            <th>Client Name</th>
                            <th>Issue</th>
                            <th>Article Name</th>
                            <th>NLM</th>
                            <th>JATS</th>
                            <th>Clockss</th>
                            <th>Scielo</th>
                            <th>EBSCO</th>
                            <th>PubMed</th>
                            <th>Biblat</th>
                            <th>DOAJ</th>
                            <th>HTML</th>
                            <th>New HTML</th>
                            <th>Direct HTML</th>
                            <th>Dispatch Date</th>
                            <!--th>Table</th>
                            <th>WP</th>
                            <th>Content</th>
                            <th>HTML 2</th>
                            <th>Scielo 2</th>
                            <th>QC</th-->
                            <th>Remarks</th>
                            <th>Billed</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>

    <!-- Update Job Modal -->
    <div class="modal fade" id="jobModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="updateJobForm">
                    <div class="modal-header bg-light">
                        <h5 class="modal-title fw-bold">Update Delivery & Articles <span id="modalJobTitle" class="badge bg-primary ms-2"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body p-4">
                        <input type="hidden" name="action" value="save_job_details">
                        <input type="hidden" name="job_id" id="modal_job_id" value="">

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark">Billed Status</label>
                                <select name="billed" id="modal_billed" class="form-select border-secondary">
                                    <option value="">Pending / Blank</option>
                                    <option value="Yes">Yes</option>
                                    <option value="No">No</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark">Dispatch Date</label>
                                <input type="date" name="despatched_date" id="modal_despatched_date" class="form-control border-secondary">
                            </div>
                        </div>

                        <!-- Process Checkboxes -->
                        <div class="mb-4 p-3 border rounded bg-light process-grid">
                            <label class="form-label fw-bold text-dark mb-2">
                                <i class="bi bi-ui-checks-grid text-primary me-1"></i> Apply Processes to pasted Articles:
                            </label>
                            <p class="text-muted small mb-2">Each checked process creates a separate row. Rows will be grouped by process.</p>
                            <div class="row g-2">
                                <div class="col-md-4"><div class="form-check"><input class="form-check-input proc-checkbox" type="checkbox" name="processes[]" value="nlm" id="p_nlm"><label class="form-check-label" for="p_nlm">NLM</label></div></div>
                                <div class="col-md-4"><div class="form-check"><input class="form-check-input proc-checkbox" type="checkbox" name="processes[]" value="jats" id="p_jats"><label class="form-check-label" for="p_jats">JATS</label></div></div>
                                <div class="col-md-4"><div class="form-check"><input class="form-check-input proc-checkbox" type="checkbox" name="processes[]" value="clockss" id="p_clockss"><label class="form-check-label" for="p_clockss">Clockss</label></div></div>
                                <div class="col-md-4"><div class="form-check"><input class="form-check-input proc-checkbox" type="checkbox" name="processes[]" value="scielo" id="p_sci"><label class="form-check-label" for="p_sci">Scielo</label></div></div>
                                <div class="col-md-4"><div class="form-check"><input class="form-check-input proc-checkbox" type="checkbox" name="processes[]" value="ebsco" id="p_ebsco"><label class="form-check-label" for="p_ebsco">EBSCO</label></div></div>
                                <div class="col-md-4"><div class="form-check"><input class="form-check-input proc-checkbox" type="checkbox" name="processes[]" value="pubmed" id="p_pub"><label class="form-check-label" for="p_pub">PubMed</label></div></div>
                                <div class="col-md-4"><div class="form-check"><input class="form-check-input proc-checkbox" type="checkbox" name="processes[]" value="biblat" id="p_biblat"><label class="form-check-label" for="p_biblat">Biblat</label></div></div>
                                <div class="col-md-4"><div class="form-check"><input class="form-check-input proc-checkbox" type="checkbox" name="processes[]" value="doaj" id="p_doaj"><label class="form-check-label" for="p_doaj">DOAJ</label></div></div>
                                
                                <div class="col-md-4"><div class="form-check"><input class="form-check-input proc-checkbox" type="checkbox" name="processes[]" value="html_1" id="p_html1"><label class="form-check-label" for="p_html1">HTML</label></div></div>
                                <div class="col-md-4"><div class="form-check"><input class="form-check-input proc-checkbox" type="checkbox" name="processes[]" value="new_html" id="p_nhtml"><label class="form-check-label" for="p_nhtml">New HTML</label></div></div>
                                <div class="col-md-4"><div class="form-check"><input class="form-check-input proc-checkbox" type="checkbox" name="processes[]" value="direct_html" id="p_dirhtml"><label class="form-check-label" for="p_dirhtml">Direct HTML</label></div></div>

                                <!-- <div class="col-md-4"><div class="form-check"><input class="form-check-input proc-checkbox" type="checkbox" name="processes[]" value="table_data" id="p_tab"><label class="form-check-label" for="p_tab">Table</label></div></div> -->
                                
                                <!-- <div class="col-md-4"><div class="form-check"><input class="form-check-input proc-checkbox" type="checkbox" name="processes[]" value="wp" id="p_wp"><label class="form-check-label" for="p_wp">WP</label></div></div> -->
                                <!-- <div class="col-md-4"><div class="form-check"><input class="form-check-input proc-checkbox" type="checkbox" name="processes[]" value="content_data" id="p_cont"><label class="form-check-label" for="p_cont">Content</label></div></div> -->
                                <!-- <div class="col-md-4"><div class="form-check"><input class="form-check-input proc-checkbox" type="checkbox" name="processes[]" value="html_2" id="p_html2"><label class="form-check-label" for="p_html2">HTML 2</label></div></div> -->
                                
                                <!-- <div class="col-md-4"><div class="form-check"><input class="form-check-input proc-checkbox" type="checkbox" name="processes[]" value="scielo_2" id="p_sci2"><label class="form-check-label" for="p_sci2">Scielo 2</label></div></div>
                                <div class="col-md-4"><div class="form-check"><input class="form-check-input proc-checkbox" type="checkbox" name="processes[]" value="qc" id="p_qc"><label class="form-check-label" for="p_qc">QC</label></div></div> -->
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-bold text-dark mb-1"><i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Paste Articles & Base Counts</label>
                            <textarea name="articles_text" id="modal_articles_text" class="form-control excel-paste" rows="8"></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success px-4 fw-bold" id="saveBtn">
                            <i class="bi bi-floppy me-1"></i> Save Updates
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Scielo Edit Modal -->
    <div class="modal fade" id="scieloJobModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="updateScieloForm">
                    <div class="modal-header bg-secondary">
                        <h5 class="modal-title fw-bold text-white">Update Scielo Articles <span id="modalScieloJobTitle" class="badge bg-dark ms-2"></span></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4">
                        <input type="hidden" name="action" value="save_scielo_details">
                        <input type="hidden" name="job_id" id="modal_scielo_job_id" value="">
                        <div class="mb-2">
                            <label class="form-label fw-bold text-dark mb-1"><i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Paste SCIELO Articles & Counts</label>
                            <textarea name="scielo_articles_text" id="modal_scielo_articles_text" class="form-control excel-paste" rows="8"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4 fw-bold text-white" id="saveScieloBtn">
                            <i class="bi bi-floppy me-1"></i> Save Scielo
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        let table;

        function showNotification(message, type = 'success') {
            const bg = type === 'success' ? 'bg-success' : 'bg-danger';
            const toast = `<div class="toast show align-items-center text-white ${bg} border-0 mb-2 shadow"><div class="d-flex"><div class="toast-body fw-medium">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="$(this).closest('.toast').remove()"></button></div></div>`;
            $('#notificationContainer').append(toast);
            setTimeout(() => $('.toast').first().fadeOut(300, function() { $(this).remove(); }), 4000);
        }

        $(document).ready(function() {
            let lastJobId = null;
            let groupToggle = false;

            table = $('#completedJobsTable').DataTable({
                ajax: '?action=fetch_jobs',
                scrollX: true, 
                autoWidth: false,
                ordering: false,
                pageLength: 50,
                columnDefs: [
                    { targets: "_all", className: "text-center" }
                ],
               
                createdRow: function(row, data, dataIndex) {
                    if (data.job_id !== lastJobId) {
                        lastJobId = data.job_id;
                        groupToggle = !groupToggle; 
                        $(row).addClass('job-group-start'); 
                    }
                    $(row).addClass(groupToggle ? 'job-group-odd' : 'job-group-even');
                },
                columns: [
                    // These fields now output on EVERY record
                    { 
                        data: 'rec_date', 
                        render: function(data) { 
                            if (!data) return '-';
                            let d = new Date(data);
                            if (isNaN(d.getTime())) return data;
                            const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
                            let day = ("0" + d.getDate()).slice(-2);
                            let monthName = months[d.getMonth()]; // This is defined
                            let year = d.getFullYear();
                            return `${day}-${monthName}-${year}`; // Used monthName here (Fixed)
                        } 
                    },
                    { data: 'client_name', render: function(data) { return data || '-'; } },
                    { data: 'issue_display', render: function(data) { return data || '-'; } },
                    
                    { data: 'article_name', defaultContent: '-' },
                    { data: 'nlm', defaultContent: '-' },
                    { data: 'jats', defaultContent: '-' },
                    { data: 'clockss', defaultContent: '-' },
                    { data: 'scielo', defaultContent: '-' },
                    { data: 'ebsco', defaultContent: '-' },
                    { data: 'pubmed', defaultContent: '-' },
                    { data: 'biblat', defaultContent: '-' },
                    { data: 'doaj', defaultContent: '-' },
                    { data: 'html_1', defaultContent: '-' },
                    { data: 'new_html', defaultContent: '-' },
                    { data: 'direct_html', defaultContent: '-' },
                    
                    // Dispatch Date and Remarks now output on EVERY record
                    { 
                        data: 'despatched_date', 
                        render: function(data) {
                            if (!data) return '<span class="text-muted">-</span>';
                            let d = new Date(data);
                            if (isNaN(d.getTime())) return data;
                            const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
                            let day = ("0" + d.getDate()).slice(-2);
                            let monthName = months[d.getMonth()];
                            let year = d.getFullYear();
                            return `<i class="bi bi-calendar-event me-1 text-muted"></i> ${day}-${monthName}-${year}`;
                        }
                    },
                    { data: 'remarks', render: function(data) { return data || '-'; } },
                    
                    // Only these 2 fields are single-occurrence per job
                    { 
                        data: 'Billed', 
                        render: function(data, type, row) {
                            if (!row.is_first) return ''; // Single
                            return data === 'Yes' ? `<span class="badge bg-success bg-opacity-10 text-success border border-success">Billed</span>` : '<span class="text-muted">-</span>';
                        }
                    },
                    {
                        data: null,
                        orderable: false,
                        className: 'text-center',
                        render: function(data, type, row) {
                            if (!row.is_first) return ''; // Single
                            
                            // Check if Scielo count exists securely via backend flag
                            let scieloButtons = '';
                            
                            if (row.has_scielo == 1) {
                                scieloButtons = `
                                    <div class="d-flex gap-1 justify-content-center mt-1">
                                        <button class="btn btn-sm btn-primary shadow-sm" style="width: 65px; font-size:11px;" onclick="openScieloEditModal(${row.job_id}, '${row.job_title}')" title="Edit Scielo">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                        <a href="?action=download_scielo_excel&job_id=${row.job_id}" class="btn btn-sm btn-success shadow-sm text-white" style="width: 65px; font-size:11px;" title="Download Scielo Excel">
                                            <i class="bi bi-file-excel"></i> Excel
                                        </a>
                                    </div>
                                `;
                            }
                            
                            return `
                                <div class="d-flex flex-column justify-content-center my-1">
                                    <div class="d-flex gap-1 justify-content-center">
                                        <button class="btn btn-sm btn-primary shadow-sm" style="width: 65px; font-size:11px;" onclick="openEditModal(${row.job_id}, '${row.job_title}')" title="Edit Main">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                        <a href="?action=download_excel&job_id=${row.job_id}" class="btn btn-sm btn-success shadow-sm" style="width: 65px; font-size:11px;" title="Download Main Excel">
                                            <i class="bi bi-file-excel"></i> Excel
                                        </a>
                                    </div>
                                    ${scieloButtons}
                                </div>
                            `;
                        }
                    }
                ]
            });

            // --- NEW MULTI-FILTER LOGIC START ---

            // 1. Initialize Searchable Dropdown for Client
            $('#clientFilter').select2({
                placeholder: "Search Client...",
                allowClear: true
            });

            // 2. Fetch ALL original clients directly from client_master database
            $.ajax({
                url: '?action=get_all_clients',
                type: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success && res.clients) {
                        var clientSelect = $('#clientFilter');
                        var currentVal = clientSelect.val();
                        
                        clientSelect.empty().append('<option value="">All Clients</option>');
                        res.clients.forEach(function(clientName) {
                            clientSelect.append('<option value="' + clientName + '">' + clientName + '</option>');
                        });
                        
                        if(currentVal) clientSelect.val(currentVal).trigger('change');
                    }
                },
                error: function() {
                    console.error("Failed to load clients from database.");
                }
            });

            // 3. Custom search condition for Month, Year, and Billed Status
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                var selectedMonth = $('#monthFilter').val();
                var selectedYear = $('#yearFilter').val();
                var billedStatus = $('#billedFilter').val();
                
                var dispatchDateCell = data[15] || ""; // Col 15 Dispatch Date
                var billedCell = data[17] || "";       // Col 17 Billed Status
                
                if (billedStatus === 'Billed' && billedCell.indexOf('Billed') === -1) return false;
                if (billedStatus === 'Pending' && billedCell.indexOf('Billed') !== -1) return false;
                if (selectedMonth && dispatchDateCell.indexOf(selectedMonth) === -1) return false;
                if (selectedYear && dispatchDateCell.indexOf(selectedYear) === -1) return false;

                return true; 
            });

            // 4. Apply Filter ONLY when "Filter" button is clicked
            $('#applyFilterBtn').on('click', function() {
                var clientVal = $('#clientFilter').val();
                var issueVal = $('#issueFilter').val();
                var processVal = $('#processFilterDropdown').val();

                // Search exact Client Name
                if (clientVal) {
                    table.column(1).search('^' + $.fn.dataTable.util.escapeRegex(clientVal) + '$', true, false);
                } else {
                    table.column(1).search('');
                }

                // Search Issue
                table.column(2).search(issueVal);

                // Search Process
                table.columns([4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14]).search(''); // Reset old
                if (processVal !== "") {
                    table.column(processVal).search('[0-9]', true, false);
                }

                // Trigger datatable redraw
                table.draw();
            });

            // 5. Clear ALL Filters when "Clear" button is clicked
            $('#clearFilterBtn').on('click', function() {
                $('#clientFilter').val('').trigger('change'); // reset select2
                $('#issueFilter').val('');
                $('#processFilterDropdown').val('');
                $('#billedFilter').val('');
                $('#monthFilter').val('');
                $('#yearFilter').val('');
                
                // Clear datatable internal searches
                table.search('').columns().search('');
                table.draw();
            });

            // 6. Download Filtered Client & Month Excel
            $('#downloadMonthlyExcelBtn').on('click', function() {
                var clientVal = $('#clientFilter').val();
                var monthVal = $('#monthFilter').val();
                var yearVal = $('#yearFilter').val();

                if (!clientVal) {
                    alert('Please select a Client Name from the dropdown first.');
                    return;
                }

                var url = '?action=download_client_month_excel&client=' + encodeURIComponent(clientVal) + '&month=' + encodeURIComponent(monthVal) + '&year=' + encodeURIComponent(yearVal);
                window.location.href = url;
            });
            // --- NEW MULTI-FILTER LOGIC END ---

            $('#updateJobForm').on('submit', function(e) {
                e.preventDefault();
                const btn = $('#saveBtn');
                btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Saving...');

                $.ajax({
                    url: '?', type: 'POST', data: $(this).serialize(), dataType: 'json',
                    success: function(res) {
                        if(res.success) {
                            showNotification(res.message, 'success');
                            $('#jobModal').modal('hide');
                            table.ajax.reload(null, false); 
                        } else {
                            showNotification(res.error || 'Failed to save changes.', 'danger');
                        }
                    },
                    complete: function() { btn.prop('disabled', false).html('<i class="bi bi-floppy me-1"></i> Save Updates'); }
                });
            });
        });

        window.openEditModal = function(id, jobIdText) {
            $('#modalJobTitle').text(jobIdText);
            $('#updateJobForm')[0].reset();
            $('#modal_job_id').val(id);
            $('.proc-checkbox').prop('checked', false); 
            
            $.ajax({
                url: '?', type: 'GET', data: { action: 'get_job_details', job_id: id }, dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        const d = res.data;
                        $('#modal_billed').val(d.Billed || '');
                        $('#modal_despatched_date').val(d.despatched_date || '');
                        $('#modal_articles_text').val(d.articles_text || '');
                        
                        if (d.checked_processes && d.checked_processes.length > 0) {
                            d.checked_processes.forEach(function(proc) {
                                $(`input[value="${proc}"]`).prop('checked', true);
                            });
                        }
                        
                        $('#jobModal').modal('show');
                    } else { showNotification(res.error, 'danger'); }
                }
            });
        };
        window.openScieloEditModal = function(id, jobIdText) {
            $('#modalScieloJobTitle').text(jobIdText);
            $('#updateScieloForm')[0].reset();
            $('#modal_scielo_job_id').val(id);
            
            $.ajax({
                url: '?', type: 'GET', data: { action: 'get_scielo_details', job_id: id }, dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        $('#modal_scielo_articles_text').val(res.data.articles_text || '');
                        $('#scieloJobModal').modal('show');
                    } else { showNotification(res.error, 'danger'); }
                }
            });
        };

        $('#updateScieloForm').on('submit', function(e) {
            e.preventDefault();
            const btn = $('#saveScieloBtn');
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Saving...');

            $.ajax({
                url: '?', type: 'POST', data: $(this).serialize(), dataType: 'json',
                success: function(res) {
                    if(res.success) {
                        showNotification(res.message, 'success');
                        $('#scieloJobModal').modal('hide');
                        table.ajax.reload(null, false); 
                    } else {
                        showNotification(res.error || 'Failed to save changes.', 'danger');
                    }
                },
                complete: function() { btn.prop('disabled', false).html('<i class="bi bi-floppy me-1"></i> Save Scielo'); }
            });
        });
        
    </script>
    <!-- Add this below your datatables.min.js script tag -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
</body>
</html>