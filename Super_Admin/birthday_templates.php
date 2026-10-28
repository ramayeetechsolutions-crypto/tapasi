
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y H:i', strtotime($d));
}

function redirect_with($params = []) {
    $base = 'birthday_templates.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Create upload directory if it doesn't exist
$upload_dir = "../birthdaytemplate/";
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_template' || $action === 'edit_template') {
        $template_name = trim($_POST['template_name'] ?? '');
        $wishes_text = trim($_POST['wishes_text'] ?? '');
        $text_color = trim($_POST['text_color'] ?? '#FFFFFF');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $created_by = $_SESSION['user_name'] ?? 'Admin';
        
        // Basic validation
        $errors = [];
        if (empty($template_name)) $errors[] = 'Template name is required';
        if (empty($wishes_text)) $errors[] = 'Wishes text is required';
        
        // Handle image upload
        $image_path = null;
        if (isset($_FILES['background_image']) && $_FILES['background_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['background_image'];
            
            // Check file type
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mime_type, $allowed_types) || !in_array($file_extension, $allowed_extensions)) {
                $errors[] = 'Only JPG, PNG, GIF, and WebP images are allowed. Your file type: ' . $mime_type;
            }
            
            // Check file size (5MB max)
            if ($file['size'] > 5 * 1024 * 1024) {
                $errors[] = 'File size must be less than 5MB. Your file size: ' . round($file['size'] / 1024 / 1024, 2) . 'MB';
            }
            
            if (empty($errors)) {
                // Generate unique filename
                $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $template_name) . '.' . $file_extension;
                $image_path = $upload_dir . $filename;
                
                // Move uploaded file
                if (!move_uploaded_file($file['tmp_name'], $image_path)) {
                    $errors[] = 'Failed to upload image. Please check directory permissions.';
                }
            }
        } elseif (isset($_FILES['background_image']) && $_FILES['background_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $errors[] = 'File upload error: ' . $_FILES['background_image']['error'];
        }
        
        if (!empty($errors)) {
            $_SESSION['form_data'] = [
                'template_name' => $template_name,
                'wishes_text' => $wishes_text,
                'text_color' => $text_color,
                'is_active' => $is_active
            ];
            redirect_with(['msg' => implode(', ', $errors), 'type' => 'danger']);
        }
        
        try {
            if ($action === 'add_template') {
                // Insert new template
                if ($image_path) {
                    $stmt = $mysqli->prepare("INSERT INTO birthday_templates (template_name, wishes_text, image_path, text_color, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $mysqli->error);
                    }
                    $stmt->bind_param("ssssis", $template_name, $wishes_text, $image_path, $text_color, $is_active, $created_by);
                } else {
                    $stmt = $mysqli->prepare("INSERT INTO birthday_templates (template_name, wishes_text, text_color, is_active, created_by) VALUES (?, ?, ?, ?, ?)");
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $mysqli->error);
                    }
                    $stmt->bind_param("sssis", $template_name, $wishes_text, $text_color, $is_active, $created_by);
                }
                
                if ($stmt->execute()) {
                    unset($_SESSION['form_data']);
                    redirect_with(['msg' => "Birthday template added successfully", 'type' => 'success']);
                } else {
                    throw new Exception("Execute failed: " . $stmt->error);
                }
                $stmt->close();
            }
            
            if ($action === 'edit_template') {
                $template_id = (int)($_POST['template_id'] ?? 0);
                
                if ($template_id > 0) {
                    // Get current image path for deletion if needed
                    $current_image_path = null;
                    $res = $mysqli->prepare("SELECT image_path FROM birthday_templates WHERE template_id = ?");
                    if ($res) {
                        $res->bind_param("i", $template_id);
                        $res->execute();
                        $res->bind_result($current_image_path);
                        $res->fetch();
                        $res->close();
                    }
                    
                    if ($image_path) {
                        // Delete old image if exists
                        if ($current_image_path && file_exists($current_image_path)) {
                            unlink($current_image_path);
                        }
                        
                        $stmt = $mysqli->prepare("UPDATE birthday_templates SET template_name = ?, wishes_text = ?, image_path = ?, text_color = ?, is_active = ? WHERE template_id = ?");
                        if (!$stmt) {
                            throw new Exception("Prepare failed: " . $mysqli->error);
                        }
                        $stmt->bind_param("ssssii", $template_name, $wishes_text, $image_path, $text_color, $is_active, $template_id);
                    } else {
                        $stmt = $mysqli->prepare("UPDATE birthday_templates SET template_name = ?, wishes_text = ?, text_color = ?, is_active = ? WHERE template_id = ?");
                        if (!$stmt) {
                            throw new Exception("Prepare failed: " . $mysqli->error);
                        }
                        $stmt->bind_param("sssii", $template_name, $wishes_text, $text_color, $is_active, $template_id);
                    }
                    
                    if ($stmt->execute()) {
                        redirect_with(['msg' => "Birthday template updated successfully", 'type' => 'success']);
                    } else {
                        throw new Exception("Execute failed: " . $stmt->error);
                    }
                    $stmt->close();
                }
            }
        } catch (Exception $e) {
            // Delete uploaded file if there was an error
            if ($image_path && file_exists($image_path)) {
                unlink($image_path);
            }
            redirect_with(['msg' => 'Database error: ' . $e->getMessage(), 'type' => 'danger']);
        }
    }
    
    if ($action === 'delete_template') {
        $template_id = (int)($_POST['template_id'] ?? 0);
        
        if ($template_id > 0) {
            try {
                // Get template details for image deletion
                $res = $mysqli->prepare("SELECT template_name, image_path FROM birthday_templates WHERE template_id = ?");
                if (!$res) {
                    throw new Exception("Prepare failed: " . $mysqli->error);
                }
                
                $res->bind_param("i", $template_id);
                $res->execute();
                $res->bind_result($template_name, $image_path);
                $res->fetch();
                $res->close();
                
                // Delete image file if exists
                if ($image_path && file_exists($image_path)) {
                    unlink($image_path);
                }
                
                // Delete template
                $del = $mysqli->prepare("DELETE FROM birthday_templates WHERE template_id = ?");
                if (!$del) {
                    throw new Exception("Prepare failed: " . $mysqli->error);
                }
                
                $del->bind_param("i", $template_id);
                if ($del->execute()) {
                    redirect_with(['msg' => "Template '$template_name' deleted successfully", 'type' => 'warning']);
                } else {
                    throw new Exception("Execute failed: " . $del->error);
                }
                $del->close();
                
            } catch (Exception $e) {
                redirect_with(['msg' => 'Database error: ' . $e->getMessage(), 'type' => 'danger']);
            }
        }
    }
    
    if ($action === 'toggle_active') {
        $template_id = (int)($_POST['template_id'] ?? 0);
        
        if ($template_id > 0) {
            try {
                $res = $mysqli->prepare("SELECT is_active, template_name FROM birthday_templates WHERE template_id = ?");
                if (!$res) {
                    throw new Exception("Prepare failed: " . $mysqli->error);
                }
                
                $res->bind_param("i", $template_id);
                $res->execute();
                $res->bind_result($current_status, $template_name);
                $res->fetch();
                $res->close();
                
                $newStatus = $current_status ? 0 : 1;
                
                $upd = $mysqli->prepare("UPDATE birthday_templates SET is_active = ? WHERE template_id = ?");
                if (!$upd) {
                    throw new Exception("Prepare failed: " . $mysqli->error);
                }
                
                $upd->bind_param("ii", $newStatus, $template_id);
                if ($upd->execute()) {
                    $status_text = $newStatus ? 'activated' : 'deactivated';
                    redirect_with(['msg' => "Template '$template_name' $status_text", 'type' => 'info']);
                } else {
                    throw new Exception("Execute failed: " . $upd->error);
                }
                $upd->close();
                
            } catch (Exception $e) {
                redirect_with(['msg' => 'Database error: ' . $e->getMessage(), 'type' => 'danger']);
            }
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editTemplate = null;

if ($mode === 'edit') {
    $template_id = (int)($_GET['id'] ?? 0);
    if ($template_id > 0) {
        try {
            $stmt = $mysqli->prepare("SELECT template_id, template_name, wishes_text, image_path, text_color, is_active, created_date, changed_date FROM birthday_templates WHERE template_id = ?");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $mysqli->error);
            }
            
            $stmt->bind_param("i", $template_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $editTemplate = $result->fetch_assoc();
            $stmt->close();
        } catch (Exception $e) {
            // Error will be displayed in the template
        }
    }
    if (!$editTemplate) {
        $mode = 'view';
    }
}

// Get templates
try {
    $sql = "SELECT template_id, template_name, wishes_text, image_path, text_color, is_active, created_date, changed_date FROM birthday_templates ORDER BY created_date DESC";
    $templates = $mysqli->query($sql);
    if (!$templates) {
        throw new Exception("Query failed: " . $mysqli->error);
    }
} catch (Exception $e) {
    $templates = false;
}

// Get form data from session if exists
$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);

// Get today's birthdays for dashboard
try {
    $today = date('m-d');
    $birthdays_today = $mysqli->query("
        SELECT employee_id, employee_name, team_id 
        FROM employee_master 
        WHERE DATE_FORMAT(date_of_birth, '%m-%d') = '$today' 
        AND status = 'Active'
    ");
    if (!$birthdays_today) {
        throw new Exception("Query failed: " . $mysqli->error);
    }
} catch (Exception $e) {
    $birthdays_today = false;
}

require_once 'header.php'; // HTML header (optional, but kept for context)
?>
<br><br><br>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Birthday Templates Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .template-card {
            height: 300px;
            background-size: cover;
            background-position: center;
            position: relative;
        }
        .template-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .template-text {
            color: white;
            text-align: center;
            white-space: pre-wrap;
        }
        .color-preview {
            width: 30px;
            height: 30px;
            border-radius: 4px;
            display: inline-block;
            border: 2px solid #dee2e6;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <!-- Display any PHP errors at top -->
        <?php if (isset($e)): ?>
            <div class="alert alert-danger">
                <strong>PHP Error:</strong> <?= h($e->getMessage()) ?>
            </div>
        <?php endif; ?>

        <!-- Rest of your HTML content goes here -->
        <div class="row">
            <div class="col-12">
                <h1>Birthday Templates</h1>
                
                <?php if ($mode === 'add' || $mode === 'edit'): ?>
                    <!-- Add/Edit Form -->
                    <div class="card">
                        <div class="card-body">
                            <form method="post" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="<?= $mode === 'edit' ? 'edit_template' : 'add_template' ?>">
                                <?php if ($mode === 'edit'): ?>
                                    <input type="hidden" name="template_id" value="<?= $editTemplate['template_id'] ?>">
                                <?php endif; ?>
                                
                                <div class="mb-3">
                                    <label class="form-label">Template Name</label>
                                    <input type="text" name="template_name" class="form-control" 
                                           value="<?= h($editTemplate['template_name'] ?? $formData['template_name'] ?? '') ?>" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Wishes Text</label>
                                    <textarea name="wishes_text" class="form-control" rows="4" required><?= h($editTemplate['wishes_text'] ?? $formData['wishes_text'] ?? '') ?></textarea>
                                    <small class="text-muted">Use {employee_name} as placeholder</small>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Background Image</label>
                                    <input type="file" name="background_image" class="form-control" accept="image/*">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Text Color</label>
                                    <input type="color" name="text_color" class="form-control form-control-color"
                                           value="<?= h($editTemplate['text_color'] ?? $formData['text_color'] ?? '#FFFFFF') ?>">
                                </div>
                                
                                <div class="mb-3 form-check">
                                    <input type="checkbox" name="is_active" class="form-check-input" 
                                           <?= ($editTemplate['is_active'] ?? $formData['is_active'] ?? 1) ? 'checked' : '' ?>>
                                    <label class="form-check-label">Active Template</label>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">Save Template</button>
                                <a href="birthday_templates.php" class="btn btn-secondary">Cancel</a>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Template List -->
                    <div class="row">
                        <?php if ($templates && $templates->num_rows > 0): ?>
                            <?php while($template = $templates->fetch_assoc()): ?>
                                <div class="col-md-4 mb-4">
                                    <div class="card">
                                        <div class="template-card" 
                                             style="background-image: url('<?= $template['image_path'] ?>')">
                                                                                    
                                        </div>
                                         <b><div style="font-size:16px;color: <?= $template['text_color'] ?>">
                                                    <?= nl2br(h(str_replace('{employee_name}', 'Employee Name', $template['wishes_text']))) ?>
                                                </div></b>
    
                                        <div class="card-body">
                                            <h5><?= h($template['template_name']) ?></h5>
                                            <div class="btn-group">
                                                <a href="?mode=edit&id=<?= $template['template_id'] ?>" class="btn btn-success">Change Template</a>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="action" value="delete_template">
                                                    <input type="hidden" name="template_id" value="<?= $template['template_id'] ?>">
                                                    <!--button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this template?')">Delete</button-->
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="col-12">
                                <div class="alert alert-info">No templates found. <a href="?mode=add">Create the first template</a></div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!--a href="?mode=add" class="btn btn-success">Add New Template</a-->
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>