<?php
require 'db.php'; 
ini_set('display_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ---------- Auto-migration for Database Columns ----------
// Ensure client_master columns that must be nullable are nullable (prevents "doesn't have a default value" on import)
$cm_fix_cols = ['template' => 'text', 'tds_percentage' => 'double(10,2)', 'igst' => 'double(10,2)'];
foreach ($cm_fix_cols as $col => $type) {
    $chk = $mysqli->query("SHOW COLUMNS FROM client_master LIKE '$col'");
    if ($chk && $chk->num_rows > 0) {
        $col_info = $chk->fetch_assoc();
        if ($col_info['Null'] === 'NO') {
            $mysqli->query("ALTER TABLE client_master MODIFY COLUMN `$col` $type NULL");
        }
    }
}

$check_prev_col = $mysqli->query("SHOW COLUMNS FROM nda_details LIKE 'previous_status'");
if ($check_prev_col && $check_prev_col->num_rows == 0) {
    $mysqli->query("ALTER TABLE nda_details ADD COLUMN previous_status VARCHAR(255) NULL AFTER revision_amount_status");
}
$check_year_col = $mysqli->query("SHOW COLUMNS FROM nda_details LIKE 'signed_year'");
if ($check_year_col && $check_year_col->num_rows == 0) {
    $mysqli->query("ALTER TABLE nda_details ADD COLUMN signed_year VARCHAR(10) NULL AFTER signed_date");
}
$check_prev_cm = $mysqli->query("SHOW COLUMNS FROM client_master LIKE 'previous_status'");
if ($check_prev_cm && $check_prev_cm->num_rows == 0) {
    $mysqli->query("ALTER TABLE client_master ADD COLUMN previous_status VARCHAR(255) NULL AFTER status");
}
$check_pmc_len = $mysqli->query("SHOW COLUMNS FROM nda_details LIKE 'pmc'");
if ($check_pmc_len && $pmc_row = $check_pmc_len->fetch_assoc()) {
    if (stripos($pmc_row['Type'], 'varchar(100)') !== false) {
        $mysqli->query("ALTER TABLE nda_details MODIFY COLUMN pmc VARCHAR(255) NULL");
    }
}

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
    'pmc'         => 'PMC',
    'pdf_to_xml'  => 'PDF to XML',
    'html'        => 'HTML',
    'direct_html' => 'Direct HTML',
    'xml_html'    => 'XML+HTML'
];

$single_services = [
    'workflow_all'=> 'All',
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
        $uploaded_name = strtolower($_FILES['excel_file']['name']);
        $is_csv = (pathinfo($uploaded_name, PATHINFO_EXTENSION) === 'csv');

        // ---- Parse CSV ----
        if ($is_csv) {
            $tmp = $_FILES['excel_file']['tmp_name'];
            $handle = fopen($tmp, 'r');
            $rows = [];
            if ($handle) {
                while (($line = fgetcsv($handle, 0, ',')) !== false) {
                    $rows[] = $line;
                }
                fclose($handle);
            }
            $parse_ok = !empty($rows);
        } else {
            // ---- Parse XLSX / XLS ----
            if (!file_exists($libPath)) {
                redirect_with(['msg' => 'SimpleXLSX library not found. Please install it.', 'type' => 'danger']);
            }
            require_once $libPath;
            $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
            $rows = $xlsx ? $xlsx->rows() : [];
            $parse_ok = ($xlsx !== false);
        }

        if ($parse_ok) {
            $count_added = 0;
            $count_skipped = 0;
            $errors =[];

            // Build normalized header index map if headers exist
            $header_map = [];
            if (!empty($rows[0]) && is_array($rows[0])) {
                foreach ($rows[0] as $col_idx => $header_val) {
                    $norm = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', (string)$header_val)));
                    if ($norm !== '') {
                        $header_map[$norm] = $col_idx;
                    }
                }
            }
            $has_header_names = isset($header_map['clientname']) || isset($header_map['name']);

            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header row

                try {
                    if ($has_header_names) {
                        $get_cell = function($aliases, $default = '') use ($r, $header_map) {
                            if (!is_array($aliases)) $aliases = [$aliases];
                            foreach ($aliases as $alias) {
                                $k_norm = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', (string)$alias)));
                                if (isset($header_map[$k_norm])) {
                                    $idx = $header_map[$k_norm];
                                    if (isset($r[$idx])) {
                                        return trim((string)$r[$idx]);
                                    }
                                }
                            }
                            return $default;
                        };

                        $get_split = function($field, $label) use ($get_cell) {
                            $w = $get_cell([$field . 'with', $label . 'with', $field . '_with', $label . ' (with)']);
                            $wo = $get_cell([$field . 'wo', $field . 'without', $label . 'wo', $label . 'without', $field . '_without', $label . ' (w/o)', $label . ' (without)']);
                            if ($w !== '' || $wo !== '') {
                                return encode_split_rate($w, $wo);
                            }
                            return $get_cell([$field, $label]);
                        };

                        $client_name     = $get_cell(['clientname', 'name']);
                        if (empty($client_name)) {
                            $count_skipped++;
                            continue;
                        }
                        $country         = $mysqli->real_escape_string($get_cell(['country']));
                        $previous_status = $mysqli->real_escape_string($get_cell(['previousstatus', 'prevstatus']));
                        $client_status   = $mysqli->real_escape_string($get_cell(['currentstatus', 'status'], 'Active'));
                        $nda_status      = $mysqli->real_escape_string($get_cell(['ndastatus', 'nda'], 'No'));
                        $revision_status = $mysqli->real_escape_string($get_cell(['revamt', 'revisionamountstatus', 'revisionstatus'], 'No'));
                        $signed_date_raw = $get_cell(['signeddate', 'dateofsigning', 'date']);
                        $signed_year_raw = $get_cell(['year', 'signedyear', 'signingyear']);

                        $workflow_all    = $mysqli->real_escape_string($get_cell(['all', 'workflowall']));
                        $ce_ts           = $mysqli->real_escape_string($get_split('ce_ts', 'cets'));
                        $ce_ts_xml       = $mysqli->real_escape_string($get_split('ce_ts_xml', 'cetsxml'));
                        $ce              = $mysqli->real_escape_string($get_split('ce', 'ce'));
                        $ts              = $mysqli->real_escape_string($get_split('ts', 'ts'));
                        $pmc             = $mysqli->real_escape_string($get_split('pmc', 'pmc'));
                        $pubmed          = $mysqli->real_escape_string($get_cell(['pubmed']));
                        $clockss         = $mysqli->real_escape_string($get_cell(['clockss']));
                        $scielo          = $mysqli->real_escape_string($get_cell(['scielo']));
                        $crossref        = $mysqli->real_escape_string($get_cell(['crossref']));
                        $doaj            = $mysqli->real_escape_string($get_cell(['doaj']));
                        $bibilat         = $mysqli->real_escape_string($get_cell(['bibilat']));
                        $medes           = $mysqli->real_escape_string($get_cell(['medes']));
                        $pdf_to_xml      = $mysqli->real_escape_string($get_split('pdf_to_xml', 'pdftoxml'));
                        $html            = $mysqli->real_escape_string($get_split('html', 'html'));
                        $new_html        = $mysqli->real_escape_string($get_cell(['newhtml', 'new_html']));
                        $direct_html     = $mysqli->real_escape_string($get_split('direct_html', 'directhtml'));
                        $xml_html        = $mysqli->real_escape_string($get_split('xml_html', 'xmlhtml'));
                        $image           = $mysqli->real_escape_string($get_cell(['image']));
                        $math            = $mysqli->real_escape_string($get_cell(['math']));
                        $remarks         = $mysqli->real_escape_string($get_cell(['remarks']));
                        $cm_template     = $get_cell(['template']);
                        $cm_tds          = $get_cell(['tds', 'tdspercentage']);
                        $cm_igst         = $get_cell(['igst']);
                    } else {
                        // Positional fallback for legacy files
                        $client_name = trim($r[1] ?? '');
                        if (empty($client_name)) {
                            $count_skipped++;
                            continue;
                        }
                        $country = $mysqli->real_escape_string(trim($r[2] ?? ''));
                        $previous_status = $mysqli->real_escape_string(trim($r[3] ?? ''));
                        $client_status   = $mysqli->real_escape_string(trim($r[4] ?? 'Active'));
                        $nda_status      = $mysqli->real_escape_string(trim($r[5] ?? 'No'));
                        $revision_status = $mysqli->real_escape_string(trim($r[6] ?? 'No'));
                        $signed_date_raw = $r[7] ?? '';
                        $signed_year_raw = $r[8] ?? '';
                        $workflow_all    = $mysqli->real_escape_string(trim($r[9] ?? ''));
                        $ce_ts           = $mysqli->real_escape_string(trim($r[10] ?? ''));
                        $ce_ts_xml       = $mysqli->real_escape_string(trim($r[11] ?? ''));
                        $ce              = $mysqli->real_escape_string(trim($r[12] ?? ''));
                        $ts              = $mysqli->real_escape_string(trim($r[13] ?? ''));
                        $pmc             = $mysqli->real_escape_string(trim($r[14] ?? ''));
                        $pubmed          = $mysqli->real_escape_string(trim($r[15] ?? ''));
                        $clockss         = $mysqli->real_escape_string(trim($r[16] ?? ''));
                        $scielo          = $mysqli->real_escape_string(trim($r[17] ?? ''));
                        $crossref        = $mysqli->real_escape_string(trim($r[18] ?? ''));
                        $doaj            = $mysqli->real_escape_string(trim($r[19] ?? ''));
                        $bibilat         = $mysqli->real_escape_string(trim($r[20] ?? ''));
                        $medes           = $mysqli->real_escape_string(trim($r[21] ?? ''));
                        $pdf_to_xml      = $mysqli->real_escape_string(trim($r[22] ?? ''));
                        $html            = $mysqli->real_escape_string(trim($r[23] ?? ''));
                        $new_html        = $mysqli->real_escape_string(trim($r[24] ?? ''));
                        $direct_html     = $mysqli->real_escape_string(trim($r[25] ?? ''));
                        $xml_html        = $mysqli->real_escape_string(trim($r[26] ?? ''));
                        $image           = $mysqli->real_escape_string(trim($r[27] ?? ''));
                        $math            = $mysqli->real_escape_string(trim($r[28] ?? ''));
                        $remarks         = $mysqli->real_escape_string(trim($r[29] ?? ''));
                        $cm_template     = trim($r[30] ?? '');
                        $cm_tds          = trim($r[31] ?? '');
                        $cm_igst         = trim($r[32] ?? '');
                    }
                    if (empty($client_status)) { $client_status = 'Active'; }

                    // Convert blank billing values to NULL for client_master
                    $sql_template = ($cm_template !== '') ? "'" . $mysqli->real_escape_string($cm_template) . "'" : "NULL";
                    $sql_tds      = ($cm_tds !== '' && is_numeric($cm_tds))  ? (float)$cm_tds  : "NULL";
                    $sql_igst     = ($cm_igst !== '' && is_numeric($cm_igst)) ? (float)$cm_igst : "NULL";

                    // Check if client exists in client_master (unique constraint)
                    $check_client = $mysqli->query("SELECT client_id FROM client_master WHERE client_name = '" . $mysqli->real_escape_string($client_name) . "'");
                    
                    if ($check_client && $check_client->num_rows > 0) {
                        // Client exists - use existing client_id
                        $client_id = $check_client->fetch_assoc()['client_id'];
                        
                        // Update country/status and billing fields if provided
                        $update_parts = "country='$country', status='$client_status', previous_status='$previous_status'";
                        if ($sql_template !== "NULL") $update_parts .= ", template=$sql_template";
                        if ($sql_tds      !== "NULL") $update_parts .= ", tds_percentage=$sql_tds";
                        if ($sql_igst     !== "NULL") $update_parts .= ", igst=$sql_igst";
                        $mysqli->query("UPDATE client_master SET $update_parts WHERE client_id=$client_id");
                    } else {
                        // Create new client (unique entry)
                        $sql_client = "INSERT INTO client_master (client_name, country, status, previous_status, template, tds_percentage, igst) VALUES ('" . $mysqli->real_escape_string($client_name) . "', '$country', '$client_status', '$previous_status', $sql_template, $sql_tds, $sql_igst)";
                        if ($mysqli->query($sql_client)) {
                            $client_id = $mysqli->insert_id;
                        } else {
                            $count_skipped++;
                            $errors[] = "Row " . ($k + 1) . ": Failed to create client '$client_name' - " . $mysqli->error;
                            continue;
                        }
                    }

                    // Process Date & Year
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

                    $signed_year = trim((string)($signed_year_raw ?? ''));
                    if ($signed_year === '' && $signed_date) {
                        $signed_year = date('Y', strtotime($signed_date));
                    }
                    $sql_year = $mysqli->real_escape_string($signed_year);

                    // Always insert new NDA record (allow multiple NDAs per client)
                    $sql = "INSERT INTO nda_details (
                            client_id, previous_status, nda_status, revision_amount_status, signed_date, signed_year,
                            workflow_all, ce_ts, ce_ts_xml, ce, ts, pmc, pubmed, clockss, scielo, crossref, doaj, bibilat, medes, pdf_to_xml, html, new_html, direct_html, xml_html, image, math, remarks,
                            created_at, updated_at
                        ) VALUES (
                            $client_id, '$previous_status', '$nda_status', '$revision_status', $sql_date, '$sql_year',
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
            redirect_with(['msg' => 'Failed to parse file. Please upload a valid .xlsx, .xls, or .csv file.', 'type' => 'danger']);
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
        $previous_status = $mysqli->real_escape_string(trim($_POST['previous_status'] ?? ''));

        // NDA Fields
        $nda_status = $mysqli->real_escape_string($_POST['nda_status'] ?? 'No');
        $revision_status = $mysqli->real_escape_string($_POST['revision_amount_status'] ?? 'No');
        $signed_date = $_POST['signed_date'] ?? NULL;
        $sql_date = empty($signed_date) ? "NULL" : "'" . $mysqli->real_escape_string(date('Y-m-d', strtotime($signed_date))) . "'";
        $signed_year = trim($_POST['signed_year'] ?? '');
        if ($signed_year === '' && !empty($signed_date)) {
            $signed_year = date('Y', strtotime($signed_date));
        }
        $signed_year = $mysqli->real_escape_string($signed_year);
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
        $pmc         = $get_split('pmc');
        $pdf_to_xml  = $get_split('pdf_to_xml');
        $html        = $get_split('html');
        $direct_html = $get_split('direct_html');
        $xml_html    = $get_split('xml_html');

        // Single-rate fields
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
                
                // Update country/status/previous_status if needed
                $mysqli->query("UPDATE client_master SET country='$country', status='$client_status', previous_status='$previous_status' WHERE client_id=$target_client_id");
            } else {
                // Create new client (unique entry)
                $sql_client = "INSERT INTO client_master (client_name, country, status, previous_status) VALUES ('$client_name', '$country', '$client_status', '$previous_status')";
                if ($mysqli->query($sql_client)) {
                    $target_client_id = $mysqli->insert_id;
                } else {
                    redirect_with(['msg' => 'Failed to create client: ' . $mysqli->error, 'type' => 'danger']);
                }
            }
            
            // Create the NDA record for this client (allow multiple NDAs per client)
            $sql = "INSERT INTO nda_details (
                        client_id, nda_status, revision_amount_status, previous_status, signed_date, signed_year, 
                        workflow_all, ce_ts, ce_ts_xml, ce, ts, pmc, pubmed, clockss, scielo, crossref, doaj, bibilat, medes, pdf_to_xml, html, new_html, direct_html, xml_html, image, math, remarks,
                        created_at, updated_at
                    ) VALUES (
                        $target_client_id, '$nda_status', '$revision_status', '$previous_status', $sql_date, '$signed_year', 
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
                $mysqli->query("UPDATE client_master SET client_name='$client_name', country='$country', status='$client_status', previous_status='$previous_status' WHERE client_id=$client_id");
                
                // Update specific NDA record by its ID
                $sql = "UPDATE nda_details 
                        SET nda_status = '$nda_status', 
                            revision_amount_status = '$revision_status', 
                            previous_status = '$previous_status',
                            signed_date = $sql_date,
                            signed_year = '$signed_year',
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

// ---------- NDA Summary Statistics Initial State ----------
$summary = ['total_clients' => 0, 'total_nda_records' => 0, 'nda_signed' => 0, 'nda_not_signed' => 0, 'revision_applicable' => 0, 'signed_this_year' => 0, 'signed_percentage' => 0];

// ---------- View / Edit / Add Mode Configuration ----------
$mode = $_GET['mode'] ?? 'view';
$editClient = null;
$editNdaId = null;

// Fetch all clients for dropdown with their countries
$all_clients =[];
$res_clients = $mysqli->query("SELECT client_id, client_name, country, status, previous_status FROM client_master ORDER BY client_name ASC");
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
$filter_status = $_GET['client_status'] ?? '';
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

// Status is calculated from the signed year (To Year)
$ref_year = 0;
if ($to_year !== '' && is_numeric($to_year)) {
    $ref_year = (int)$to_year;
} elseif ($from_year !== '' && is_numeric($from_year)) {
    $ref_year = (int)date('Y');
}
if ($ref_year > 0) {
    $status_expr = "IF(EXISTS(SELECT 1 FROM nda_details y WHERE y.client_id = cm.client_id AND COALESCE(NULLIF(y.signed_year, ''), YEAR(y.signed_date)) = $ref_year), 'Active', 'Inactive')";
} else {
    $status_expr = "cm.status";
}

$sql = "SELECT 
            nd.id as nda_id, cm.client_id, cm.client_name, cm.company_name, cm.email, cm.phone, cm.country, $status_expr as client_status,
            COALESCE(nd.previous_status, cm.previous_status, '') as previous_status,
            IFNULL(nd.nda_status, 'No') as nda_status, 
            IFNULL(nd.revision_amount_status, 'No') as revision_amount_status, 
            nd.signed_date, nd.signed_year, nd.remarks,
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
    $cond[] = "COALESCE(NULLIF(nd.signed_year, ''), YEAR(nd.signed_date)) >= ?";
    $params[] = (int)$from_year;
    $types .= "i";
}

// 4. To Year Filter
if ($to_year !== '' && is_numeric($to_year)) {
    $cond[] = "COALESCE(NULLIF(nd.signed_year, ''), YEAR(nd.signed_date)) <= ?";
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

// 7. Client Status Filter (Active / Inactive)
if ($filter_status === 'Active' || $filter_status === 'Inactive') {
    $cond[] = "$status_expr = ?";
    $params[] = $filter_status;
    $types .= "s";
}

if ($cond) $sql .= " WHERE " . implode(" AND ", $cond);
$sql .= " ORDER BY $orderBy $dir";

// ---------- Get Dynamic NDA Summary Statistics (reflecting active filters) ----------
$stats_sql = "SELECT 
                COUNT(DISTINCT cm.client_id) as total_clients,
                COUNT(nd.id) as total_nda_records,
                COUNT(CASE WHEN nd.id IS NOT NULL AND IFNULL(nd.nda_status, 'No') = 'Yes' THEN 1 END) as signed_count,
                COUNT(CASE WHEN nd.id IS NOT NULL AND IFNULL(nd.nda_status, 'No') = 'No' THEN 1 END) as not_signed_count,
                COUNT(CASE WHEN nd.id IS NOT NULL AND IFNULL(nd.revision_amount_status, 'No') = 'Yes' THEN 1 END) as revision_count,
                COUNT(CASE WHEN nd.id IS NOT NULL AND (YEAR(nd.signed_date) = YEAR(CURDATE()) OR nd.signed_year = YEAR(CURDATE())) THEN 1 END) as signed_this_year
              FROM client_master cm
              LEFT JOIN nda_details nd ON cm.client_id = nd.client_id";

if (!empty($cond)) {
    $stats_sql .= " WHERE " . implode(" AND ", $cond);
}

try {
    if (!empty($types)) {
        $stmt_stats = $mysqli->prepare($stats_sql);
        if ($stmt_stats) {
            $stmt_stats->bind_param($types, ...$params);
            $stmt_stats->execute();
            $stats_res = $stmt_stats->get_result();
            if ($stats_res && $stats = $stats_res->fetch_assoc()) {
                $summary['total_clients'] = (int)($stats['total_clients'] ?? 0);
                $summary['total_nda_records'] = (int)($stats['total_nda_records'] ?? 0);
                $summary['nda_signed'] = (int)($stats['signed_count'] ?? 0);
                $summary['nda_not_signed'] = (int)($stats['not_signed_count'] ?? 0);
                $summary['revision_applicable'] = (int)($stats['revision_count'] ?? 0);
                $summary['signed_this_year'] = (int)($stats['signed_this_year'] ?? 0);
            }
            $stmt_stats->close();
        }
    } else {
        $stats_res = $mysqli->query($stats_sql);
        if ($stats_res && $stats = $stats_res->fetch_assoc()) {
            $summary['total_clients'] = (int)($stats['total_clients'] ?? 0);
            $summary['total_nda_records'] = (int)($stats['total_nda_records'] ?? 0);
            $summary['nda_signed'] = (int)($stats['signed_count'] ?? 0);
            $summary['nda_not_signed'] = (int)($stats['not_signed_count'] ?? 0);
            $summary['revision_applicable'] = (int)($stats['revision_count'] ?? 0);
            $summary['signed_this_year'] = (int)($stats['signed_this_year'] ?? 0);
        }
    }
} catch (Exception $e) {
    error_log("NDA summary error: " . $e->getMessage());
}

$summary['signed_percentage'] = $summary['total_nda_records'] > 0 ? round(($summary['nda_signed'] / $summary['total_nda_records']) * 100, 1) : 0;

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
    /* Multi-row Sticky Table Header for NDA Details */
    .nda-table-responsive {
        max-height: 72vh !important;
        overflow: auto !important;
        position: relative !important;
    }

    .nda-table {
        border-collapse: separate !important;
        border-spacing: 0 !important;
        width: 100% !important;
    }

    /* All NDA header cells */
    .table thead.table-dark th,
    .nda-table thead th {
        background-color: #6c757d !important;
        color: #ffffff !important;
        border-color: #5a6268 !important;
        box-sizing: border-box !important;
        background-clip: padding-box !important;
    }

    /* Row 1 sticky header */
    .nda-table thead tr:first-child th {
        position: sticky !important;
        top: 0 !important;
        z-index: 25 !important;
        border-top: 1px solid #5a6268 !important;
        border-bottom: 1px solid #5a6268 !important;
        border-right: 1px solid #5a6268 !important;
        padding-top: 6px !important;
        padding-bottom: 6px !important;
        white-space: nowrap !important;
    }

    .nda-table thead tr:first-child th:first-child {
        border-left: 1px solid #5a6268 !important;
    }

    /* Row 1 spanning cells (S.No, Client Name, Country, etc.) */
    .nda-table thead tr:first-child th[rowspan="2"] {
        position: sticky !important;
        top: 0 !important;
        z-index: 26 !important;
        vertical-align: middle !important;
    }

    /* Row 2 sticky sub-headers (WITH / W/O) */
    .nda-table thead tr:nth-child(2) th {
        position: sticky !important;
        top: 31px !important;
        z-index: 24 !important;
        background-color: #6c757d !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        border-bottom: 1px solid #5a6268 !important;
        border-right: 1px solid #5a6268 !important;
        padding-top: 4px !important;
        padding-bottom: 4px !important;
        white-space: nowrap !important;
    }

    /* Table body cell borders under separate border-collapse */
    .nda-table tbody td {
        border-bottom: 1px solid #e9ecef !important;
        border-right: 1px solid #e9ecef !important;
    }

    .nda-table tbody tr td:first-child {
        border-left: 1px solid #e9ecef !important;
    }

    /* Sticky Actions column */
    .nda-table thead tr:first-child th.sticky-actions-col {
        position: sticky !important;
        top: 0 !important;
        right: 0 !important;
        z-index: 35 !important;
        background-color: #6c757d !important;
        box-shadow: -2px 0 6px rgba(0, 0, 0, 0.15) !important;
    }

    .nda-table tbody td.sticky-actions-col {
        position: sticky !important;
        right: 0 !important;
        z-index: 10 !important;
        background-color: #ffffff !important;
        box-shadow: -2px 0 6px rgba(0, 0, 0, 0.08) !important;
    }

    .nda-table tbody tr:hover td.sticky-actions-col {
        background-color: #f8fafc !important;
    }
</style>

<!-- UI STARTS HERE -->
<div class="container-fluid py-4 main-content">

    <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
        <?= h($_GET['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($mode === 'view'): ?>
    <!-- PAGE TITLE CONTAINER AT TOP -->
    <div class="page-title-container" style="background-color: #6c757d !important; background: #6c757d !important; color: #ffffff !important; padding: 12px 20px !important; border-radius: 12px !important; margin-top: 0 !important; margin-bottom: 20px !important; display: flex !important; justify-content: space-between !important; align-items: center !important;">
        <div>
            <h4 class="fw-bold mb-0 text-white" style="color: #ffffff !important; font-size: 1.25rem !important;"><i class="fas fa-file-contract me-2" style="color: #ffffff !important;"></i>NDA Details</h4>
            <p class="mb-0 text-white opacity-75" style="color: rgba(255,255,255,0.85) !important;">Manage NDA records, signed contracts, and renewal timelines</p>
        </div>
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

    <!-- CONSOLIDATED NDA SUMMARY SECTION -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
                <div class="card-header py-2 px-3 text-white d-flex align-items-center" style="background-color: #6c757d !important; background: #6c757d !important; border-top-left-radius: 12px; border-top-right-radius: 12px;">
                    <div class="rounded-circle bg-white p-1 me-2" style="width: 26px; height: 26px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-chart-pie small" style="color: #6c757d;"></i>
                    </div>
                    <span class="fw-bold text-white mb-0" style="font-size: 1rem;">NDA Performance Dashboard</span>
                </div>
                <div class="card-body py-3" style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);">
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
    <?php endif; ?>

    <!-- ADD / EDIT FORM -->
    <?php if ($mode === 'edit' || $mode === 'add'): ?>
    <?php 
        $isAdd = ($mode === 'add');
        $formAction = $isAdd ? 'add_new' : 'update_nda';
        $title = $isAdd ? '<i class="fas fa-plus-circle me-2"></i>Create New NDA Record' : '<i class="fas fa-edit me-2"></i>Update NDA Record for: ' . h($editClient['client_name'] ?? '');
        $btnText = $isAdd ? 'Save New NDA Record' : 'Update NDA Details';
    ?>
    <div class="mx-auto" style="max-width: 950px;">
    <div class="page-title-container" style="background-color: #6c757d !important; background: #6c757d !important; color: #ffffff !important; padding: 12px 20px !important; border-radius: 12px !important; margin-top: 0 !important; margin-bottom: 20px !important; display: flex !important; justify-content: space-between !important; align-items: center !important;">
        <h4 class="fw-bold mb-0 text-white" style="color: #ffffff !important; font-size: 1.25rem !important;"><?= $title ?></h4>
        <a href="nda_details.php" class="btn btn-outline-light text-white btn-sm shadow-sm">
            <i class="fas fa-arrow-left me-1"></i> Back to List
        </a>
    </div>
    <div class="card mb-4 shadow-sm">
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
                    <div class="col-md-3">
                        <label class="form-label small">Client Name <span class="text-danger">*</span></label>
                        <?php if ($isAdd): ?>
                            <select name="client_name" id="client_select" class="form-select form-select-sm" required onchange="autoFillCountry()">
                                <option value="">-- Select Existing Client or Type New --</option>
                                <?php foreach($all_clients as $cl): ?>
                                    <option value="<?= h($cl['client_name']) ?>" data-country="<?= h($cl['country'] ?? '') ?>" data-status="<?= h($cl['status'] ?? 'Active') ?>" data-previous-status="<?= h($cl['previous_status'] ?? '') ?>">
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
                    <div class="col-md-3">
                        <label class="form-label small">Country</label>
                        <input type="text" name="country" id="country_input" class="form-control form-control-sm" value="<?= h($editClient['country'] ?? '') ?>" placeholder="Enter country name">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Previous Status</label>
                        <input type="text" name="previous_status" id="previous_status_input" class="form-control form-control-sm" value="<?= h($editClient['previous_status'] ?? '') ?>" placeholder="Enter previous status">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Current Status</label>
                        <select name="client_status" id="status_select" class="form-select form-select-sm">
                            <option value="Active" <?= (strtolower($editClient['client_status'] ?? '') == 'active' || $isAdd) ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= (strtolower($editClient['client_status'] ?? '') == 'inactive') ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                </div>

                <!-- NDA Information (Multiple records allowed) -->
                <h6 class="border-bottom pb-2 text-success fw-bold"><i class="fas fa-file-contract me-2"></i>NDA Details (Multiple Records Allowed)</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <label class="form-label small">NDA Status (Signed?)</label>
                        <select name="nda_status" class="form-select form-select-sm">
                            <option value="No" <?= (strtolower($editClient['nda_status'] ?? '') == 'no' || $isAdd) ? 'selected' : '' ?>>No</option>
                            <option value="Yes" <?= (strtolower($editClient['nda_status'] ?? '') == 'yes') ? 'selected' : '' ?>>Yes</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Revision Amount Status</label>
                        <select name="revision_amount_status" class="form-select form-select-sm">
                            <option value="No" <?= (strtolower($editClient['revision_amount_status'] ?? '') == 'no' || $isAdd) ? 'selected' : '' ?>>No</option>
                            <option value="Yes" <?= (strtolower($editClient['revision_amount_status'] ?? '') == 'yes') ? 'selected' : '' ?>>Yes</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Date of Signing</label>
                        <input type="date" name="signed_date" id="signed_date_input" class="form-control form-control-sm" value="<?= !empty($editClient['signed_date']) && $editClient['signed_date'] != '0000-00-00' ? date('Y-m-d', strtotime($editClient['signed_date'])) : '' ?>" onchange="autoFillYear(this.value)">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Signed Year</label>
                        <input type="text" name="signed_year" id="signed_year_input" class="form-control form-control-sm" value="<?= h($editClient['signed_year'] ?? (!empty($editClient['signed_date']) && $editClient['signed_date'] != '0000-00-00' ? date('Y', strtotime($editClient['signed_date'])) : '')) ?>" placeholder="e.g. 2026">
                        <small class="text-muted" style="font-size: 0.72rem;">Enter year or auto-fills from date</small>
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
    </div>
    <?php endif; ?>

    <!-- LIST VIEW -->
    <?php if ($mode === 'view'): ?>
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
                
                <!-- Client Status (Active / Inactive) -->
                <div class="col-xl-2 col-lg-3 col-md-4">
                    <select name="client_status" class="form-select form-select-sm">
                        <option value="">Filter by Current Status</option>
                        <option value="Active" <?= $filter_status === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= $filter_status === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
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
                        <i class="fas fa-filter me-1"></i> Apply Filters
                    </button>
                    <a href="nda_details.php" class="btn btn-outline-secondary btn-sm shadow-sm flex-grow-1" title="Clear Filters">
                            <i class="fas fa-undo me-1"></i> Reset
                        </a>
                </div>

            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive nda-table-responsive">
                <table class="table table-hover table-sm align-middle mb-0 nda-table" style="font-size: 0.75rem;">
                    <thead class="table-dark">
                        <tr>
                            <th rowspan="2" class="py-2 text-center border-end align-middle">S.No</th>
                            <th rowspan="2" class="py-2 border-end align-middle">Client Name</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle">Country</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle" title="Previous Status">Previous Status</th>
                            <th rowspan="2" class="py-2 text-center border-end align-middle" title="Current Status">Current Status</th>
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
                            <th colspan="2" class="py-1 text-center border-end" title="PMC">PMC</th>
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

                            <th rowspan="2" class="py-2 text-center align-middle sticky-actions-col">Actions</th>
                        </tr>
                        <!-- Row 2: Sub-headers for Split Columns using exact table-dark header style -->
                        <tr style="font-size: 0.68rem;">
                            <!-- CE+TS -->
                            <th class="py-1 text-center border-end text-light fw-bold">WITH</th>
                            <th class="py-1 text-center border-end text-light fw-bold">W/O</th>
                            <!-- CE+TS+XML -->
                            <th class="py-1 text-center border-end text-light fw-bold">WITH</th>
                            <th class="py-1 text-center border-end text-light fw-bold">W/O</th>
                            <!-- CE -->
                            <th class="py-1 text-center border-end text-light fw-bold">WITH</th>
                            <th class="py-1 text-center border-end text-light fw-bold">W/O</th>
                            <!-- TS -->
                            <th class="py-1 text-center border-end text-light fw-bold">WITH</th>
                            <th class="py-1 text-center border-end text-light fw-bold">W/O</th>
                            <!-- PMC -->
                            <th class="py-1 text-center border-end text-light fw-bold">WITH</th>
                            <th class="py-1 text-center border-end text-light fw-bold">W/O</th>
                            <!-- PDF to XML -->
                            <th class="py-1 text-center border-end text-light fw-bold">WITH</th>
                            <th class="py-1 text-center border-end text-light fw-bold">W/O</th>
                            <!-- HTML -->
                            <th class="py-1 text-center border-end text-light fw-bold">WITH</th>
                            <th class="py-1 text-center border-end text-light fw-bold">W/O</th>
                            <!-- Direct HTML -->
                            <th class="py-1 text-center border-end text-light fw-bold">WITH</th>
                            <th class="py-1 text-center border-end text-light fw-bold">W/O</th>
                            <!-- XML+HTML -->
                            <th class="py-1 text-center border-end text-light fw-bold">WITH</th>
                            <th class="py-1 text-center border-end text-light fw-bold">W/O</th>
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
                                $p_pmc      = parse_split_rate($row['pmc'] ?? '');
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
                            <td class="text-center border-end"><?= !empty($row['previous_status']) ? h($row['previous_status']) : '-' ?></td>
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
                            <td class="text-center fw-bold text-info border-end"><?= !empty($row['signed_year']) ? h($row['signed_year']) : ((!empty($row['signed_date']) && $row['signed_date'] != '0000-00-00') ? date('Y', strtotime($row['signed_date'])) : '-') ?></td>
                            
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

                            <!-- PMC (Split) -->
                            <td class="text-center border-end"><?= h($p_pmc['with']) ?></td>
                            <td class="text-center border-end"><?= h($p_pmc['without']) ?></td>

                            <!-- Single Services -->
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
                            <td class="text-center text-nowrap sticky-actions-col">
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
                            <td colspan="40" class="text-center py-5 text-muted">
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
                <div class="modal-header text-white border-0" style="background-color: #6c757d !important; background: #6c757d !important;">
                    <h5 class="modal-title fw-bold text-white">
                        <i class="fas fa-file-excel me-2 text-white"></i>Import NDA & Workflows
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <form action="" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_excel">
                    
                    <div class="modal-body p-4">
                        <div class="row bg-light rounded p-3 mb-4 shadow-sm border">
                            <div class="col-md-8">
                                <label class="form-label fw-bold text-dark">Select File (.xlsx, .xls, .csv)</label>
                                <input type="file" name="excel_file" class="form-control" accept=".xlsx, .xls, .csv" required>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="button" class="btn btn-success w-100 shadow-sm" onclick="downloadSample()">
                                    <i class="fas fa-download me-2"></i> Download Sample
                                </button>
                            </div>
                        </div>
                        
                        <div class="alert alert-secondary border-0 shadow-sm">
                            <h6 class="fw-bold text-dark mb-2"><i class="fas fa-list-ol me-2 text-primary"></i>Template Column Format (Matches UI Headers):</h6>
                            <p class="mb-2 text-muted small">Supports separate <strong>WITH</strong> and <strong>W/O</strong> (Without) columns matching the table, plus custom <strong>Year</strong>. Headers are automatically detected.</p>
                            <div class="table-responsive bg-white rounded border">
                                <table class="table table-sm table-bordered mb-0 align-middle text-center small" style="font-size: 0.68rem;">
                                    <thead class="table-light">
                                        <tr>
                                            <th>S.No</th><th class="text-primary fw-bold">Client Name*</th><th>Country</th><th>Previous Status</th><th>Current Status</th><th>NDA Status</th><th>Rev.Amt</th><th>Signed Date</th><th class="text-info fw-bold">Year</th><th>All</th><th>CE+TS WITH</th><th>CE+TS W/O</th><th>CE+TS+XML WITH</th><th>CE+TS+XML W/O</th><th>CE WITH</th><th>CE W/O</th><th>TS WITH</th><th>TS W/O</th><th>PMC WITH</th><th>PMC W/O</th><th>PUBMED</th><th>CLOCKSS</th><th>SCIELO</th><th>Crossref</th><th>DOAJ</th><th>BIBILAT</th><th>MEDES</th><th>PDF to XML WITH</th><th>PDF to XML W/O</th><th>HTML WITH</th><th>HTML W/O</th><th>New HTML</th><th>Direct HTML WITH</th><th>Direct HTML W/O</th><th>XML+HTML WITH</th><th>XML+HTML W/O</th><th>Image</th><th>Math</th><th>Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody class="text-muted">
                                        <tr>
                                            <td>1</td><td class="text-primary">ABC Corp</td><td>India</td><td>Lead</td><td>Active</td><td>Yes</td><td>Yes</td><td>2025-10-24</td><td>2025</td><td>10</td><td>10</td><td>8</td><td>10</td><td>8</td><td>10</td><td>8</td><td>10</td><td>8</td><td>10</td><td>8</td><td>10</td><td>10</td><td>10</td><td>10</td><td>10</td><td>10</td><td>10</td><td>10</td><td>8</td><td>10</td><td>8</td><td>10</td><td>10</td><td>8</td><td>10</td><td>8</td><td>10</td><td>10</td><td>Sample remarks</td>
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
            var prevStatus = selectedOption.getAttribute('data-previous-status');
            
            // Fill Country
            var countryInput = document.getElementById('country_input');
            if (countryInput) countryInput.value = country || '';

            // Fill Previous Status
            var prevStatusInput = document.getElementById('previous_status_input');
            if (prevStatusInput) prevStatusInput.value = prevStatus || '';
            
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
            var prevStatusInput = document.getElementById('previous_status_input');
            if (prevStatusInput) prevStatusInput.value = '';
            var statusSelect = document.getElementById('status_select');
            if (statusSelect) statusSelect.selectedIndex = 0;
        }
    }

    function autoFillYear(dateVal) {
        var yearInput = document.getElementById('signed_year_input');
        if (yearInput && !yearInput.value && dateVal) {
            var parts = dateVal.split('-');
            if (parts.length > 0 && parts[0].length === 4) {
                yearInput.value = parts[0];
            }
        }
    }

    function downloadSample() {
        const sampleContent = "S.No,Client Name,Country,Previous Status,Current Status,NDA Status,Rev.Amt,Signed Date,Year,All,CE+TS WITH,CE+TS W/O,CE+TS+XML WITH,CE+TS+XML W/O,CE WITH,CE W/O,TS WITH,TS W/O,PMC WITH,PMC W/O,PUBMED,CLOCKSS,SCIELO,Crossref,DOAJ,BIBILAT,MEDES,PDF to XML WITH,PDF to XML W/O,HTML WITH,HTML W/O,New HTML,Direct HTML WITH,Direct HTML W/O,XML+HTML WITH,XML+HTML W/O,Image,Math,Remarks\n" +
                             "1,ABC Corp,India,Lead,Active,Yes,Yes,2025-10-24,2025,10,10,8,10,8,10,8,10,8,10,8,10,10,10,10,10,10,10,10,8,10,8,10,10,8,10,8,10,10,Standard terms applied\n" +
                             "2,XYZ Ltd,USA,New,Active,Yes,No,2025-11-15,2025,8,8,6,8,6,8,6,8,6,8,6,8,8,8,8,8,8,8,8,6,8,6,8,8,6,8,6,8,8,Second NDA record\n" +
                             "3,Global Media,UK,Active,Active,Yes,Yes,2026-01-10,2026,12,12,10,12,10,12,10,12,10,12,10,12,12,12,12,12,12,12,12,10,12,10,12,12,10,12,10,12,12,Annual contract";
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

    // Ensure sticky multi-row sub-headers (WITH / W/O) dynamically align seamlessly under Row 1
    function syncStickySubheaders() {
        var table = document.querySelector('.nda-table');
        if (!table) return;
        var firstRowColspanTh = table.querySelector('thead tr:first-child th[colspan]');
        if (firstRowColspanTh) {
            var row1Height = firstRowColspanTh.offsetHeight;
            if (row1Height > 0) {
                var subHeaderThs = table.querySelectorAll('thead tr:nth-child(2) th');
                subHeaderThs.forEach(function(th) {
                    th.style.setProperty('top', row1Height + 'px', 'important');
                });
            }
        }
    }
    window.addEventListener('DOMContentLoaded', syncStickySubheaders);
    window.addEventListener('load', syncStickySubheaders);
    window.addEventListener('resize', syncStickySubheaders);
    setTimeout(syncStickySubheaders, 200);
</script>
</body>
</html>