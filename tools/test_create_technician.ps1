$base = "http://localhost/Smart-ISP-Business-Operations-Management-System-main/Smart-ISP-Business-Operations-Management-System-main"
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

$loginBody = @{ email = "admin@promee.local"; password = "Admin@12345" } | ConvertTo-Json
$loginRes = Invoke-WebRequest -UseBasicParsing -Uri "$base/backend/public/auth/login.php" -Method POST -WebSession $session -ContentType "application/json" -Body $loginBody
$loginJson = $loginRes.Content | ConvertFrom-Json
if (-not $loginJson.ok) {
  Write-Output "login_failed"
  Write-Output $loginRes.Content
  exit 1
}

$nextRes = Invoke-WebRequest -UseBasicParsing -Uri "$base/backend/public/employees/next_employee_code_add_employee.php" -Method GET -WebSession $session
$nextJson = $nextRes.Content | ConvertFrom-Json
$newCode = [string]$nextJson.next_employee_code

$posRes = Invoke-WebRequest -UseBasicParsing -Uri "$base/backend/public/employees/list_positions_add_employee.php" -Method GET -WebSession $session
$posJson = $posRes.Content | ConvertFrom-Json
$pos = $posJson.positions | Where-Object { $_.department -eq "Support" -and $_.name -eq "Technician" } | Select-Object -First 1
if ($null -eq $pos) {
  Write-Output "position_not_found"
  exit 1
}

$moduleMap = @{}
foreach ($p in $pos.module_permissions.PSObject.Properties) {
  $moduleMap[$p.Name] = [string]$p.Value
}

$selectedAccess = @()
foreach ($kv in $moduleMap.GetEnumerator()) {
  if ($kv.Value -eq "full" -or $kv.Value -eq "view" -or $kv.Value -eq "limited") {
    $selectedAccess += $kv.Key
  }
}

$payload = @{
  employeeId = $newCode
  employeeFirstName = "Test"
  employeeLastName = "Technician"
  employeeDesignation = "Technician"
  employeeDepartment = "Support"
  employeeJoiningDate = "2026-04-11"
  employeeStatus = "Active"
  employeeGender = "Male"
  employeeNid = ([string](3000000000000 + [int]$newCode))
  employeeDob = "1996-05-15"
  employeeBloodGroup = "B+"
  employeeType = "Permanent"
  employeePhone = "01795555555"
  employeeEmergencyName = "Md. Karim"
  employeeEmergencyPhone = "01890000000"
  employeeMail = "test.technician.$newCode@promee.internet"
  employeePassword = "123456"
  employeeManager = "Support Manager"
  employeeSalary = 25000
  employeeHouseAllowance = 5000
  employeeMedicalAllowance = 2000
  employeeTransportAllowance = 1500
  employeeBankName = "BRAC Bank"
  employeeBankAccount = ([string](500000000000 + [int]$newCode))
  employeeEducation = "Diploma"
  employeeExperience = 3
  employeePresentAddress = "Mirpur, Dhaka"
  employeePermanentAddress = "Cumilla, Bangladesh"
  employeeSkills = "Fiber, Support"
  employeeNotes = "API test employee"
  employeeAccess = $selectedAccess
  employeeAccessPermissions = $moduleMap
} | ConvertTo-Json -Depth 8

$createRes = Invoke-WebRequest -UseBasicParsing -Uri "$base/backend/public/employees/create.php" -Method POST -WebSession $session -ContentType "application/json" -Body $payload
Write-Output $createRes.Content
$createJson = $createRes.Content | ConvertFrom-Json

if ($createJson.ok -and $createJson.employee.id) {
  php "tools/inspect_employee.php" ([int]$createJson.employee.id)
}
