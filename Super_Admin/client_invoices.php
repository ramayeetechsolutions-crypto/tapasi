<?php
// ===============================================
// 1. DATABASE CONNECTION SETUP
// ===============================================
require_once 'db.php';
require_once 'header.php';

// ===============================================
// 2. FILE SYSTEM LISTING LOGIC
// ===============================================

// Set the base directory relative to index.php (assuming index.php is next to 'invoices/')
$upload_dir = 'invoices'; // No trailing slash here for cleaner path manipulation
$unique_invoices = [];

// Get search term from URL parameter
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

/**
* Recursively find all PDF files in directory and subdirectories
* $base_dir_len is used to efficiently calculate the relative path.
*/
function findPdfFilesRecursively($dir, &$results = [], $base_dir_len = 0) {
    if ($base_dir_len === 0) {
        // Calculate the length of the base directory path plus one for the directory separator
        $base_dir_len = strlen($dir) + 1;
    }

    $files = scandir($dir);
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $path = $dir . '/' . $file;
        
        if (is_dir($path)) {
            // Recursively search subdirectories, passing the base directory length
            findPdfFilesRecursively($path, $results, $base_dir_len);
        } elseif (is_file($path) && pathinfo($path, PATHINFO_EXTENSION) === 'pdf') {
            
            // Calculate relative path by stripping the base directory path
            $relative_path = substr($path, $base_dir_len); 
            $relative_path = ltrim($relative_path, '/'); // Ensure no leading slash

            // basename() for the folder name ensures we only get the last folder part
            $folder_path = dirname($relative_path);
            
            // Clean up the folder name to ensure we only group by the client/date part,
            // handling cases where the file might be in a complex or root path.
            $clean_folder_name = ($folder_path === '.') ? '' : basename($folder_path);

            $results[] = [
                'relative_path' => $relative_path,
                'filename' => basename($path),
                'folder' => $clean_folder_name
            ];
        }
    }
    
    return $results;
}

## A. Fetch from File System (Recursive Search)
if (is_dir($upload_dir)) {
    $pdf_files = findPdfFilesRecursively($upload_dir);

    foreach ($pdf_files as $file_info) {
        $unique_invoices[$file_info['relative_path']] = $file_info;
    }
}

// Group invoices by folder for better organization
$invoices_by_folder = [];
foreach ($unique_invoices as $invoice) {
    // The 'folder' key now holds the dynamic client folder name (e.g., 'ClientA_2024-10')
    $folder = $invoice['folder'] ?: 'Root Directory'; // Group files directly in 'invoices/' into a 'Root Directory' group
    
    if (!isset($invoices_by_folder[$folder])) {
        $invoices_by_folder[$folder] = [];
    }
    $invoices_by_folder[$folder][] = $invoice;
}

// Apply search filter (This logic remains correct)
if (!empty($search_term)) {
    $filtered_invoices_by_folder = [];
    foreach ($invoices_by_folder as $folder_name => $invoices) {
        // Search in folder name (case-insensitive)
        if (stripos($folder_name, $search_term) !== false) {
            $filtered_invoices_by_folder[$folder_name] = $invoices;
        } else {
            // Also search in filenames within the folder
            $filtered_files = [];
            foreach ($invoices as $invoice) {
                if (stripos($invoice['filename'], $search_term) !== false) {
                    $filtered_files[] = $invoice;
                }
            }
            if (!empty($filtered_files)) {
                $filtered_invoices_by_folder[$folder_name] = $filtered_files;
            }
        }
    }
    $invoices_by_folder = $filtered_invoices_by_folder;
}

// Sort folders alphabetically
ksort($invoices_by_folder);

// Count totals
$total_folders = count($invoices_by_folder);
$total_files = 0;
foreach ($invoices_by_folder as $invoices) {
    $total_files += count($invoices);
}

// We don't need the DB connection open for file operations, so close it
if (isset($mysqli) && $mysqli) {
    $mysqli->close();
}

// ===============================================
// 3. HTML DISPLAY
// ===============================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Super Admin: Available Invoice Files</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .search-section { background: #e7f3ff; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        .search-form { display: flex; gap: 10px; align-items: center; }
        .search-input { flex: 1; padding: 10px 15px; border: 2px solid #007bff; border-radius: 25px; font-size: 16px; outline: none; }
        .search-button { background: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 25px; cursor: pointer; font-size: 16px; }
        .clear-button { background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 25px; cursor: pointer; font-size: 16px; text-decoration: none; display: inline-block; }
        .folder-section { margin-bottom: 30px; padding: 15px; border: 1px solid #ddd; border-radius: 5px; background: #f9f9f9; }
        .folder-header { background: #707981; color: white; padding: 10px 15px; margin: -15px -15px 15px -15px; border-radius: 5px 5px 0 0; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
        .file-list { list-style: none; padding: 0; margin: 0; }
        .file-item { padding: 8px 0; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        .download-link { color: #007bff; text-decoration: none; display: flex; align-items: center; gap: 8px; }
        .highlight { background-color: yellow; font-weight: bold; }
        
        /* Send Invoice button styles */
        .send-invoice-btn { 
            background: #28a745; 
            color: white; 
            border: none; 
            padding: 5px 10px; 
            border-radius: 4px; 
            cursor: pointer; 
            font-size: 13px; 
            text-decoration: none;
            display: inline-block;
        }
        .send-invoice-btn:hover {
            background: #218838;
        }
        
        .journal-tag {
            display: inline-block; 
            width: 110px; 
            text-align: center; 
            color: white; 
            font-size: 12px; 
            background: linear-gradient(135deg, #ff4b2b, #ff416c); 
            padding: 2px 0px; 
            border-radius: 8px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
    </style>
    <script>
        function highlightText(text, searchTerm) {
            if (!searchTerm) return text;
            const regex = new RegExp(`(${searchTerm})`, 'gi');
            return text.replace(regex, '<span class="highlight">$1</span>');
        }

        document.addEventListener('DOMContentLoaded', function() {
            const searchTerm = '<?php echo addslashes($search_term); ?>';
            if (searchTerm) {
                // Highlight folder names
                document.querySelectorAll('.folder-name-text').forEach(span => {
                    span.innerHTML = highlightText(span.textContent, searchTerm);
                });

                // Highlight file names
                document.querySelectorAll('.download-link').forEach(link => {
                    const icon = link.querySelector('.file-icon');
                    const iconHtml = icon ? icon.outerHTML : '';
                    let originalText = link.textContent.trim();
                    if (icon) {
                        const iconText = icon.textContent;
                        originalText = originalText.substring(iconText.length).trim();
                    }
                    link.innerHTML = iconHtml + ' ' + highlightText(originalText, searchTerm);
                });
            }
        });
    </script>
</head>
<body>
    <div class="container">
        <br><br><br>
        <h1>📁 Client Completed Journals & Invoice</h1>

        <div class="search-section">
            <form method="GET" class="search-form">
                <input
                    type="text"
                    name="search"
                    class="search-input"
                    placeholder="Search by client name, folder name, or filename..."
                    value="<?php echo htmlspecialchars($search_term); ?>"
                >
                <button type="submit" class="search-button">🔍 Search</button>
                <?php if (!empty($search_term)): ?>
                    <a href="?" class="clear-button">❌ Clear Search</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="summary">
            <strong>Total Folders:</strong> <?php echo $total_folders; ?> |
            <strong>Total Files:</strong> <?php echo $total_files; ?>
            <?php if (!empty($search_term)): ?>
                | <strong>Search Results for:</strong> "<em><?php echo htmlspecialchars($search_term); ?></em>"
            <?php endif; ?>
        </div>

        <?php if (!empty($search_term) && empty($invoices_by_folder)): ?>
            <div class="search-results-info">
                <h3>🔍 No results found for "<?php echo htmlspecialchars($search_term); ?>"</h3>
                <p>Try searching with different terms or <a href="?">clear the search</a> to see all files.</p>
            </div>
        <?php elseif (empty($invoices_by_folder)): ?>
            <div class="empty-message">
                <p>No invoice files found in the system.</p>
                <p>Check if the **invoices/** directory exists and contains PDF files.</p>
            </div>
        <?php else: ?>
            <?php if (!empty($search_term)): ?>
                <div class="search-results-info">
                    <h3>🔍 Search Results for "<?php echo htmlspecialchars($search_term); ?>"</h3>
                    <p>Found <?php echo $total_folders; ?> folder(s) with <?php echo $total_files; ?> matching file(s).</p>
                </div>
            <?php endif; ?>

            <?php foreach ($invoices_by_folder as $folder_name => $invoices): ?>
                <div class="folder-section">
                    <div class="folder-header">
                        <span class="folder-name-text">
                            📁 <?php echo htmlspecialchars($folder_name); ?>
                        </span>
                          
                        <span>
                            <span style="font-size: 0.9em; margin-right: 15px;">
                                (<?php echo count($invoices); ?> file<?php echo count($invoices) !== 1 ? 's' : ''; ?>)
                            </span>
                        </span>
                    </div>
                    

                    <ul class="file-list">
                        <?php foreach ($invoices as $invoice): ?>
                            <li class="file-item">
                                <div>
                                    <a href="download.php?file=<?php echo urlencode($invoice['relative_path']); ?>" class="download-link" target="_blank">
                                        <span class="file-icon">📄</span>
                                        <?php echo htmlspecialchars($invoice['filename']); ?>
                                    </a>
                                </div>

                                <?php
                                // --- CONDITIONAL LOGIC: ONLY DISPLAY BUTTON IF FILENAME STARTS WITH 'INV' ---
                                if (str_starts_with($invoice['filename'], 'INV')) {
                                ?>
                                    <a href="send_client_invoices.php?file=<?php echo urlencode($invoice['relative_path']); ?>&client=<?php echo urlencode($folder_name); ?>" 
                                       class="send-invoice-btn" 
                                       onclick="return confirm('Send the file <?php echo addslashes($invoice['filename']); ?> to client <?php echo addslashes($folder_name); ?>?');">
                                        ✉️ Send Invoice
                                    </a>
                                <?php 
                                } else {
                                    // Display Journal tag for non-invoice files
                                    echo '<b><span class="journal-tag">Journal</span></b>';
                                }
                                ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>