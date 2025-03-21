<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $github_repo = "https://github.com/Waquarn/Forms/releases/download/Beta/forms.zip";
    $zip_file = "forms.zip";
    $extract_dir = __DIR__;

    function sendResponse($success, $message) {
        echo json_encode(['success' => $success, 'message' => $message]);
        exit;
    }

    function checkPermissions($file) {
        $perms = fileperms($file);
        if (($perms & 0777) !== 0777) {
            return false;
        }
        return true;
    }

    function setPermissions($file) {
        if (!chmod($file, 0777)) {
            return false;
        }
        return true;
    }

    try {
        $files = scandir($extract_dir);
        foreach ($files as $file) {
            if ($file !== "." && $file !== "..") {
                if (!checkPermissions($file)) {
                    if (!setPermissions($file)) {
                        sendResponse(false, "Failed to set 777 permissions for file: $file, set permissions for file $file to '777'");
                    }
                    sendResponse(false, "File $file did not have 777 permissions. Permissions set to 777. Please refresh the page.");
                }
            }
        }

        if (file_exists($zip_file)) {
            unlink($zip_file) or sendResponse(false, "Failed to delete existing ZIP file.");
        }

        $zip_content = @file_get_contents($github_repo);
        if ($zip_content === false) {
            sendResponse(false, "Failed to download ZIP file. Check the URL or server settings (allow_url_fopen).");
        }

        if (!file_put_contents($zip_file, $zip_content)) {
            sendResponse(false, "Failed to save the downloaded ZIP file.");
        }

        if (!class_exists('ZipArchive')) {
            sendResponse(false, "PHP ZipArchive extension is not installed on the server.");
        }

        $zip = new ZipArchive();
        $open_result = $zip->open($zip_file);
        if ($open_result !== true) {
            $error_msg = "Failed to open ZIP file. Error code: $open_result";
            switch ($open_result) {
                case ZipArchive::ER_EXISTS: $error_msg .= " (File already exists)"; break;
                case ZipArchive::ER_INCONS: $error_msg .= " (Inconsistent ZIP file)"; break;
                case ZipArchive::ER_INVAL: $error_msg .= " (Invalid argument)"; break;
                case ZipArchive::ER_MEMORY: $error_msg .= " (Memory allocation failure)"; break;
                case ZipArchive::ER_NOENT: $error_msg .= " (File not found)"; break;
                case ZipArchive::ER_NOZIP: $error_msg .= " (Not a ZIP archive)"; break;
                case ZipArchive::ER_OPEN: $error_msg .= " (Can't open file)"; break;
                case ZipArchive::ER_READ: $error_msg .= " (Read error)"; break;
                case ZipArchive::ER_SEEK: $error_msg .= " (Seek error)"; break;
                default: $error_msg .= " (Unknown error)";
            }
            sendResponse(false, $error_msg);
        }

        if (!$zip->extractTo($extract_dir)) {
            $zip->close();
            sendResponse(false, "Failed to extract ZIP file contents.");
        }

        $zip->close();
        if (!unlink($zip_file)) {
            sendResponse(false, "Failed to delete temporary ZIP file.");
        }

        sendResponse(true, "Process completed successfully");
    } catch (Exception $e) {
        sendResponse(false, "Unexpected error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>GitHub Downloader</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background-color: #f0f4f8;
            color: #333;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            flex-direction: column;
        }

        h1 {
            color: #2c3e50;
            margin-bottom: 20px;
        }

        #messages {
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
        }

        .message {
            opacity: 0;
            margin: 10px 0;
            padding: 15px;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: opacity 0.5s ease-in-out, transform 0.5s ease-in-out;
            transform: translateY(20px);
        }

        .message.show {
            opacity: 1;
            transform: translateY(0);
        }

        .error {
            background: #ffebee;
            color: #c62828;
            border-left: 4px solid #c62828;
        }

        .success {
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #2e7d32;
        }

        .info {
            background: #e3f2fd;
            color: #1565c0;
            border-left: 4px solid #1565c0;
        }

        .loading {
            background: #fff3e0;
            color: #ef6c00;
            border-left: 4px solid #ef6c00;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading::after {
            content: '';
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #ef6c00;
            border-top: 2px solid transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <h1>GitHub Repository Downloader</h1>
    <div id="messages"></div>

    <script>
        const messagesDiv = document.getElementById('messages');

        async function showMessage(text, type = 'info') {
            const msg = document.createElement('p');
            msg.textContent = text;
            msg.className = `message ${type}`;
            messagesDiv.appendChild(msg);
            
            await new Promise(resolve => setTimeout(resolve, 100));
            msg.classList.add('show');
            return new Promise(resolve => setTimeout(resolve, 1000));
        }

        async function processDownload() {
            try {
                await showMessage("Checking permissions...", 'loading');

                const response = await fetch('<?php echo basename(__FILE__); ?>', {
                    method: 'POST'
                });
                const result = await response.json();

                if (!result.success) {
                    if (result.message.includes("Permissions set to 777")) {
                        await showMessage("Permissions were not 777. Setting permissions to 777...", 'info');
                        await showMessage("Permissions set to 777. Refreshing page...", 'info');
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                        return;
                    }
                    throw new Error(result.message);
                }

                await showMessage("Download successful, file saved", 'success');
                await showMessage("Extracting files...", 'loading');
                await showMessage("Extraction successful", 'success');
                await showMessage("Cleaning up temporary files...", 'loading');
                await showMessage("Redirecting to setup page...", 'info');
                
                setTimeout(() => {
                    window.location.href = "index.php?page=setup";
                }, 1000);
            } catch (error) {
                await showMessage(`Error: ${error.message}`, 'error');
            }
        }

        processDownload();
    </script>
</body>
</html>
