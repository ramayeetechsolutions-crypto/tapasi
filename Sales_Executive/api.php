<?php
// api.php
require_once 'db.php';
require_once 'publisher_payment.php';

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Log for debugging
error_log("API called: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);
error_log("POST data: " . print_r($_POST, true));
error_log("GET data: " . print_r($_GET, true));

$database = new Database();
$db = $database->getConnection();
$payment = new PublisherPayment($db);

// Get action from POST or GET
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

error_log("Action: " . $action);

switch($action) {
    case 'create':
        error_log("Processing CREATE action");
        
        // Validate required fields
        $required = ['month', 'publisher_name', 'country', 'amount', 'total_amount'];
        $missing = [];
        foreach($required as $field) {
            if(empty($_POST[$field])) {
                $missing[] = $field;
            }
        }
        
        if(!empty($missing)) {
            echo json_encode([
                'success' => false,
                'message' => 'Missing required fields: ' . implode(', ', $missing)
            ]);
            break;
        }
        
        try {
            $payment->month = $_POST['month'] ?? '';
            $payment->publisher_name = $_POST['publisher_name'] ?? '';
            $payment->country = $_POST['country'] ?? '';
            $payment->no_of_journals = intval($_POST['no_of_journals'] ?? 0);
            $payment->frequency = $_POST['frequency'] ?? '';
            $payment->services = $_POST['services'] ?? '';
            $payment->amount = floatval($_POST['amount'] ?? 0);
            $payment->total_amount = floatval($_POST['total_amount'] ?? 0);
            $payment->tds = floatval($_POST['tds'] ?? 0);
            $payment->ba = floatval($_POST['ba'] ?? 0);
            $payment->status = $_POST['status'] ?? 'Pending';
            
            error_log("Creating payment with: month={$payment->month}, publisher={$payment->publisher_name}, amount={$payment->amount}");
            
            if($payment->create()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Payment record created successfully.',
                    'id' => $payment->id
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to create payment record. Database error.'
                ]);
            }
        } catch(Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage()
            ]);
        }
        break;
        
    case 'update':
        if(empty($_POST['id'])) {
            echo json_encode(['success' => false, 'message' => 'Missing payment ID']);
            break;
        }
        
        try {
            $payment->id = $_POST['id'];
            $payment->month = $_POST['month'] ?? '';
            $payment->publisher_name = $_POST['publisher_name'] ?? '';
            $payment->country = $_POST['country'] ?? '';
            $payment->no_of_journals = intval($_POST['no_of_journals'] ?? 0);
            $payment->frequency = $_POST['frequency'] ?? '';
            $payment->services = $_POST['services'] ?? '';
            $payment->amount = floatval($_POST['amount'] ?? 0);
            $payment->total_amount = floatval($_POST['total_amount'] ?? 0);
            $payment->tds = floatval($_POST['tds'] ?? 0);
            $payment->ba = floatval($_POST['ba'] ?? 0);
            $payment->status = $_POST['status'] ?? 'Pending';
            
            if($payment->update()) {
                echo json_encode(['success' => true, 'message' => 'Payment updated successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update payment.']);
            }
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        break;
        
    case 'delete':
        if(empty($_POST['id'])) {
            echo json_encode(['success' => false, 'message' => 'Missing payment ID']);
            break;
        }
        
        $payment->id = $_POST['id'];
        if($payment->delete()) {
            echo json_encode(['success' => true, 'message' => 'Payment deleted successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete payment.']);
        }
        break;
        
    case 'read':
        $page = intval($_GET['page'] ?? 1);
        $limit = intval($_GET['limit'] ?? 10);
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? '';
        $country = $_GET['country'] ?? '';
        
        $offset = ($page - 1) * $limit;
        
        // Build query
        $where = [];
        $params = [];
        
        if(!empty($search)) {
            $where[] = "(publisher_name LIKE ? OR month LIKE ? OR country LIKE ?)";
            $searchTerm = "%$search%";
            $params = array_fill(0, 3, $searchTerm);
        }
        
        if(!empty($status)) {
            $where[] = "status = ?";
            $params[] = $status;
        }
        
        if(!empty($country)) {
            $where[] = "country = ?";
            $params[] = $country;
        }
        
        $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
        
        // Get data
        $query = "SELECT * FROM publisher_payments $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $stmt = $db->prepare($query);
        
        // Add limit and offset parameters
        $params[] = $limit;
        $params[] = $offset;
        
        // Bind parameters
        foreach($params as $i => $param) {
            $stmt->bindValue($i+1, $param);
        }
        
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM publisher_payments $whereClause";
        $countStmt = $db->prepare($countQuery);
        
        if(!empty($where)) {
            foreach(array_slice($params, 0, -2) as $i => $param) {
                $countStmt->bindValue($i+1, $param);
            }
        }
        
        $countStmt->execute();
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        echo json_encode([
            'success' => true,
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => ceil($total / $limit)
        ]);
        break;
        
    case 'read_one':
        $id = $_GET['id'] ?? 0;
        if(empty($id)) {
            echo json_encode(['success' => false, 'message' => 'Missing ID']);
            break;
        }
        
        $payment->id = $id;
        if($payment->readOne()) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'id' => $payment->id,
                    'month' => $payment->month,
                    'publisher_name' => $payment->publisher_name,
                    'country' => $payment->country,
                    'no_of_journals' => $payment->no_of_journals,
                    'frequency' => $payment->frequency,
                    'services' => $payment->services,
                    'amount' => $payment->amount,
                    'total_amount' => $payment->total_amount,
                    'tds' => $payment->tds,
                    'ba' => $payment->ba,
                    'status' => $payment->status
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Payment not found']);
        }
        break;
        
    case 'summary':
        $stmt = $db->query("SELECT COUNT(*) as total FROM publisher_payments");
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $stmt = $db->query("SELECT SUM(amount) as total_amount, SUM(total_amount) as grand_total FROM publisher_payments");
        $amounts = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $db->query("SELECT status, COUNT(*) as count FROM publisher_payments GROUP BY status");
        $statuses = [];
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $statuses[$row['status']] = $row['count'];
        }
        
        echo json_encode([
            'success' => true,
            'total_records' => $total,
            'total_amount' => $amounts['total_amount'] ?? 0,
            'grand_total' => $amounts['grand_total'] ?? 0,
            'statuses' => $statuses
        ]);
        break;
        
    case 'countries':
        $stmt = $db->query("SELECT DISTINCT country FROM publisher_payments WHERE country IS NOT NULL AND country != '' ORDER BY country");
        $countries = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode($countries);
        break;
        
    case 'history':
        $id = $_GET['id'] ?? 0;
        if(empty($id)) {
            echo json_encode([]);
            break;
        }
        
        $stmt = $db->prepare("SELECT * FROM payment_history WHERE payment_id = ? ORDER BY created_at DESC");
        $stmt->execute([$id]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($history);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action specified']);
        break;
}
?>