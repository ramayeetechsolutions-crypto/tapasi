<?php
require 'db.php'; 
ini_set('display_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ---------- Include SimpleXLSX ----------
$libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';
if (!file_exists($libPath)) $libPath = 'simplexlsx-master/SimpleXLSX.php';

// ---------- Helpers ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}
function redirect_with($params =[]) {
    $base = 'nda_details.php';
    if (!empty($params)) {
        $base .= '?' . http_build_query($params);
    }
    header("Location: $base");
    exit;
}

// Global Workflows Dictionary for reuse across logic and UI
$workflows =[
    'workflow_all'=>'All', 'ce_ts'=>'CE+TS', 'ce_ts_xml'=>'CE+TS+XML', 'ce'=>'CE', 'ts'=>'TS', 
    'pmc'=>'PMC', 'pubmed'=>'PUBMED', 'clockss'=>'CLOCKSS', 'scielo'=>'SCIELO', 'crossref'=>'Crossref', 
    'doaj'=>'DOAJ', 'bibilat'=>'BIBILAT', 'medes'=>'MEDES', 'pdf_to_xml'=>'PDF to XML', 
    'html'=>'HTML', 'new_html'=>'New HTML', 'direct_html'=>'Direct HTML', 'xml_html'=>'XML+HTML', 'image'=>'Image', 'math'=>'Math'
];

// Config for Services that support With/Without Instruction split
$split_services = [
    'ce_ts'       => 'CE+TS',
    'ce_ts_xml'   => 'CE+TS+XML',
    'ce'          => 'CE',
    'ts'          => 'TS',
    'pdf_to_xml'  => 'PDF to XML',
    'html'        => 'HTML',
    'direct_html' => 'Direct HTML',
    'xml_html'    => 'XML+HTML'
];

$single_services = [
    'workflow_all'=> 'All',
    'pmc'         => 'PMC',
    'pubmed'      => 'PUBMED',
    'clockss'     => 'CLOCKSS',
    'scielo'      => 'SCIELO',
    'crossref'    => 'Crossref',
    'doaj'        => 'DOAJ',
    'bibilat'     => 'BIBILAT',
    'medes'       => 'MEDES',
    'new_html'    => 'New HTML',
    'image'       => 'Image',
    'math'        => 'Math'
];

// Helper to decode a split field stored as JSON or legacy plain string
function parse_split_rate($val) {
    if (empty($val)) return ['with' => '', 'without' => ''];
    if (is_string($val)) {
        $decoded = json_decode($val, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return [
                'with'    => trim((string)($decoded['with'] ?? '')),
                'without' => trim((string)($decoded['without'] ?? ''))
            ];
        }
        return ['with' => trim($val), 'without' => ''];
    }
    return ['with' => '', 'without' => ''];
}

// Helper to encode with/without rate back to JSON
function encode_split_rate($with, $without) {
    $with = trim((string)$with);
    $without = trim((string)$without);
    if ($with === '' && $without === '') return '';
    return json_encode(['with' => $with, 'without' => $without], JSON_UNESCAPED_SLASHES);
}

// ---------------------------------------------------------
// EXCEL IMPORT HANDLER
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        if (!file_exists($libPath)) {
            redirect_with(['msg' => 'SimpleXLSX library not found. Please install it.', 'type' => 'danger']);
        }
        
        require_once $libPath;
        $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);

        if ($xlsx) {
            $rows = $xlsx->rows();
            $count_added = 0;
            $count_skipped = 0;
            $errors =[];

            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header row

                try {
                    // $r[0] is S.No (Ignored)
                    $client_name = trim($r[1] ?? ''); // Shifted to index 1
                    if (empty($client_name)) {
                        $count_skipped++;
                        continue;
                    }

                    // Extract Country and Client Status 
                    $country = $mysqli->real_escape_string(trim($r[2] ?? ''));
                    $client_status = $mysqli->real_escape_string(trim($r[3] ?? 'Active'));
                    if (empty($client_status)) { $client_status = 'Active'; }

                    // Check if client exists in client_master (unique constraint)
                    $check_client = $mysqli->query("SELECT client_id FROM client_master WHERE client_name = '" . $mysqli->real_escape_string($client_name) . "'");
                    
                    if ($check_client && $check_client->num_rows > 0) {
                        // Client exists - use existing client_id
                        $client_id = $check_client->fetch_assoc()['client_id'];
                        
                        // Optionally update country/status if needed
                        $mysqli->query("UPDATE client_master SET country='$country', status='$client_status' WHERE client_id=$client_id");
                    } else {
                        // Create new client (unique entry)
                        $sql_client = "INSERT INTO client_master (client_name, country, status) VALUES ('" . $mysqli->real_escape_string($client_name) . "', '$country', '$client_status')";
                        if ($mysqli->query($sql_client)) {
                            $client_id = $mysqli->insert_id;
                        } else {
                            $count_skipped++;
                            $errors[] = "Row " . ($k + 1) . ": Failed to create client '$client_name' - " . $mysqli->error;
                            continue;
                        }
                    }

                    $nda_status = $mysqli->real_escape_string(trim($r[4] ?? 'No'));
                    $revision_status = $mysqli->real_escape_string(trim($r[5] ?? 'No'));
                    $signed_date_raw = $r[6] ?? '';
                    
                    // Workflow Fields (Shifted index +1 due to S.No)
                    $workflow_all = $mysqli->real_escape_string(trim($r[8] ?? ''));
                    $ce_ts = $mysqli->real_escape_string(trim($r[9] ?? ''));
                    $ce_ts_xml = $mysqli->real_escape_string(trim($r[10] ?? ''));
                    $ce = $mysqli->real_escape_string(trim($r[11] ?? ''));
                    $ts = $mysqli->real_escape_string(trim($r[12] ?? ''));
                    $pmc = $mysqli->real_escape_string(trim($r[13] ?? ''));
                    $pubmed = $mysqli->real_escape_string(trim($r[14] ?? ''));
                    $clockss = $mysqli->real_escape_string(trim($r[15] ?? ''));
                    $scielo = $mysqli->real_escape_string(trim($r[16] ?? ''));
                    $crossref = $mysqli->real_escape_string(trim($r[17] ?? ''));
                    $doaj = $mysqli->real_escape_string(trim($r[18] ?? ''));
                    $bibilat = $mysqli->real_escape_string(trim($r[19] ?? ''));
                    $medes = $mysqli->real_escape_string(trim($r[20] ?? ''));
                    $pdf_to_xml = $mysqli->real_escape_string(trim($r[21] ?? ''));
                    $html = $mysqli->real_escape_string(trim($r[22] ?? ''));
                    $new_html = $mysqli->real_escape_string(trim($r[23] ?? ''));
                    $direct_html = $mysqli->real_escape_string(trim($r[24] ?? ''));
                    $xml_html = $mysqli->real_escape_string(trim($r[25] ?? ''));
                    $image = $mysqli->real_escape_string(trim($r[26] ?? ''));
                    $math = $mysqli->real_escape_string(trim($r[27] ?? ''));
                    
                    // Remarks
                    $remarks = $mysqli->real_escape_string(trim($r[28] ?? ''));

                    // Process Date
                    $signed_date = NULL;
                    if (!empty($signed_date_raw)) {
                        if (is_numeric($signed_date_raw)) {
                            $unix = ($signed_date_raw - 25569) * 86400;
                            $signed_date = gmdate("Y-m-d", (int)$unix);
                        } else {
                            $ts_val = strtotime($signed_date_raw);
                            if ($ts_val !== false) $signed_date = date('Y-m-d', $ts_val);
                        }
                    }
                    $sql_date = $signed_date ? "'" . $mysqli->real_escape_string($signed_date) . "'" : "NULL";

                    // Always insert new NDA record (allow multiple NDAs per client)
                    $sql = "INSERT INTO nda_details (
                            client_id, nda_status, revision_amount_status, signed_date, 
                            workflow_all, ce_ts, ce_ts_xml, ce, ts, pmc, pubmed, clockss, scielo, crossref, doaj, bibilat, medes, pdf_to_xml, html, new_html, direct_html, xml_html, image, math, remarks,
                            created_at, updated_at
                        ) VALUES (
                            $client_id, '$nda_status', '$revision_status', $sql_date, 
                            '$workflow_all', '$ce_ts', '$ce_ts_xml', '$ce', '$ts', '$pmc', '$pubmed', '$clockss', '$scielo', '$crossref', '$doaj', '$bibilat', '$medes', '$pdf_to_xml', '$html', '$new_html', '$direct_html', '$xml_html', '$image', '$math', '$remarks',
                            NOW(), NOW()
                        )";
                    
                    if ($mysqli->query($sql)) { 
                        $count_added++; 
                    } else { 
                        $count_skipped++; 
                        $errors[] = "Row " . ($k + 1) . ": Insert error - " . $mysqli->error; 
                    }
                    
                } catch (Exception $e) {
                    $errors[] = "Row " . ($k + 1) . ": Processing error - " . $e->getMessage();
                    $count_skipped++;
                }
            }
            
            $msg = "Import Complete. <strong>Added:</strong> $count_added | <strong>Skipped (Errors):</strong> $count_skipped";
            if (!empty($errors)) {
                $msg .= "<br><small class='text-danger'>First few logs: " . implode(" | ", array_slice($errors, 0, 3)) . "</small>";
            }
            redirect_with(['msg' => $msg, 'type' => ($count_added > 0) ? 'success' : 'warning']);
            
        } else {
            redirect_with(['msg' => 'Failed to parse Excel file.', 'type' => 'danger']);
        }
    }
}

// ---------- Handle POST actions (Add, Update, Delete) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'],['update_nda', 'add_new', 'delete'])) {
    $action = $_POST['action'];

    // Action: Delete NDA record (not the entire client)
    if ($action === 'delete') {
        $nda_id = (int)($_POST['nda_id'] ?? 0);
        $client_id = (int)($_POST['client_id'] ?? 0);
        
        if ($nda_id > 0) {
            // Delete only the specific NDA record
            $mysqli->query("DELETE FROM nda_details WHERE id = $nda_id AND client_id = $client_id");
            
            // Check if client has any remaining NDA records
            $check_remaining = $mysqli->query("SELECT COUNT(*) as count FROM nda_details WHERE client_id = $client_id");
            $remaining = $check_remaining->fetch_assoc();
            
            if ($remaining['count'] == 0) {
                // Optionally delete client if no NDAs left
                // $mysqli->query("DELETE FROM client_master WHERE client_id = $client_id");
                $msg = 'NDA record deleted successfully. Client has no remaining NDA records.';
            } else {
                $msg = 'NDA record deleted successfully.';
            }
            
            redirect_with(['msg' => $msg, 'type' => 'success']);
        }
    }

    // Action: Add New or Update Existing
    if ($action === 'update_nda' || $action === 'add_new') {
        
        $client_id = (int)($_POST['client_id'] ?? 0);
        
        // Client Info Fields
        $client_name = $mysqli->real_escape_string(trim($_POST['client_name'] ?? ''));
        $country = $mysqli->real_escape_string(trim($_POST['country'] ?? ''));
        $client_status = $mysqli->real_escape_string($_POST['client_status'] ?? 'Active');

        // NDA Fields
        $nda_status = $mysqli->real_escape_string($_POST['nda_status'] ?? 'No');
        $revision_status = $mysqli->real_escape_string($_POST['revision_amount_status'] ?? 'No');
        $signed_date = $_POST['signed_date'] ?? NULL;
        $sql_date = empty($signed_date) ? "NULL" : "'" . $mysqli->real_escape_string(date('Y-m-d', strtotime($signed_date))) . "'";
        $remarks = $mysqli->real_escape_string($_POST['remarks'] ?? '');

        // Workflow fields - Process split with/without or fallback to direct value
        $workflow_all = $mysqli->real_escape_string($_POST['workflow_all'] ?? '');
        
        // Helper to extract split rate from POST
        $get_split = function($field) use ($mysqli) {
            $with_rate = trim($_POST[$field . '_with'] ?? '');
            $without_rate = trim($_POST[$field . '_without'] ?? '');

            if ($with_rate !== '' || $without_rate !== '') {
                return $mysqli->real_escape_string(encode_split_rate($with_rate, $without_rate));
            } elseif (isset($_POST[$field])) {
                // Legacy fallback
                return $mysqli->real_escape_string($_POST[$field]);
            }
            return '';
        };

        $ce_ts       = $get_split('ce_ts');
        $ce_ts_xml   = $get_split('ce_ts_xml');
        $ce          = $get_split('ce');
        $ts          = $get_split('ts');
        $pdf_to_xml  = $get_split('pdf_to_xml');
        $html        = $get_split('html');
        $direct_html = $get_split('direct_html');
        $xml_html    = $get_split('xml_html');

        // Single-rate fields
        $pmc = $mysqli->real_escape_string($_POST['pmc'] ?? '');
        $pubmed = $mysqli->real_escape_string($_POST['pubmed'] ?? '');
        $clockss = $mysqli->real_escape_string($_POST['clockss'] ?? '');
        $scielo = $mysqli->real_escape_string($_POST['scielo'] ?? '');
        $crossref = $mysqli->real_escape_string($_POST['crossref'] ?? '');
        $doaj = $mysqli->real_escape_string($_POST['doaj'] ?? '');
        $bibilat = $mysqli->real_escape_string($_POST['bibilat'] ?? '');
        $medes = $mysqli->real_escape_string($_POST['medes'] ?? '');
        $new_html = $mysqli->real_escape_string($_POST['new_html'] ?? '');
        $image = $mysqli->real_escape_string($_POST['image'] ?? '');
        $math = $mysqli->real_escape_string($_POST['math'] ?? '');

        // 1. ADD NEW NDA - Use existing client or create new one (unique in client_master)
        if ($action === 'add_new') {
            // Check if client already exists in client_master
            $check_client = $mysqli->query("SELECT client_id FROM client_master WHERE client_name = '$client_name'");
            
            if ($check_client && $check_client->num_rows > 0) {
                // Client exists - use existing client_id
                $client_data = $check_client->fetch_assoc();
                $target_client_id = $client_data['client_id'];
                
                // Update country/status if needed
                $mysqli->query("UPDATE client_master SET country='$country', status='$client_status' WHERE client_id=$target_client_id");
            } else {
                // Create new client (unique entry)
                $sql_client = "INSERT INTO client_master (client_name, country, status) VALUES ('$client_name', '$country', '$client_status')";
                if ($mysqli->query($sql_client)) {
                    $target_client_id = $mysqli->insert_id;
                } else {
                    redirect_with(['msg' => 'Failed to create client: ' . $mysqli->error, 'type' => 'danger']);
                }
            }
            
            // Create the NDA record for this client (allow multiple NDAs per client)
            $sql = "INSERT INTO nda_details (
                        client_id, nda_status, revision_amount_status, signed_date, 
                        workflow_all, ce_ts, ce_ts_xml, ce, ts, pmc, pubmed, clockss, scielo, crossref, doaj, bibilat, medes, pdf_to_xml, html, new_html, direct_html, xml_html, image, math, remarks,
                        created_at, updated_at
                    ) VALUES (
                        $target_client_id, '$nda_status', '$revision_status', $sql_date, 
                        '$workflow_all', '$ce_ts', '$ce_ts_xml', '$ce', '$ts', '$pmc', '$pubmed', '$clockss', '$scielo', '$crossref', '$doaj', '$bibilat', '$medes', '$pdf_to_xml', '$html', '$new_html', '$direct_html', '$xml_html', '$image', '$math', '$remarks',
                        NOW(), NOW()
                    )";
            
            if ($mysqli->query($sql)) {
                redirect_with(['msg' => 'New NDA details added successfully for client: ' . $client_name, 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Database Error: ' . $mysqli->error, 'type' => 'danger']);
            }
        } 
        
        // 2. UPDATE EXISTING ENTRY
        else if ($action === 'update_nda') {
            $nda_id = (int)($_POST['nda_id'] ?? 0);
            
            if ($client_id > 0 && $nda_id > 0) {
                // Update client master (keep unique)
                $mysqli->query("UPDATE client_master SET client_name='$client_name', country='$country', status='$client_status' WHERE client_id=$client_id");
                
                // Update specific NDA record by its ID
                $sql = "UPDATE nda_details 
                        SET nda_status = '$nda_status', 
                            revision_amount_status = '$revision_status', 
                            signed_date = $sql_date,
                            workflow_all = '$workflow_all', 
                            ce_ts = '$ce_ts', 
                            ce_ts_xml = '$ce_ts_xml', 
                            ce = '$ce', 
                            ts = '$ts', 
                            pmc = '$pmc', 
                            pubmed = '$pubmed', 
                            clockss = '$clockss', 
                            scielo = '$scielo', 
                            crossref = '$crossref', 
                            doaj = '$doaj', 
                            bibilat = '$bibilat', 
                            medes = '$medes', 
                            pdf_to_xml = '$pdf_to_xml', 
                            html = '$html',
                            new_html = '$new_html', 
                            direct_html = '$direct_html', 
                            xml_html = '$xml_html', 
                            image = '$image', 
                            math = '$math',
                            remarks = '$remarks',
                            updated_at = NOW() 
                        WHERE id = $nda_id AND client_id = $client_id";
                
                if ($mysqli->query($sql)) {
                    redirect_with(['msg' => 'NDA record successfully updated', 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Database Error: ' . $mysqli->error, 'type' => 'danger']);
                }
            } else {
                redirect_with(['msg' => 'Invalid NDA Record Selection.', 'type' => 'danger']);
            }
        }
    }
}

// ---------- Get NDA Summary Statistics ----------
$summary =['total_clients' => 0, 'total_nda_records' => 0, 'nda_signed' => 0, 'nda_not_signed' => 0, 'revision_applicable' => 0, 'signed_this_year' => 0];
$total_result = $mysqli->query("SELECT COUNT(*) as total FROM client_master");
if ($total_result) $summary['total_clients'] = $total_result->fetch_assoc()['total'];

$total_nda_result = $mysqli->query("SELECT COUNT(*) as total FROM nda_details");
if ($total_nda_result) $summary['total_nda_records'] = $total_nda_result->fetch_assoc()['total'];

$stats_sql = "SELECT 
                COUNT(CASE WHEN IFNULL(nda_status, 'No') = 'Yes' THEN 1 END) as signed_count,
                COUNT(CASE WHEN IFNULL(nda_status, 'No') = 'No' THEN 1 END) as not_signed_count,
                COUNT(CASE WHEN IFNULL(revision_amount_status, 'No') = 'Yes' THEN 1 END) as revision_count,
                COUNT(CASE WHEN YEAR(signed_date) = YEAR(CURDATE()) THEN 1 END) as signed_this_year
              FROM nda_details";

$stats_result = $mysqli->query($stats_sql);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
    $summary['nda_signed'] = $stats['signed_count'] ?? 0;
    $summary['nda_not_signed'] = $stats['not_signed_count'] ?? 0;
    $summary['revision_applicable'] = $stats['revision_count'] ?? 0;
    $summary['signed_this_year'] = $stats['signed_this_year'] ?? 0;
}
$summary['signed_percentage'] = $summary['total_nda_records'] > 0 ? round(($summary['nda_signed'] / $summary['total_nda_records']) * 100, 1) : 0;

// ---------- View / Edit / Add Mode Configuration ----------
$mode = $_GET['mode'] ?? 'view';
$editClient = null;
$editNdaId = null;

// Fetch all clients for dropdown with their countries
$all_clients =[];
$res_clients = $mysqli->query("SELECT client_id, client_name, country, status FROM client_master ORDER BY client_name ASC");
if ($res_clients) {
    while ($c_row = $res_clients->fetch_assoc()) {
        $all_clients[] = $c_row;
    }
}

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        // First get the NDA record
        $nda_sql = "SELECT * FROM nda_details WHERE id = ?";
        $stmt = $mysqli->prepare($nda_sql);
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $nda_result = $stmt->get_result();
            $nda_data = $nda_result->fetch_assoc();
            $stmt->close();
            
            if ($nda_data) {
                $editNdaId = $nda_data['id'];
                // Then get client data
                $client_sql = "SELECT * FROM client_master WHERE client_id = ?";
                $stmt = $mysqli->prepare($client_sql);
                if ($stmt) {
                    $stmt->bind_param("i", $nda_data['client_id']);
                    $stmt->execute();
                    $client_result = $stmt->get_result();
                    $client_data = $client_result->fetch_assoc();
                    $stmt->close();
                    
                    if ($client_data) {
                        $editClient = array_merge($client_data, $nda_data);
                    }
                }
            }
        }
    }
    if (!$editClient) $mode = 'view';
}

// ---------- Fetch Unique Countries for Filter ----------
$countries =[];
$res_countries = $mysqli->query("SELECT DISTINCT country FROM client_master WHERE country IS NOT NULL AND country != '' ORDER BY country ASC");
if ($res_countries) {
    while ($row_c = $res_countries->fetch_assoc()) {
        $countries[] = $row_c['country'];
    }
}

// ---------- Filters & Sorting ----------
$q = trim($_GET['q'] ?? '');
$filter_nda = $_GET['nda_status'] ?? '';
$filter_country = trim($_GET['country'] ?? '');
$from_year = $_GET['from_year'] ?? '';
$to_year = $_GET['to_year'] ?? '';
$service_name = $_GET['service_name'] ?? '';
$service_value = trim($_GET['service_value'] ?? '');

$sort = $_GET['sort'] ?? 'client_name';
$dir = strtolower($_GET['dir'] ?? 'asc');

$allowed_sort =['client_name', 'company_name', 'nda_status', 'signed_date'];
if (!in_array($sort, $allowed_sort, true)) $sort = 'client_name';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

$sortMap =[
    'client_name' => 'cm.client_name',
    'company_name' => 'cm.company_name',
    'nda_status' => 'nda_status', 
    'signed_date' => 'nd.signed_date'
];
$orderBy = $sortMap[$sort];

$sql = "SELECT 
            nd.id as nda_id, cm.client_id, cm.client_name, cm.company_name, cm.email, cm.phone, cm.country, cm.status as client_status,
            IFNULL(nd.nda_status, 'No') as nda_status, 
            IFNULL(nd.revision_amount_status, 'No') as revision_amount_status, 
            nd.signed_date, nd.remarks,
            nd.workflow_all, nd.ce_ts, nd.ce_ts_xml, nd.ce, nd.ts, nd.pmc, nd.pubmed, nd.clockss, nd.scielo, nd.crossref, nd.doaj, nd.bibilat, nd.medes, nd.pdf_to_xml, nd.html, nd.new_html, nd.direct_html, nd.xml_html, nd.image, nd.math
        FROM client_master cm
        LEFT JOIN nda_details nd ON cm.client_id = nd.client_id";

$cond = []; $params =[]; $types = '';

// 1. Keyword Filter
if ($q !== '') {
    $cond[] = "(cm.client_name LIKE ? OR cm.company_name LIKE ? OR cm.email LIKE ?)";
    $like = "%$q%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= "sss";
}

// 2. NDA Status Filter
if ($filter_nda === 'Yes') {
    $cond[] = "IFNULL(nd.nda_status, 'No') = 'Yes'";
} elseif ($filter_nda === 'No') {
    $cond[] = "IFNULL(nd.nda_status, 'No') = 'No'";
}

// 3. From Year Filter
if ($from_year !== '' && is_numeric($from_year)) {
    $cond[] = "YEAR(nd.signed_date) >= ?";
    $params[] = (int)$from_year;
    $types .= "i";
}

// 4. To Year Filter
if ($to_year !== '' && is_numeric($to_year)) {
    $cond[] = "YEAR(nd.signed_date) <= ?";
    $params[] = (int)$to_year;
    $types .= "i";
}

// 5. Service / Workflow Based Filter
if (!empty($service_name) && array_key_exists($service_name, $workflows)) {
    if ($service_value !== '') {
        $cond[] = "nd.$service_name LIKE ?";
        $params[] = "%$service_value%";
        $types .= "s";
    } else {
        // If they select a service but leave value blank, we filter records where the service is not empty
        $cond[] = "(nd.$service_name IS NOT NULL AND nd.$service_name != '')";
    }
}

// 6. Country Filter
if ($filter_country !== '') {
    $cond[] = "cm.country = ?";
    $params[] = $filter_country;
    $types .= "s";
}

if ($cond) $sql .= " WHERE " . implode(" AND ", $cond);
$sql .= " ORDER BY $orderBy $dir";

$clients = false;
$error = '';

try {
    if (!empty($types)) {
        $stmt = $mysqli->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $clients = $stmt->get_result();
        }
    } else {
        $clients = $mysqli->query($sql);
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

require 'header.php'; 
?>

<style>
    /* Uniform black header for both header rows (main + With / W/o) */
    .table thead.table-dark th {
        background-color: #6c757d !important;
        color: #fff !important;
        border-color: #868e96 !important;
        vertical-align: middle;
    }
</style>

<!-- UI STARTS HERE -->
<div class="container-fluid py-4" style="margin-top: 80px;">

    <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
        <?= h($_GET['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- CONSOLIDATED NDA SUMMARY SECTION -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm" style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);">
                <div class="card-body py-3">
                    <div class="d-flex align-items-center mb-3">
                        <div class="rounded-circle bg-primary p-2 me-2" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-chart-pie text-white small"></i>
                        </div>
                        <span class="fw-bold text-dark">NDA Performance Dashboard</span>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="p-2 rounded bg-white bg-opacity-75">
                                <span class="small text-secondary mb-1 d-block"><i class="fas fa-users fa-fw me-1 text-primary"></i>Total Clients</span>
                                <span class="h4 mb-0 fw-bold text-primary"><?= $summary['total_clients'] ?></span>
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="p-2 rounded bg-white bg-opacity-75">
                                <span class="small text-secondary mb-1 d-block"><i class="fas fa-file-contract fa-fw me-1 text-info"></i>Total NDA Records</span>
                                <span class="h4 mb-0 fw-bold text-info"><?= $summary['total_nda_records'] ?></span>
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="p-2 rounded bg-white bg-opacity-75">
                                <span class="small text-secondary mb-1 d-block"><i class="fas fa-check-circle fa-fw me-1 text-success"></i>NDA Signed</span>
                                <div class="d-flex align-items-center mb-1">
                                    <span class="h4 mb-0 fw-bold text-success me-2"><?= $summary['nda_signed'] ?></span>
                                </div>
                                <div class="progress" style="height: 6px; background-color: #e9ecef;">
                                    <div class="progress-bar bg-success" style="width: <?= $summary['signed_percentage'] ?>%;"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="p-2 rounded bg-white bg-opacity-75">
                                <span class="small text-secondary mb-1 d-block"><i class="fas fa-times-circle fa-fw me-1 text-danger"></i>Unsigned</span>
                                <span class="h4 mb-0 fw-bold text-danger"><?= $summary['nda_not_signed'] ?></span>
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="p-2 rounded bg-white bg-opacity-75">
                                <span class="small text-secondary mb-1 d-block"><i class="fas fa-file-signature fa-fw me-1 text-warning"></i>Revision Accepted</span>
                                <span class="h4 mb-0 fw-bold text-warning"><?= $summary['revision_applicable'] ?></span>
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="p-2 rounded bg-white bg-opacity-75">
                                <span class="small text-secondary mb-1 d-block"><i class="fas fa-calendar-check fa-fw me-1 text-info"></i>Current Year Signed</span>
                                <span class="h4 mb-0 fw-bold text-info"><?= $summary['signed_this_year'] ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ADD / EDIT FORM -->
    <?php if ($mode === 'edit' || $mode === 'add'): ?>
    <?php 
        $isAdd = ($mode === 'add');
        $formAction = $isAdd ? 'add_new' : 'update_nda';
        $title = $isAdd ? '<i class="fas fa-plus-circle me-2"></i>Create New NDA Record' : '<i class="fas fa-edit me-2"></i>Update NDA Record for: ' . h($editClient['client_name'] ?? '');
        $btnText = $isAdd ? 'Save New NDA Record' : 'Update NDA Details';
    ?>
    <div class="card mb-4 mx-auto shadow-sm" style="max-width: 950px;">
        <a href="nda_details.php" class="btn btn-secondary btn-sm shadow-sm mb-2">
            <i class="fas fa-arrow-left me-1"></i> Back to List
        </a>
        <div class="card-header bg-secondary text-white">
            <h6 class="mb-0"><?= $title ?></h6> 
        </div>
        <div class="card-body p-4">
            <form method="post">
                <input type="hidden" name="action" value="<?= $formAction ?>">
                <?php if (!$isAdd): ?>
                    <input type="hidden" name="client_id" value="<?= $editClient['client_id'] ?>">
                    <input type="hidden" name="nda_id" value="<?= $editNdaId ?>">
                <?php endif; ?>

                <!-- Client Details (Unique in client_master) -->
                <h6 class="border-bottom pb-2 text-primary fw-bold"><i class="fas fa-user-tie me-2"></i>Client Profile (Unique)</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label small">Client Name <span class="text-danger">*</span></label>
                        <?php if ($isAdd): ?>
                            <select name="client_name" id="client_select" class="form-select form-select-sm" required onchange="autoFillCountry()">
                                <option value="">-- Select Existing Client or Type New --</option>
                                <?php foreach($all_clients as $cl): ?>
                                    <option value="<?= h($cl['client_name']) ?>" data-country="<?= h($cl['country'] ?? '') ?>" data-status="<?= h($cl['status'] ?? 'Active') ?>">
                                        <?= h($cl['client_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Select existing or type new client name (will be created if new)</small>
                        <?php else: ?>
                            <input type="text" name="client_name" class="form-control form-control-sm" value="<?= h($editClient['client_name'] ?? '') ?>" required readonly>
                            <small class="text-muted">Client name cannot be changed in edit mode</small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Country</label>
                        <input type="text" name="country" id="country_input" class="form-control form-control-sm" value="<?= h($editClient['country'] ?? '') ?>" placeholder="Enter country name">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Client Status</label>
                        <select name="client_status" id="status_select" class="form-select form-select-sm">
                            <option value="Active" <?= (strtolower($editClient['client_status'] ?? '') == 'active' || $isAdd) ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= (strtolower($editClient['client_status'] ?? '') == 'inactive') ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                </div>

                <!-- NDA Information (Multiple records allowed) -->
                <h6 class="border-bottom pb-2 text-success fw-bold"><i class="fas fa-file-contract me-2"></i>NDA Details (Multiple Records Allowed)</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label small">NDA Status (Signed?)</label>
                        <select name="nda_status" class="form-select form-select-sm">
                            <option value="No" <?= (strtolower($editClient['nda_status'] ?? '') == 'no' || $isAdd) ? 'selected' : '' ?>>No</option>
                            <option value="Yes" <?= (strtolower($editClient['nda_status'] ?? '') == 'yes') ? 'selected' : '' ?>>Yes</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Revision Amount Status</label>
                        <select name="revision_amount_status" class="form-select form-select-sm">
                            <option value="No" <?= (strtolower($editClient['revision_amount_status'] ?? '') == 'no' || $isAdd) ? 'selected' : '' ?>>No</option>
                            <option value="Yes" <?= (strtolower($editClient['revision_amount_status'] ?? '') == 'yes') ? 'selected' : '' ?>>Yes</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Date of Signing</label>
                        <input type="date" name="signed_date" class="form-control form-control-sm" value="<?= !empty($editClient['signed_date']) && $editClient['signed_date'] != '0000-00-00' ? date('Y-m-d', strtotime($editClient['signed_date'])) : '' ?>">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label small">Remarks</label>
                        <textarea name="remarks" class="form-control form-control-sm" rows="2" placeholder="Any additional notes or terms..."><?= h($editClient['remarks'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Workflow Fields -->
                <h6 class="border-bottom pb-2 text-primary fw-bold"><i class="fas fa-tasks me-2"></i>Workflow / Services Configuration</h6>

                <!-- 1. Split Service Cards (Always open / active inputs) -->
                <div class="row g-3 mb-4">
                    <?php foreach($split_services as $sf => $slabel): 
                        $parsed = parse_split_rate($editClient[$sf] ?? '');
                    ?>
                    <div class="col-lg-4 col-md-6">
                        <div class="card h-100 shadow-none border service-card" style="border-color: #dee2e6;">
                            <div class="card-header bg-white py-2 border-bottom">
                                <span class="fw-bold small text-dark"><?= $slabel ?></span>
                            </div>
                            <div class="card-body p-2 bg-light">
                                <!-- With Instruction Option -->
                                <div class="d-flex align-items-center gap-2 p-1 px-2 rounded mb-2 border bg-white" style="border-color: #dee2e6 !important;">
                                    <span class="small fw-semibold text-primary" style="font-size: 0.75rem; min-width: 120px;">
                                        With Instruction
                                    </span>
                                    <input type="text" name="<?= $sf ?>_with" class="form-control form-control-sm text-end" placeholder="Rate" value="<?= h($parsed['with']) ?>" style="font-size: 0.75rem;">
                                </div>
                                <!-- Without Instruction Option -->
                                <div class="d-flex align-items-center gap-2 p-1 px-2 rounded border bg-white" style="border-color: #dee2e6 !important;">
                                    <span class="small fw-semibold text-secondary" style="font-size: 0.75rem; min-width: 120px;">
                                        Without Instruction
                                    </span>
                                    <input type="text" name="<?= $sf ?>_without" class="form-control form-control-sm text-end" placeholder="Rate" value="<?= h($parsed['without']) ?>" style="font-size: 0.75rem;">
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- 2. Single-rate Services Flat Grid -->
                <div class="card border-0 bg-light p-3 rounded">
                    <h6 class="fw-bold small text-muted mb-2"><i class="fas fa-layer-group me-1"></i>Single-Rate Services (no With/Without split)</h6>
                    <div class="row g-2">
                        <?php foreach($single_services as $sf => $slabel): ?>
                        <div class="col-lg-2 col-md-3 col-sm-4 col-6">
                            <label class="form-label small text-secondary mb-1" style="font-size: 0.72rem;"><?= $slabel ?></label>
                            <input type="text" name="<?= $sf ?>" class="form-control form-control-sm" placeholder="Rate" value="<?= h($editClient[$sf] ?? '') ?>">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                    <a href="nda_details.php" class="btn btn-light border">Cancel</a>
                    <button type="submit" class="btn btn-<?= $isAdd ? 'primary' : 'success' ?> px-4"><i class="fas fa-save me-2"></i><?= $btnText ?></button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- LIST VIEW -->
    <?php if ($mode === 'view'): ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold text-dark"><i class="fas fa-file-contract text-primary me-2"></i>NDA Details</h4>
        <div class="d-flex gap-2">
            <!-- Add New Action -->
            <button type="button" class="btn btn-warning text-dark fw-bold btn-sm shadow-sm" data-bs-toggle="modal" data-bs-target="#importModal">
                <i class="fas fa-file-excel me-2"></i> Import Excel
            </button>
            <a href="nda_details.php?mode=add" class="btn btn-primary text-white fw-bold btn-sm shadow-sm">
                <i class="fas fa-plus me-2"></i> Add New NDA Record
            </a>
        </div>
    </div>

   <!-- Filter Bar Component -->
    <div class="card shadow-sm border-0 mb-4 bg-light">
        <div class="card-body p-3">
            <form method="get" class="row gy-3 gx-2 align-items-end">
                
                <!-- 1. Search -->
                <div class="col-xl-2 col-lg-3 col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white text-muted border-end-0"><i class="fas fa-search"></i></span>
                        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search by Client" class="form-control border-start-0 ps-0">
                    </div>
                </div>

                <!-- 2. Country Filter -->
                <div class="col-xl-2 col-lg-3 col-md-4">
                    <select name="country" class="form-select form-select-sm">
                        <option value="">Filter by Country</option>
                        <?php foreach($countries as $c): ?>
                            <option value="<?= h($c) ?>" <?= $filter_country === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- 3. NDA Status -->
                <div class="col-xl-2 col-lg-3 col-md-4">
                    <select name="nda_status" class="form-select form-select-sm">
                        <option value="">Filter by NDA Status</option>
                        <option value="Yes" <?= $filter_nda === 'Yes' ? 'selected' : '' ?>>Signed Only</option>
                        <option value="No" <?= $filter_nda === 'No' ? 'selected' : '' ?>>Unsigned Only</option>
                    </select>
                </div>
                
                <!-- 4. Year Range -->
                <div class="col-xl-2 col-lg-3 col-md-4">
                    <div class="input-group input-group-sm">
                        <input type="number" name="from_year" value="<?= h($from_year) ?>" placeholder="From Year" class="form-control" min="1990" max="2100">
                        <span class="input-group-text border-start-0 border-end-0 bg-white">to</span>
                        <input type="number" name="to_year" value="<?= h($to_year) ?>" placeholder="To Year" class="form-control" min="1990" max="2100">
                    </div>
                </div>
                
                <!-- 5. Service -->
                <div class="col-xl-2 col-lg-3 col-md-4">
                    <select name="service_name" class="form-select form-select-sm">
                        <option value="">-- Filter by Service --</option>
                        <?php foreach($workflows as $field => $label): ?>
                            <option value="<?= $field ?>" <?= $service_name === $field ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- 6. Action Buttons -->
                <div class="col-xl-2 col-lg-3 col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm shadow-sm flex-grow-1" title="Apply Filters">
                        <i class="fas fa-filter"></i> Apply
                    </button>
                    <a href="nda_details.php" class="btn btn-outline-secondary btn-sm shadow-sm flex-grow-1" title="Reset Filters">
                        <i class="fas fa-times"></i> Reset
                    </a>
                </div>

            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle mb-0" style="font-size: 0.75rem;">
                    <thead class="table-dark">
                        <tr>
                            <th rowspan="2" class="py-2 text-center border-end align-middle">S.No</th>
                            <th rowspan="2" class="py-2 border-end align-middle">Client Name</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle">Country</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle" title="Client Status">Status</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle" title="NDA Status">NDA</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle" title="Revision Amount Applicable">Rev.Amt</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle">Signed Date</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle">Year</th>
                            
                            <!-- Workflow Headers (Split & Single) -->
                            <th rowspan="2" class="py-2 text-center border-end align-middle" title="All">All</th>
                            <th colspan="2" class="py-1 text-center border-end" title="CE+TS">CE+TS</th>
                            <th colspan="2" class="py-1 text-center border-end" title="CE+TS+XML">CE+TS+XML</th>
                            <th colspan="2" class="py-1 text-center border-end" title="CE">CE</th>
                            <th colspan="2" class="py-1 text-center border-end" title="TS">TS</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle" title="PMC">PMC</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle" title="PUBMED">PUBMED</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle" title="CLOCKSS">CLOCKSS</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle" title="SCIELO">SCIELO</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle" title="Crossref">Crossref</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle" title="DOAJ">DOAJ</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle" title="BIBILAT">BIBILAT</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle" title="MEDES">MEDES</th>
                            <th colspan="2" class="py-1 text-center border-end" title="PDF to XML">PDF to XML</th>
                            <th colspan="2" class="py-1 text-center border-end" title="HTML">HTML</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle" title="New HTML">New HTML</th>
                            <th colspan="2" class="py-1 text-center border-end" title="Direct HTML">Direct HTML</th>
                            <th colspan="2" class="py-1 text-center border-end" title="XML+HTML">XML+HTML</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle" title="Image">Image</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle" title="Math">Math</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle" title="Remarks">Remarks</th>

                            <th rowspan="2" class="py-2 text-center align-middle">Actions</th>
                        </tr>
                        <!-- Row 2: Sub-headers for Split Columns using exact table-dark header style -->
                        <tr style="font-size: 0.68rem;">
                            <!-- CE+TS -->
                            <th class="py-1 text-center border-end text-light fw-normal">With</th>
                            <th class="py-1 text-center border-end text-light fw-normal">W/o</th>
                            <!-- CE+TS+XML -->
                            <th class="py-1 text-center border-end text-light fw-normal">With</th>
                            <th class="py-1 text-center border-end text-light fw-normal">W/o</th>
                            <!-- CE -->
                            <th class="py-1 text-center border-end text-light fw-normal">With</th>
                            <th class="py-1 text-center border-end text-light fw-normal">W/o</th>
                            <!-- TS -->
                            <th class="py-1 text-center border-end text-light fw-normal">With</th>
                            <th class="py-1 text-center border-end text-light fw-normal">W/o</th>
                            <!-- PDF to XML -->
                            <th class="py-1 text-center border-end text-light fw-normal">With</th>
                            <th class="py-1 text-center border-end text-light fw-normal">W/o</th>
                            <!-- HTML -->
                            <th class="py-1 text-center border-end text-light fw-normal">With</th>
                            <th class="py-1 text-center border-end text-light fw-normal">W/o</th>
                            <!-- Direct HTML -->
                            <th class="py-1 text-center border-end text-light fw-normal">With</th>
                            <th class="py-1 text-center border-end text-light fw-normal">W/o</th>
                            <!-- XML+HTML -->
                            <th class="py-1 text-center border-end text-light fw-normal">With</th>
                            <th class="py-1 text-center border-end text-light fw-normal">W/o</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white">
                        <?php if ($clients && $clients->num_rows > 0): 
                            $i = 0; 
                            while ($row = $clients->fetch_assoc()): 
                                $i++; 
                                // Parse split values
                                $p_cets     = parse_split_rate($row['ce_ts'] ?? '');
                                $p_cetsxml  = parse_split_rate($row['ce_ts_xml'] ?? '');
                                $p_ce       = parse_split_rate($row['ce'] ?? '');
                                $p_ts       = parse_split_rate($row['ts'] ?? '');
                                $p_pdfxml   = parse_split_rate($row['pdf_to_xml'] ?? '');
                                $p_html     = parse_split_rate($row['html'] ?? '');
                                $p_dhtml    = parse_split_rate($row['direct_html'] ?? '');
                                $p_xmlhtml  = parse_split_rate($row['xml_html'] ?? '');
                        ?>
                        <tr>
                            <td class="text-center text-muted border-end"><?= $i ?></td>
                            <td class="border-end">
                                <span class="fw-bold text-primary"><?= h($row['client_name']) ?></span>
                            </td>
                            <td class="text-center border-end"><?= !empty($row['country']) ? h($row['country']) : '-' ?></td>
                            <td class="text-center border-end">
                                <span class="badge bg-<?= strtolower($row['client_status'] ?? '') == 'active' ? 'success' : 'secondary' ?>"><?= h($row['client_status'] ?? 'Unknown') ?></span>
                            </td>
                            <td class="text-center border-end">
                                <span class="badge bg-<?= strtolower($row['nda_status']) === 'yes' ? 'success' : 'light text-dark border' ?>"><?= strtolower($row['nda_status']) === 'yes' ? 'Signed' : 'No' ?></span>
                            </td>
                            <td class="text-center border-end">
                                <?= strtolower($row['revision_amount_status']) === 'yes' ? '<span class="text-warning fw-bold"><i class="fas fa-check"></i></span>' : '<span class="text-muted">-</span>' ?>
                            </td>
                            <td class="text-center border-end"><?= h(formatDate($row['signed_date'])) ?></td>
                            <td class="text-center fw-bold text-info border-end"><?= (!empty($row['signed_date']) && $row['signed_date'] != '0000-00-00') ? date('Y', strtotime($row['signed_date'])) : '-' ?></td>
                            
                            <!-- Workflows -->
                            <td class="text-center border-end"><?= h($row['workflow_all'] ?? '') ?></td>

                            <!-- CE+TS (Split) -->
                            <td class="text-center border-end"><?= h($p_cets['with']) ?></td>
                            <td class="text-center border-end"><?= h($p_cets['without']) ?></td>

                            <!-- CE+TS+XML (Split) -->
                            <td class="text-center border-end"><?= h($p_cetsxml['with']) ?></td>
                            <td class="text-center border-end"><?= h($p_cetsxml['without']) ?></td>

                            <!-- CE (Split) -->
                            <td class="text-center border-end"><?= h($p_ce['with']) ?></td>
                            <td class="text-center border-end"><?= h($p_ce['without']) ?></td>

                            <!-- TS (Split) -->
                            <td class="text-center border-end"><?= h($p_ts['with']) ?></td>
                            <td class="text-center border-end"><?= h($p_ts['without']) ?></td>

                            <!-- Single Services -->
                            <td class="text-center border-end"><?= h($row['pmc'] ?? '') ?></td>
                            <td class="text-center border-end"><?= h($row['pubmed'] ?? '') ?></td>
                            <td class="text-center border-end"><?= h($row['clockss'] ?? '') ?></td>
                            <td class="text-center border-end"><?= h($row['scielo'] ?? '') ?></td>
                            <td class="text-center border-end"><?= h($row['crossref'] ?? '') ?></td>
                            <td class="text-center border-end"><?= h($row['doaj'] ?? '') ?></td>
                            <td class="text-center border-end"><?= h($row['bibilat'] ?? '') ?></td>
                            <td class="text-center border-end"><?= h($row['medes'] ?? '') ?></td>

                            <!-- PDF to XML (Split) -->
                            <td class="text-center border-end"><?= h($p_pdfxml['with']) ?></td>
                            <td class="text-center border-end"><?= h($p_pdfxml['without']) ?></td>

                            <!-- HTML (Split) -->
                            <td class="text-center border-end"><?= h($p_html['with']) ?></td>
                            <td class="text-center border-end"><?= h($p_html['without']) ?></td>

                            <!-- New HTML (Single) -->
                            <td class="text-center border-end"><?= h($row['new_html'] ?? '') ?></td>

                            <!-- Direct HTML (Split) -->
                            <td class="text-center border-end"><?= h($p_dhtml['with']) ?></td>
                            <td class="text-center border-end"><?= h($p_dhtml['without']) ?></td>

                            <!-- XML+HTML (Split) -->
                            <td class="text-center border-end"><?= h($p_xmlhtml['with']) ?></td>
                            <td class="text-center border-end"><?= h($p_xmlhtml['without']) ?></td>

                            <!-- Image, Math -->
                            <td class="text-center border-end"><?= h($row['image'] ?? '') ?></td>
                            <td class="text-center border-end"><?= h($row['math'] ?? '') ?></td>
                            
                            <td class="text-center border-end">
                                <span class="d-inline-block text-truncate" style="max-width: 100px;" title="<?= h($row['remarks'] ?? '') ?>">
                                    <?= h($row['remarks'] ?? '') ?>
                                </span>
                            </td>

                            <!-- Action Tools: Edit and Delete -->
                            <td class="text-center text-nowrap">
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="nda_details.php?mode=edit&id=<?= (int)$row['nda_id'] ?>" 
                                       class="btn btn-warning" title="Edit NDA Record">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="post" style="display:inline;" 
                                          onsubmit="return confirm('Are you sure you want to delete this NDA record for <?= h($row['client_name']) ?>?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="client_id" value="<?= (int)$row['client_id'] ?>">
                                        <input type="hidden" name="nda_id" value="<?= (int)$row['nda_id'] ?>">
                                        <button type="submit" class="btn btn-danger" title="Delete NDA Record">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>                        
                        </tr>
                        <?php endwhile; else: ?>
                        <tr>
                            <td colspan="31" class="text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-2x mb-2 opacity-50"></i><br>
                                No NDA records found matching the requested filters.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- IMPORT MODAL -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-warning text-dark border-0">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-file-excel me-2"></i>Import NDA & Workflows
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <form action="" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_excel">
                    
                    <div class="modal-body p-4">
                        <div class="row bg-light rounded p-3 mb-4 shadow-sm border">
                            <div class="col-md-8">
                                <label class="form-label fw-bold text-dark">Select Excel File (.xlsx, .xls)</label>
                                <input type="file" name="excel_file" class="form-control" accept=".xlsx, .xls" required>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="button" class="btn btn-success w-100 shadow-sm" onclick="downloadSample()">
                                    <i class="fas fa-download me-2"></i> Download Sample
                                </button>
                            </div>
                        </div>
                        
                        <div class="alert alert-secondary border-0 shadow-sm">
                            <h6 class="fw-bold text-dark mb-2"><i class="fas fa-list-ol me-2 text-primary"></i>Excel Format (29 Columns Required):</h6>
                            <p class="mb-2 text-muted small">Columns 1(S.No) and 8(Signed Year) are ignored during import. Client names are unique in client_master, but multiple NDA records per client are allowed.</p>
                            <div class="table-responsive bg-white rounded border">
                                <table class="table table-sm table-bordered mb-0 align-middle text-center small" style="font-size: 0.70rem;">
                                    <thead class="table-light">
                                        <tr>
                                            <th>A</th><th>B</th><th>C</th><th>D</th><th>E</th><th>F</th><th>G</th><th>H</th><th>I</th><th>J</th><th>K</th><th>L</th><th>M</th><th>N</th><th>O</th><th>P</th><th>Q</th><th>R</th><th>S</th><th>T</th><th>U</th><th>V</th><th>W</th><th>X</th><th>Y</th><th>Z</th><th>AA</th><th>AB</th><th>AC</th>
                                        </tr>
                                    </thead>
                                    <tbody class="text-muted">
                                        <tr>
                                            <td>S.No</td><td class="text-primary fw-bold">Client Name*</td><td>Country</td><td>C.Status</td><td>NDA Status</td><td>Rev amt</td><td>Signed Date</td><td>Signed Year</td><td>All</td><td>CE+TS</td><td>CE+TS+XML</td><td>CE</td><td>TS</td><td>PMC</td><td>PUBMED</td><td>CLOCKSS</td><td>SCIELO</td><td>Crossref</td><td>DOAJ</td><td>BIBILAT</td><td>MEDES</td><td>PDF to XML</td><td>HTML</td><td>New HTML</td><td>Direct HTML</td><td>XML+HTML</td><td>Image</td><td>Math</td><td>Remarks</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer bg-light border-0">
                        <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-dark px-4 shadow-sm"><i class="fas fa-cloud-upload-alt me-2"></i> Import Data</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Autofill Country & Status when a Client is Selected in Add Mode
    function autoFillCountry() {
        var select = document.getElementById('client_select');
        var selectedOption = select.options[select.selectedIndex];
        
        if (selectedOption && selectedOption.value !== "") {
            var country = selectedOption.getAttribute('data-country');
            var status = selectedOption.getAttribute('data-status');
            
            // Fill Country
            var countryInput = document.getElementById('country_input');
            if (countryInput) countryInput.value = country || '';
            
            // Match Status Dropdown
            var statusSelect = document.getElementById('status_select');
            if (status && statusSelect) {
                for (var i = 0; i < statusSelect.options.length; i++) {
                    if (statusSelect.options[i].value.toLowerCase() === status.toLowerCase()) {
                        statusSelect.selectedIndex = i;
                        break;
                    }
                }
            }
        } else {
            // Clear if -- Select -- is clicked
            var countryInput = document.getElementById('country_input');
            if (countryInput) countryInput.value = '';
            var statusSelect = document.getElementById('status_select');
            if (statusSelect) statusSelect.selectedIndex = 0;
        }
    }

    function downloadSample() {
        const sampleContent = "S.No,Client Name,Country,Client Status,NDA Status,Rev amt,Signed Date,Signed Year,All,CE+TS,CE+TS+XML,CE,TS,PMC,PUBMED,CLOCKSS,SCIELO,Crossref,DOAJ,BIBILAT,MEDES,PDF to XML,HTML,New HTML,Direct HTML,XML+HTML,Image,Math,Remarks\n" +
                             "1,ABC Corp,India,Active,Yes,Yes,10/24/2025,2025,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,Standard terms applied\n" +
                             "2,ABC Corp,India,Active,No,No,11/15/2025,2025,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,Second NDA record for same client\n" +
                             "3,XYZ Ltd,USA,Active,Yes,Yes,10/24/2025,2025,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,Special terms";
        const blob = new Blob([sampleContent], { type: 'text/csv;charset=utf-8;' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'sample_nda_workflows.csv'; 
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    }

    document.addEventListener('DOMContentLoaded', function() {
        var alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            setTimeout(function() {
                var bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        });
    });
</script>
</body>
</html>