<?php
class RD_Algo_Bat_Gen {
    private $opts;

    public function __construct() {
        $this->opts = get_option('rd_algo_settings', []);
    }

    public function init() {
        // Intercept frontend request early (init hook) to bypass LoginPress/Admin restrictions
        add_action('init', [$this, 'handle_frontend_download'], 1);
    }

    /**
     * 1. Generate Unique Link
     * Returns a fresh URL with a random token every time.
     */
    public function create_one_time_link($type, $id) {
        $token = wp_generate_password(64, false);
        // Token valid for 2 hours
        set_transient('rd_bat_' . $token, ['type' => $type, 'id' => $id], 2 * HOUR_IN_SECONDS);
        
        $slug = $this->opts['download_slug'] ?? 'rd-install';
        // Add unique timestamp to URL to force browser to fetch a new file
        return home_url('/' . $slug . '/?token=' . $token . '&_t=' . time());
    }

    /**
     * 2. Handle Request
     */
    public function handle_frontend_download() {
        if (!isset($_GET['token'])) return;

        $slug = $this->opts['download_slug'] ?? 'rd-install';
        $req_uri = $_SERVER['REQUEST_URI'];
        
        // Clean URL parsing
        $path = parse_url($req_uri, PHP_URL_PATH);
        $path = trim($path, '/');
        $slug = trim($slug, '/');

        if ($path === $slug) {
            $this->serve_bat_file($_GET['token']);
        }
    }

    private function serve_bat_file($token) {
        // Clear buffers to prevent file corruption
        while (ob_get_level()) { ob_end_clean(); }

        $token = sanitize_text_field($token);
        $data = get_transient('rd_bat_' . $token);

        if (!$data) {
            wp_die('<b>Link Expired.</b><br>Please generate a new link.', 'Error', ['response' => 410]);
        }

        // STRICT ONE-TIME USE: Delete token immediately
        delete_transient('rd_bat_' . $token); 

        if ($data['type'] === 'mt4_install') {
            $this->generate_mt4_installer($data['id']);
        } elseif ($data['type'] === 'vps_connect') {
            $this->generate_vps_connector($data['id']);
        }
        exit;
    }

    /**
     * 3. Generate MT4 Script (Self-Deleting, Auto-Login)
     */
    private function generate_mt4_installer($mt4_login) {
        global $wpdb;
        $table = $this->opts['tb_mt4'] ?? 'wp_mt4_user_records';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE mt4userid = %s", $mt4_login));

        if (!$row) wp_die('MT4 User Data Not Found');

        $zip_url = $this->opts['mt4_zip_url'] ?? '';
        if (empty($zip_url)) wp_die('Installer ZIP URL not configured.');

        // Data for Injection
        $login = trim($row->mt4userid);
        $pass  = trim($row->mt4password);
        $server = trim($row->mt4servername);
        $filename = "Install_MT4_{$login}.bat";

        // --- POLYGLOT BATCH/POWERSHELL SCRIPT ---
        // 1. Valid Batch Header that hides itself inside <# ... #> comments for PowerShell.
        // 2. Uses 'ping' for delay (Works on all Windows versions, unlike timeout).
        // 3. Deletes itself in a background process.
        
$script = <<<EOD
<# :
@echo off
title RdAlgo Installer
color 0A
set "self=%~f0"
:: Run PowerShell logic using the content of this file
powershell -NoProfile -ExecutionPolicy Bypass -Command "iex ([System.IO.File]::ReadAllText('%self%'))"
:: SELF DESTRUCT: Wait 3 sec (using ping for compatibility), then delete this file
start /b "" cmd /c "ping 127.0.0.1 -n 3 > nul & del "%self%""
exit /b
#>

# -----------------------------------------------------------
# POWERSHELL INSTALLER LOGIC
# -----------------------------------------------------------
\$ErrorActionPreference = 'Stop'

# --- CREDENTIALS INJECTED FROM DB ---
\$Login = '$login'
\$Pass  = '$pass'
\$Serv  = '$server'
\$Url   = '$zip_url'

# Clean Inputs
\$Login = \$Login.Trim()
\$Pass  = \$Pass.Trim()
\$Serv  = \$Serv.Trim()

\$ZipPath = "\$env:TEMP\\MT4_Install.zip"
\$InstallDir = "C:\\RdAlgo_MT4"

Write-Host "1. Initializing..." -ForegroundColor Cyan

# --- DOWNLOAD ---
Write-Host "2. Downloading..." -ForegroundColor Green
try {
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    \$wc = New-Object System.Net.WebClient
    \$wc.Headers.Add("User-Agent", "Mozilla/5.0")
    \$wc.DownloadFile(\$Url, \$ZipPath)
} catch {
    Write-Host "   WebClient failed. Trying CertUtil..." -ForegroundColor Yellow
    \$result = cmd /c "certutil -urlcache -split -f `"\$Url`" `"\$ZipPath`" > nul 2>&1"
}

if (!(Test-Path \$ZipPath) -or (Get-Item \$ZipPath).Length -lt 1000) {
    Write-Host "ERROR: Download Failed. Check URL/Firewall." -ForegroundColor Red
    Start-Sleep -s 5
    exit
}

# --- EXTRACT (Using Shell.Application for RDP compatibility) ---
Write-Host "3. Installing..." -ForegroundColor Green
Get-Process terminal -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Sleep -s 1

# Handle existing folder
if (Test-Path \$InstallDir) {
    \$Trash = "\$InstallDir.old_\$(Get-Random)"
    try {
        Move-Item \$InstallDir \$Trash -Force -ErrorAction Stop
        Remove-Item \$Trash -Recurse -Force -ErrorAction SilentlyContinue
    } catch {
        Write-Host "   Overwriting files..." -ForegroundColor Gray
    }
}

New-Item -Path \$InstallDir -ItemType Directory -Force | Out-Null

\$shell = New-Object -ComObject Shell.Application
\$zip = \$shell.NameSpace(\$ZipPath)
\$dest = \$shell.NameSpace(\$InstallDir)
\$dest.CopyHere(\$zip.Items(), 16)
Start-Sleep -s 2

# --- AUTO-LOGIN CONFIGURATION ---
Write-Host "4. Configuring Auto-Login..." -ForegroundColor Cyan
\$TerminalExe = Get-ChildItem -Path \$InstallDir -Filter "terminal.exe" -Recurse | Select-Object -First 1

if (!\$TerminalExe) {
    Write-Host "Error: terminal.exe not found!" -ForegroundColor Red
    Start-Sleep -s 5
    exit
}

\$ConfigDir = Join-Path \$TerminalExe.DirectoryName "config"
if (!(Test-Path \$ConfigDir)) { New-Item -Path \$ConfigDir -ItemType Directory | Out-Null }
\$IniFile = Join-Path \$ConfigDir "startup.ini"

# Inject Credentials into INI file
\$IniContent = @"
;Common
Profile=default
Login=\$Login
Password=\$Pass
Server=\$Serv
EnableNews=false
"@
Set-Content -Path \$IniFile -Value \$IniContent -Encoding Ascii

# --- SHORTCUT ---
Write-Host "5. Creating Shortcut..." -ForegroundColor Green
\$WshShell = New-Object -ComObject WScript.Shell
\$DesktopPath = [Environment]::GetFolderPath('Desktop') + '\\RdAlgo MT4.lnk'
\$Shortcut = \$WshShell.CreateShortcut(\$DesktopPath)
\$Shortcut.TargetPath = \$TerminalExe.FullName
\$Shortcut.WorkingDirectory = \$TerminalExe.DirectoryName
# Add portable flag and config path
\$Shortcut.Arguments = "/portable `"\$IniFile`""
\$Shortcut.WindowStyle = 3

\$Icon = Get-ChildItem -Path \$InstallDir -Filter "terminal.ico" -Recurse | Select-Object -First 1
if (\$Icon) { \$Shortcut.IconLocation = \$Icon.FullName } else { \$Shortcut.IconLocation = \$TerminalExe.FullName }

\$Shortcut.Save()

# --- LAUNCH ---
Write-Host "SUCCESS! Launching..." -ForegroundColor Cyan
Invoke-Item \$DesktopPath
Start-Sleep -s 1
EOD;

        $this->force_download($filename, $script);
    }

    private function generate_vps_connector($host_name) {
        global $wpdb;
        $table = $this->opts['tb_vps'] ?? 'wp_vps_records';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE host_name = %s", $host_name));

        if (!$row) wp_die('VPS Not Found');

        $filename = "Connect_{$row->host_name}.bat";
        // Escape special batch characters
        $safe_pass = str_replace(['%', '^', '&', '<', '>', '|'], ['%%', '^^', '^&', '^<', '^>', '^|'], $row->vps_password);

        $script  = "@echo off\r\n";
        $script .= "echo Connecting to {$row->vps_ip}...\r\n";
        $script .= "cmdkey /delete:TERMSRV/{$row->vps_ip}\r\n";
        $script .= "cmdkey /generic:TERMSRV/{$row->vps_ip} /user:{$row->vps_user_id} /pass:{$safe_pass}\r\n";
        $script .= "start mstsc /v:{$row->vps_ip}\r\n";
        
        // SELF DESTRUCT (Universal Ping Method)
        $script .= "start /b \"\" cmd /c \"ping 127.0.0.1 -n 3 > nul & del \"%~f0\"\"\r\n";
        $script .= "exit\r\n";

        $this->force_download($filename, $script);
    }

    private function force_download($filename, $content) {
        // Force Windows-style Line Endings (CRLF) for Batch compatibility
        $content = preg_replace('~\r\n?~', "\n", $content);
        $content = str_replace("\n", "\r\n", $content);

        if (!headers_sent()) {
            nocache_headers();
            header('Content-Type: application/x-bat');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Transfer-Encoding: binary');
            header('Content-Length: ' . strlen($content));
        }
        echo $content;
        exit;
    }
}