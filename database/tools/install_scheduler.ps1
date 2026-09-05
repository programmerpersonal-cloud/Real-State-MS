<#
.SYNOPSIS
    Register (or remove) the Windows scheduled task that runs Saxane's backup
    scheduler.

.DESCRIPTION
    Automatic backups do not happen because a schedule row says they should.
    They happen because something runs database\tools\run_backups.php on a
    timer. On this platform that something is a Windows Task Scheduler entry,
    and this script is the one place it is defined.

    ONE task, not one per schedule. The runner reads backup_schedules on every
    tick and decides for itself what is due, so daily, weekly and monthly all
    fire from the same five-minute heartbeat. Creating a task per schedule
    would put the scheduling logic in two places and let them disagree.

    The task is deliberately configured to:

      * repeat indefinitely            a duration would silently expire, and a
                                       backup scheduler that stops after a day
                                       is worse than none because the dashboard
                                       would go on looking healthy for a while
      * StartWhenAvailable             the machine is not always on. A tick
                                       missed at 03:00 because the laptop was
                                       shut runs as soon as it is back, and the
                                       runner then catches up the overdue
                                       schedule rather than skipping the night
      * IgnoreNew                      two ticks must not overlap. The runner
                                       takes its own lock as well, so this is
                                       the belt to that pair of braces
      * run whether or not a user is
        logged on (SYSTEM by default)  a backup that needs somebody signed in
                                       is a backup that stops the first time
                                       the server reboots at night
      * ExecutionTimeLimit 2h          a wedged run is terminated rather than
                                       blocking every later tick forever

.PARAMETER IntervalMinutes
    How often to tick. Default 5. Anything from 1 to 60 is sensible; the
    runner costs a handful of indexed queries when nothing is due.

.PARAMETER PhpExe
    Full path to php.exe. Auto-detected from the XAMPP layout when omitted.

.PARAMETER RunAsCurrentUser
    Register the task under the current account instead of SYSTEM. Use when
    the backup destination is on a share only that account can reach. The task
    then runs only while that user is logged on unless a password is stored.

.PARAMETER Uninstall
    Remove the task instead of creating it.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File install_scheduler.ps1
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File install_scheduler.ps1 -IntervalMinutes 15
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File install_scheduler.ps1 -Uninstall
#>

[CmdletBinding()]
param(
    [ValidateRange(1, 60)]
    [int]    $IntervalMinutes = 5,
    [string] $PhpExe,
    [switch] $RunAsCurrentUser,
    [switch] $Uninstall
)

$ErrorActionPreference = 'Stop'

# Kept in step with BACKUP_TASK_NAME in config/app.php, which is what the
# --doctor command looks for. If one changes, change both.
$TaskName = 'Saxane Backup Scheduler'

# Everything is resolved from this script's own location, never from the
# working directory: Task Scheduler starts in C:\Windows\System32 and an
# installer that only works when run from the project folder is an installer
# that works once.
$ToolsDir    = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectRoot = (Resolve-Path (Join-Path $ToolsDir '..\..')).Path
$Runner      = Join-Path $ProjectRoot 'database\tools\run_backups.php'

function Fail([string] $message) {
    Write-Host ''
    Write-Host "  ERROR: $message" -ForegroundColor Red
    Write-Host ''
    exit 1
}

# ── Administrator ──────────────────────────────────────────────────────
# Elevation is needed to register a task that runs as SYSTEM, and only for
# that. Registering one under the current account is something the account can
# already do, so an unelevated run is offered the -RunAsCurrentUser form rather
# than being turned away — a backup that runs while somebody is logged in is
# worth a great deal more than no backup at all, and it can be upgraded later.
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$isAdmin  = ([Security.Principal.WindowsPrincipal] $identity).IsInRole(
                [Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $isAdmin -and -not $RunAsCurrentUser -and -not $Uninstall) {
    Write-Host ''
    Write-Host '  Not running as Administrator.' -ForegroundColor Yellow
    Write-Host '  Registering the task under SYSTEM - which lets backups run when nobody is'
    Write-Host '  signed in — needs elevation. Either:'
    Write-Host ''
    Write-Host '    right-click install_scheduler.bat and choose "Run as administrator"   (recommended)'
    Write-Host ''
    Write-Host '  or install it under your own account, which only runs while you are signed in:'
    Write-Host ''
    Write-Host '    powershell -ExecutionPolicy Bypass -File install_scheduler.ps1 -RunAsCurrentUser'
    Write-Host ''
    exit 1
}
if (-not $isAdmin -and $Uninstall) {
    # Removing a task registered under this account needs no elevation; one
    # registered as SYSTEM does. Let Unregister-ScheduledTask say which.
    Write-Host '  Not elevated — this can only remove a task registered under your own account.' -ForegroundColor Yellow
}

# ── Uninstall ──────────────────────────────────────────────────────────
if ($Uninstall) {
    $existing = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if ($null -eq $existing) {
        Write-Host "  '$TaskName' is not registered. Nothing to remove." -ForegroundColor Yellow
        exit 0
    }
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    Write-Host ''
    Write-Host "  Removed '$TaskName'." -ForegroundColor Green
    Write-Host '  Automatic backups will no longer run. Schedules stay configured.' -ForegroundColor Yellow
    Write-Host ''
    exit 0
}

# ── php.exe ────────────────────────────────────────────────────────────
# Walk up from the project looking for a sibling php\php.exe, which is the
# XAMPP layout (D:\XAMPP\php\php.exe beside D:\XAMPP\htdocs\...), then fall
# back to the usual install locations and finally to PATH. Detected rather
# than hard-coded so this works on a machine where XAMPP is not on D:.
if (-not $PhpExe) {
    $candidates = @()
    $cursor = $ProjectRoot
    for ($i = 0; $i -lt 5 -and $cursor; $i++) {
        $cursor = Split-Path -Parent $cursor
        if ($cursor) { $candidates += (Join-Path $cursor 'php\php.exe') }
    }
    $candidates += 'D:\XAMPP\php\php.exe', 'C:\xampp\php\php.exe', 'C:\Program Files\php\php.exe'

    $PhpExe = $candidates | Where-Object { $_ -and (Test-Path $_ -PathType Leaf) } | Select-Object -First 1

    if (-not $PhpExe) {
        $onPath = Get-Command php.exe -ErrorAction SilentlyContinue
        if ($onPath) { $PhpExe = $onPath.Source }
    }
}

if (-not $PhpExe -or -not (Test-Path $PhpExe -PathType Leaf)) {
    Fail "Could not find php.exe. Pass it explicitly:  -PhpExe `"D:\XAMPP\php\php.exe`""
}
if (-not (Test-Path $Runner -PathType Leaf)) {
    Fail "The backup runner is missing: $Runner"
}

# ── Prove it works before scheduling it ────────────────────────────────
# A task that was registered successfully and fails on every tick looks
# identical from the Task Scheduler window to one that is working, so the
# runner is exercised before it is scheduled. --check judges capability only:
# "no scheduled task is registered" is true at this moment and is the very
# thing about to be fixed, so it must not read as a failure here.
Write-Host ''
Write-Host '  Checking the runner before scheduling it...' -ForegroundColor Cyan
# No stderr redirection: Windows PowerShell wraps a native command's stderr in
# an ErrorRecord and would report a clean exit 0 as a failure.
& $PhpExe $Runner --doctor --check --quiet | Out-Null
$doctor = $LASTEXITCODE

if ($doctor -ne 0) {
    Write-Host ''
    Write-Host '  The runner cannot produce a backup on this machine. Full diagnosis:' -ForegroundColor Red
    Write-Host ''
    & $PhpExe $Runner --doctor
    Fail 'Fix the problems above, then run this installer again.'
}
Write-Host '  Runner OK - it can produce a backup on this machine.' -ForegroundColor Green

# ── Register ───────────────────────────────────────────────────────────
# --quiet because Task Scheduler discards stdout anyway; the runner's own log
# at <BACKUP_PATH>\logs\scheduler.log is the record that survives, and stderr
# still carries failures for anything watching the exit code.
$action = New-ScheduledTaskAction -Execute $PhpExe `
                                  -Argument ('"{0}" --quiet' -f $Runner) `
                                  -WorkingDirectory $ProjectRoot

# A repetition with no duration repeats forever. Anchoring the start to
# midnight today keeps the tick boundaries predictable.
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).Date `
                                    -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes)

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Hours 2) `
    -RestartCount 2 -RestartInterval (New-TimeSpan -Minutes 5)

if ($RunAsCurrentUser) {
    # RunLevel follows the shell: asking for Highest from an unelevated session
    # registers a task Windows will refuse to start.
    $level     = if ($isAdmin) { 'Highest' } else { 'Limited' }
    $principal = New-ScheduledTaskPrincipal -UserId $identity.Name -LogonType Interactive -RunLevel $level
    $who = $identity.Name + ' (only while signed in)'
} else {
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $who = 'SYSTEM (whether or not anyone is signed in)'
}

if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Write-Host "  Replacing the existing '$TaskName' task." -ForegroundColor Yellow
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

Register-ScheduledTask -TaskName $TaskName `
    -Action $action -Trigger $trigger -Settings $settings -Principal $principal `
    -Description ("Runs the Saxane backup scheduler every $IntervalMinutes minutes. " +
                  "The runner decides which backup schedules are due; this task only ticks. " +
                  "Log: run `"$PhpExe`" `"$Runner`" --log") | Out-Null

# Run it once now, so the heartbeat exists and the dashboard stops saying the
# scheduler has never run before the first tick would have arrived.
Start-ScheduledTask -TaskName $TaskName

Write-Host ''
Write-Host '  ------------------------------------------------------------' -ForegroundColor Green
Write-Host "  Installed: $TaskName" -ForegroundColor Green
Write-Host '  ------------------------------------------------------------'
Write-Host "  Program    : $PhpExe"
Write-Host "  Arguments  : `"$Runner`" --quiet"
Write-Host "  Start in   : $ProjectRoot"
Write-Host "  Every      : $IntervalMinutes minutes, indefinitely"
Write-Host "  Runs as    : $who"
Write-Host ''
Write-Host '  Check it:'
Write-Host "    `"$PhpExe`" `"$Runner`" --doctor"
Write-Host "    `"$PhpExe`" `"$Runner`" --log"
Write-Host ''
Write-Host '  Remove it:'
Write-Host '    install_scheduler.bat /uninstall   (as administrator)'
Write-Host ''
exit 0
