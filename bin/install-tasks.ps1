<#
.SYNOPSIS
  Installe (ou retire) les tâches planifiées quotidiennes d'ETHAN VRSC dans le Planificateur de tâches Windows.

.DESCRIPTION
  Trois tâches, dans le dossier « \ETHAN VRSC\ » du planificateur :
    - OT préventifs        05:00  bin\generate-preventive.php
    - Alertes e-mail       06:00  bin\send-alerts.php
    - Sauvegarde           23:00  bin\backup.php --verify
  Chaque passage est journalisé dans l'application (Administration > Tâches planifiées), qui signale
  une tâche en échec ou en retard. Une tâche manquée (serveur éteint) est rattrapée au démarrage suivant.

  À lancer dans une console PowerShell « Exécuter en tant qu'administrateur » : les tâches tournent alors
  sous le compte SYSTEM, même sans session ouverte. Sans droits administrateur, elles sont créées pour
  l'utilisateur courant et ne tournent que lorsqu'il est connecté (un avertissement le rappelle).

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File bin\install-tasks.ps1
  powershell -ExecutionPolicy Bypass -File bin\install-tasks.ps1 -List
  powershell -ExecutionPolicy Bypass -File bin\install-tasks.ps1 -Uninstall
  powershell -ExecutionPolicy Bypass -File bin\install-tasks.ps1 -PhpPath "D:\php\php.exe" -WhatIf
#>
[CmdletBinding()]
param(
    [string]$PhpPath = '',
    [switch]$Uninstall,
    [switch]$List,
    [switch]$WhatIf
)

$ErrorActionPreference = 'Stop'
$Folder = '\ETHAN VRSC\'
$Root = Split-Path -Parent $PSScriptRoot

# Mêmes tâches et heures que App\Services\ScheduledTaskService::TASKS.
$Tasks = @(
    @{ Name = 'OT preventifs';  Script = 'generate-preventive.php'; Args = '';         Time = '05:00' },
    @{ Name = 'Alertes e-mail'; Script = 'send-alerts.php';         Args = '';         Time = '06:00' },
    @{ Name = 'Sauvegarde';     Script = 'backup.php';              Args = '--verify'; Time = '23:00' }
)

if ($List) {
    Get-ScheduledTask -TaskPath $Folder -ErrorAction SilentlyContinue |
        ForEach-Object {
            $info = $_ | Get-ScheduledTaskInfo
            [pscustomobject]@{ Tache = $_.TaskName; Etat = $_.State; Dernier = $info.LastRunTime; Resultat = $info.LastTaskResult; Prochain = $info.NextRunTime }
        } | Format-Table -AutoSize
    exit 0
}

if ($Uninstall) {
    foreach ($t in $Tasks) {
        if (Get-ScheduledTask -TaskPath $Folder -TaskName $t.Name -ErrorAction SilentlyContinue) {
            if ($WhatIf) { Write-Host "[simulation] Suppression de $Folder$($t.Name)"; continue }
            Unregister-ScheduledTask -TaskPath $Folder -TaskName $t.Name -Confirm:$false
            Write-Host "Supprimée : $Folder$($t.Name)"
        }
    }
    exit 0
}

# PHP : paramètre, sinon XAMPP, sinon le PATH.
if (-not $PhpPath) {
    if (Test-Path 'C:\xampp\php\php.exe') { $PhpPath = 'C:\xampp\php\php.exe' }
    elseif (Get-Command php -ErrorAction SilentlyContinue) { $PhpPath = (Get-Command php).Source }
}
if (-not $PhpPath -or -not (Test-Path $PhpPath)) {
    throw "php.exe introuvable : précisez-le avec -PhpPath `"C:\chemin\php.exe`"."
}

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if ($isAdmin) {
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
} else {
    Write-Warning "Console non administrateur : les tâches ne tourneront que lorsque $env:USERNAME est connecté. Relancez en administrateur pour le compte SYSTEM."
    $principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive
}
# Rattrapage d'une exécution manquée, pas de double lancement, 2 h au plus.
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 2) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries

foreach ($t in $Tasks) {
    $scriptPath = Join-Path $Root ('bin\' + $t.Script)
    $arguments = ('"{0}" {1}' -f $scriptPath, $t.Args).Trim()
    if ($WhatIf) {
        Write-Host ("[simulation] {0}{1} : chaque jour à {2} -> {3} {4}" -f $Folder, $t.Name, $t.Time, $PhpPath, $arguments)
        continue
    }
    $action = New-ScheduledTaskAction -Execute $PhpPath -Argument $arguments -WorkingDirectory $Root
    $trigger = New-ScheduledTaskTrigger -Daily -At $t.Time
    Register-ScheduledTask -TaskPath $Folder -TaskName $t.Name -Action $action -Trigger $trigger -Principal $principal -Settings $settings `
        -Description ("ETHAN VRSC : $($t.Script) $($t.Args)".Trim()) -Force | Out-Null
    Write-Host ("Installée : {0}{1} (chaque jour à {2})" -f $Folder, $t.Name, $t.Time)
}
if (-not $WhatIf) {
    Write-Host "`nVérification : powershell -ExecutionPolicy Bypass -File bin\install-tasks.ps1 -List"
    Write-Host "Suivi dans l'application : Administration > Tâches planifiées."
}
