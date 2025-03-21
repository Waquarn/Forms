# Forms - A Simple Form Management Application

This is a PHP-based form management application created by Waquarn. It allows users to create, edit, fill out, and manage responses to forms through a simple, user-friendly interface. The application supports multilingual functionality, file uploads, and administrative privileges.

## Key Features
- **Form Creation and Editing**: Short and long text answers, radio buttons, checkboxes, dropdown menus.
- **Multilingual Support**: Dynamic language selection based on JSON files in the `languages/` directory.
- **File Uploads**: Attach files to questions (supports PNG, JPG, WebP, MP4; max 2MB).
- **User Management**: Admin users can create and manage other users.
- **Response Management**: View, export (CSV), and delete responses.
- **Setup Wizard**: Easy configuration during initial setup with database and site settings.

## Requirements
- PHP 7.0 or higher
- MySQL database
- PHP extensions: `pdo_mysql`, `zip` (for the downloader), `mbstring`
- Web server (e.g., Apache, Nginx)
- Composer (for dependency management)

## Installation

### Option 1: Using the Downloader
1. Download the `index.php` downloader file from this repository.
2. Upload it to your web server.
3. Open it in your browser (e.g., `http://yourserver.com/index.php`).
4. The script will automatically download the latest version from GitHub, extract it, and redirect you to the setup page.
5. Follow the setup instructions below.

### Option 2: Manual Installation
1. Clone or download this repository:  
