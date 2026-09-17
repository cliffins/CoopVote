param(
    [ValidateSet('Prepare','CreateVoters','CreateElections','CreateCandidates','Activate','VoteMain','VoteResults','Publish','Verify','Finish','Inspect')]
    [string]$Action='Prepare',
    [int]$Start=1,
    [int]$End=100
)
$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
$dataDir=Join-Path $root 'database\test-data'
$statePath=Join-Path $dataDir 'state.json'
$base='http://127.0.0.1:8095/'
$debug='http://127.0.0.1:9341/'
$utf8=[System.Text.UTF8Encoding]::new($false)
function Save-State { [System.IO.File]::WriteAllText($statePath,($script:state|ConvertTo-Json -Depth 30),$utf8) }
function Json($v) { ConvertTo-Json -InputObject $v -Compress -Depth 20 }
function Cdp([string]$method,$parameters=@{}) {
    $script:messageId++
    $id=$script:messageId
    $payload=Json @{id=$id;method=$method;params=$parameters}
    $bytes=[System.Text.Encoding]::UTF8.GetBytes($payload)
    $cts=[System.Threading.CancellationTokenSource]::new(20000)
    try {
        $script:ws.SendAsync([ArraySegment[byte]]::new($bytes),[System.Net.WebSockets.WebSocketMessageType]::Text,$true,$cts.Token).GetAwaiter().GetResult()
        while ($true) {
            $builder=[System.Text.StringBuilder]::new()
            do {
                $buf=New-Object byte[] 65536
                $received=$script:ws.ReceiveAsync([ArraySegment[byte]]::new($buf),$cts.Token).GetAwaiter().GetResult()
                if ($received.MessageType -eq [System.Net.WebSockets.WebSocketMessageType]::Close) { throw 'Browser connection closed' }
                [void]$builder.Append([System.Text.Encoding]::UTF8.GetString($buf,0,$received.Count))
            } while (!$received.EndOfMessage)
            $msg=$builder.ToString()|ConvertFrom-Json
            if ($msg.method -eq 'Runtime.exceptionThrown') {
                $script:browserErrors.Add($msg.params.exceptionDetails.exception.description)
            }
            if ($msg.id -eq $id) {
                if ($msg.error) { throw (Json $msg.error) }
                return $msg
            }
        }
    } finally { $cts.Dispose() }
}
function Js([string]$expression) {
    $r=Cdp 'Runtime.evaluate' @{expression=$expression;returnByValue=$true;awaitPromise=$true}
    if ($r.result.exceptionDetails) { throw ($r.result.exceptionDetails.exception.description) }
    return $r.result.result.value
}
function Wait-Js([string]$expression,[int]$seconds=20) {
    $deadline=(Get-Date).AddSeconds($seconds)
    do {
        try { if (Js $expression) { return } } catch { }
        Start-Sleep -Milliseconds 100
    } while ((Get-Date) -lt $deadline)
    $visible=Js 'document.body.innerText.slice(-2000)'
    throw "Timed out waiting for: $expression. Page: $visible"
}
function Go([string]$page,[string]$ready='window.CoopVote && CoopVote.user') {
    Cdp 'Page.navigate' @{url=$base+$page}|Out-Null
    Wait-Js ("location.pathname === '/"+$page.Split('?')[0]+"' && document.readyState === 'complete' && ("+$ready+")")
}
function Click-Handler([string]$handler) {
    $h=Json $handler
    Js "(()=>{const e=[...document.querySelectorAll('[onclick]')].find(e=>e.getAttribute('onclick')===$h && e.getBoundingClientRect().width>0); if(!e)throw Error('Control missing: '+$h); e.click();return true})()"|Out-Null
}
function Fill($fields) {
    $j=Json $fields
    Js "(()=>{for(const [id,value] of Object.entries($j)){const e=document.getElementById(id);if(!e)throw Error('Field missing '+id);e.value=value;e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));}return true})()"|Out-Null
}
function Snapshot([string]$name) {
    Start-Sleep -Milliseconds 1200
    $r=Cdp 'Page.captureScreenshot' @{format='png';captureBeyondViewport=$false}
    [System.IO.File]::WriteAllBytes((Join-Path $dataDir ('screenshots\'+$name+'.png')),[Convert]::FromBase64String($r.result.data))
    $text=Js 'document.body.innerText'
    [System.IO.File]::WriteAllText((Join-Path $dataDir ('screenshots\'+$name+'.txt')),$text,$utf8)
}
function Check([string]$name,[string]$expression) {
    $ok=[bool](Js $expression)
    $script:state.checks+=@{name=$name;passed=$ok;at=(Get-Date).ToString('o')}
    Save-State
    if(!$ok) { throw ('Check failed: '+$name) }
}
function Login([string]$username,[string]$password,[string]$role='member') {
    Cdp 'Network.clearBrowserCookies'|Out-Null
    Go 'login.html' "document.getElementById('login-btn')"
    Fill @{'username'=$username;'password'=$password}
    Js "document.getElementById('login-btn').click()"|Out-Null
    $landing=if($role -eq 'admin'){'admin/dashboard.html'}else{'voter/dashboard.html'}
    Wait-Js ("location.pathname==='/$landing' && window.CoopVote && CoopVote.user && CoopVote.user.role==='"+$role+"'")
    if(!(Js 'typeof esc==="function"')) { throw 'Shared runtime is missing esc' }
}
function Admin { Login 'admin' 'Admin@123' 'admin' }
function Election([string]$kind) { return @($script:state.elections|Where-Object {$_.kind -eq $kind})[0] }

if($Action -eq 'Prepare') {
    New-Item -ItemType Directory -Path $dataDir -Force|Out-Null
    New-Item -ItemType Directory -Path (Join-Path $dataDir 'screenshots') -Force|Out-Null
    & C:\xampp\php\php.exe -d extension=zip (Join-Path $PSScriptRoot 'realistic_data.php') prepare
    if($LASTEXITCODE -ne 0){throw 'Fixture preparation failed'}
    if(!(Test-Path $statePath)) {
        $script:state=@{accounts=@();elections=@();ballots=@();checks=@();createdAt=(Get-Date).ToString('o');targetDatabase='coopvote'}
        Save-State
        $backup=Join-Path $root ('database\backups\coopvote_before_realistic_'+(Get-Date -Format 'yyyyMMdd_HHmmss')+'.sql')
        & C:\xampp\mysql\bin\mysqldump.exe -u root --single-transaction --skip-lock-tables "--result-file=$backup" coopvote
        if($LASTEXITCODE -ne 0){throw 'Database backup failed'}
        Copy-Item -LiteralPath (Join-Path $root 'database\coopvote_full.sql') -Destination (Join-Path $dataDir 'original_coopvote_full.sql')
        $script:state.backup=$backup
        Save-State
    }
    try { $old=Invoke-RestMethod -Uri ($base+'__uat_health') -TimeoutSec 1 } catch { $old=$null }
    if(!$old) {
        $priorMail=$env:COOPVOTE_MAIL; $priorDb=$env:COOPVOTE_DB
        try {
            $env:COOPVOTE_MAIL='0'; $env:COOPVOTE_DB='coopvote'
            $server=Start-Process -FilePath C:\xampp\php\php.exe -ArgumentList @('-S','127.0.0.1:8095','-t',$root,(Join-Path $PSScriptRoot 'realistic_router.php')) -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $dataDir 'php-output.log') -RedirectStandardError (Join-Path $dataDir 'php-error.log')
        } finally { $env:COOPVOTE_MAIL=$priorMail; $env:COOPVOTE_DB=$priorDb }
        Start-Sleep -Milliseconds 800
    }
    $health=Invoke-RestMethod -Uri ($base+'__uat_health')
    if($health.database -ne 'coopvote' -or $health.mailEnabled -ne $false){throw 'Unexpected test-server configuration'}
    try { $version=Invoke-RestMethod -Uri ($debug+'json/version') -TimeoutSec 1 } catch { $version=$null }
    if(!$version) {
        $profile=Join-Path ([System.IO.Path]::GetTempPath()) 'coopvote-realistic-ui-profile'
        Start-Process -FilePath 'C:\Program Files\Google\Chrome\Application\chrome.exe' -ArgumentList @('--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--disable-extensions','--disable-background-networking','--remote-debugging-port=9341',('--user-data-dir="'+$profile+'"'),'about:blank') -WindowStyle Hidden|Out-Null
        Start-Sleep -Seconds 2
    }
    Write-Output 'Ready: original database backed up; 100 fixtures; local application has mail disabled.'
    exit
}
$script:state=Get-Content -LiteralPath $statePath -Raw -Encoding UTF8|ConvertFrom-Json
$fixtures=Get-Content -LiteralPath (Join-Path $dataDir 'fixtures.json') -Raw -Encoding UTF8|ConvertFrom-Json
if($Action -eq 'Finish') {
    & C:\xampp\php\php.exe -d extension=zip (Join-Path $PSScriptRoot 'realistic_data.php') verify
    if($LASTEXITCODE -ne 0){throw 'Verification failed; dump not refreshed'}
    $dump=Join-Path $root 'database\coopvote_full.sql'
    & C:\xampp\mysql\bin\mysqldump.exe -u root --single-transaction --skip-lock-tables "--result-file=$dump" coopvote
    if($LASTEXITCODE -ne 0){throw 'Final export failed'}
    Write-Output 'Verified data and saved credentials, workbook, and refreshed coopvote_full.sql.'
    exit
}
$health=Invoke-RestMethod -Uri ($base+'__uat_health')
if($health.database -ne 'coopvote' -or $health.mailEnabled -ne $false){throw 'Unexpected test-server configuration'}
$tabs=Invoke-RestMethod -Uri ($debug+'json/list')
$tab=$tabs|Where-Object {$_.type -eq 'page'}|Select-Object -First 1
$script:ws=[System.Net.WebSockets.ClientWebSocket]::new()
$script:ws.ConnectAsync([uri]$tab.webSocketDebuggerUrl,[Threading.CancellationToken]::None).GetAwaiter().GetResult()
$script:messageId=0
$script:browserErrors=[System.Collections.Generic.List[string]]::new()
Cdp 'Runtime.enable'|Out-Null
Cdp 'Page.enable'|Out-Null
Cdp 'Network.enable'|Out-Null
Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=1000;deviceScaleFactor=1;mobile=$false}|Out-Null
try {
    switch($Action) {
        'CreateVoters' {
            Admin
            Go 'admin/voter-management.html' 'window.CoopVote && CoopVote.user && typeof allVoters!=="undefined" && allVoters.length>0'
            foreach($v in $fixtures) {
                if($v.number -lt $Start -or $v.number -gt $End){continue}
                if(@($script:state.accounts|Where-Object {$_.username -eq $v.username}).Count){continue}
                Click-Handler 'openAdd()'
                Fill @{'f-first'=$v.first;'f-last'=$v.last;'f-dob'=$v.dob;'f-email'=$v.email;'f-username'=$v.username;'f-phone'=$v.phone;'f-address'=$v.address}
                Click-Handler 'saveVoter()'
                $user=Json $v.username
                Wait-Js "document.getElementById('created-modal').classList.contains('show') && document.getElementById('created-username').textContent===$user"
                $a=Js "({number:$($v.number),name:document.getElementById('created-name').textContent,memberNo:document.getElementById('created-memberno').textContent,username:document.getElementById('created-username').textContent,password:document.getElementById('created-password').textContent})"
                if(!$a.password -or $a.password -eq '-'){throw 'Generated password missing'}
                $script:state.accounts+=@($a)
                Save-State
                Js "document.querySelector('#created-modal [onclick]').click()"|Out-Null
                Wait-Js "!document.getElementById('created-modal').classList.contains('show') && allVoters.some(v=>v.email===$(Json $v.email))"
                if($v.number%10 -eq 0){Write-Output ("Created "+$v.number+" / 100 voters through the UI")}
            }
            Snapshot '01-admin-voter-management'
        }
        'CreateElections' {
            Admin
            Go 'admin/election-management.html' 'window.CoopVote && CoopVote.user && typeof allElections!=="undefined" && allElections.length>0'
            foreach($kind in @('main','results')) {
                if(@($script:state.elections|Where-Object {$_.kind -eq $kind}).Count){continue}
                $title=if($kind -eq 'main'){'TEST - 2026 Cooperative Officers Election'}else{'TEST - 2026 Committee Results Demonstration'}
                Click-Handler 'openCreate()'
                Fill @{'f-title'=$title;'f-desc'='Fictional end-to-end test election. Accounts and ballots are created using the CoopVote interface.';'f-start'=(Get-Date).AddMinutes(-5).ToString('yyyy-MM-ddTHH:mm');'f-end'=(Get-Date).AddDays(30).ToString('yyyy-MM-ddTHH:mm')}
                if($kind -eq 'results') {
                    Js "[...document.querySelectorAll('.pos-check')].filter(e=>!['President','Vice President'].includes(e.querySelector('span').textContent.trim())&&e.classList.contains('checked')).forEach(e=>e.click())"|Out-Null
                }
                Click-Handler 'saveElection()'
                $titleJs=Json $title
                Wait-Js "!document.getElementById('election-modal').classList.contains('show') && allElections.some(e=>e.title===$titleJs)"
                $e=Js "allElections.find(e=>e.title===$titleJs)"
                $script:state.elections+=@{kind=$kind;id=$e.id;title=$e.title}
                Save-State
                Write-Output ('Created '+$title+' with positions through the UI')
            }
        }
        'CreateCandidates' {
            Admin
            Go 'admin/candidate-management.html' 'window.CoopVote && CoopVote.user && typeof openAdd === "function"'
            $occupations=@('Rice farmer','Public school teacher','Small business owner','Bookkeeper','Agricultural technician','Store manager','Community health worker','Transport operator','Savings officer','Livestock farmer','Office administrator','Food processor','Retail entrepreneur','Accountant','Cooperative field worker','Mechanic','Market vendor','Social worker')
            foreach($e in $script:state.elections) {
                Click-Handler 'openAdd()'
                Wait-Js '!document.getElementById("f-election").disabled'
                Fill @{'f-election'=[string]$e.id}
                $positions=@(Js "[...document.querySelectorAll('#f-position option')].filter(o=>o.value).map(o=>({id:o.value,name:o.textContent.trim()}))")
                Js "closeModal('cand-modal')"|Out-Null
                if(!$positions.Count){throw ('No position options for '+$e.title)}
                $index=0
                foreach($p in $positions) {
                    for($c=0;$c -lt 3;$c++) {
                        $v=$fixtures[$index]; $occ=$occupations[$index%$occupations.Count]; $index++
                        $exists=Js "allCandidates.some(c=>c.first===$(Json $v.first) && c.last===$(Json $v.last) && String(c.positionId)===$(Json ([string]$p.id)))"
                        if($exists){continue}
                        Click-Handler 'openAdd()'
                        Wait-Js '!document.getElementById("f-election").disabled'
                        Fill @{'f-election'=[string]$e.id}
                        Fill @{'f-first'=$v.first;'f-last'=$v.last;'f-position'=[string]$p.id;'f-occ'=$occ;'f-years'=[string](3+($index%15));'f-desc'=("$($v.first) $($v.last) is a fictional $($occ.ToLower()) and cooperative member. Priorities for $($p.name): transparent decisions, timely member services, and practical financial education.")}
                        Click-Handler 'saveCandidate()'
                        Wait-Js "!document.getElementById('cand-modal').classList.contains('show') && allCandidates.some(c=>c.first===$(Json $v.first) && c.last===$(Json $v.last) && String(c.positionId)===$(Json ([string]$p.id)))"
                    }
                }
                Write-Output ("Created competing candidates for "+$e.title)
            }
            Snapshot '02-admin-candidates'
        }
        'Activate' {
            Admin
            Go 'admin/election-management.html' 'window.CoopVote && CoopVote.user && typeof allElections!=="undefined" && allElections.length>0'
            foreach($e in $script:state.elections) {
                if(Js "allElections.find(e=>e.id===$($e.id)).status==='Active'"){continue}
                Click-Handler "activateElection($($e.id))"
                Wait-Js "allElections.find(e=>e.id===$($e.id)).status==='Active'"
            }
            Snapshot '03-admin-active-elections'
        }
        {$_ -in 'VoteMain','VoteResults'} {
            $kind=if($Action -eq 'VoteMain'){'main'}else{'results'}
            $e=Election $kind
            $limit=if($kind -eq 'main'){50}else{10}
            foreach($v in $script:state.accounts) {
                if($v.number -lt $Start -or $v.number -gt [Math]::Min($End,$limit)){continue}
                if(@($script:state.ballots|Where-Object {$_.username -eq $v.username -and $_.electionId -eq $e.id}).Count){continue}
                Login $v.username $v.password
                if($v.number -eq 1 -and $kind -eq 'main'){Snapshot '04-voter-dashboard'}
                # Election selection is offered by the app on the ballot page.
                Go 'voter/ballot.html' 'window.CoopVote && CoopVote.user && typeof ballotData!=="undefined" && ballotData && document.getElementById("cv-picker-btn")'
                $current=Js "String(ballotData.election.id)===$(Json ([string]$e.id))"
                if(!$current) {
                    Js "document.getElementById('cv-picker-btn').click();document.querySelector('#cv-picker-menu li[data-id=""$($e.id)""]').click()"|Out-Null
                    Wait-Js "typeof ballotData!=='undefined' && ballotData && ballotData.election.id===$($e.id) && document.querySelector('.cand-choice')"
                }
                Go 'voter/dashboard.html'
                Go 'voter/election.html' 'window.CoopVote && CoopVote.user && document.querySelector(".hero-title")'
                if($v.number -eq 1 -and $kind -eq 'main'){Snapshot '05-voter-active-election'}
                Go 'voter/candidates.html' 'window.CoopVote && CoopVote.user && document.querySelector(".cand-name")'
                if($v.number -eq 1 -and $kind -eq 'main'){Snapshot '06-voter-candidates'}
                Go 'voter/ballot.html' "window.CoopVote && CoopVote.user && typeof ballotData!=='undefined' && ballotData && ballotData.election.id===$($e.id) && document.querySelector('.cand-choice')"
                Check ("$kind voter $($v.number): incomplete ballot blocked") 'document.getElementById("btn-submit").disabled'
                # Exercise the real candidate buttons. Do not call the vote API directly.
                Js "(()=>{const groups=[...document.querySelectorAll('#ballot-sections .cand-choices')];for(let p=0;p<groups.length;p++){const options=[...groups[p].querySelectorAll('.cand-choice')];const draw=crypto.getRandomValues(new Uint32Array(1))[0]%100;const pick=draw<48?0:(draw<80?1:2);options[pick%options.length].click();}return true})()"|Out-Null
                Wait-Js "!document.getElementById('btn-submit').disabled"
                Js "document.getElementById('btn-submit').click()"|Out-Null
                Wait-Js "document.getElementById('confirm-modal').classList.contains('show')"
                Js "document.getElementById('confirm-btn').click()"|Out-Null
                Wait-Js 'document.querySelector(".success-title") && document.querySelector(".success-title").textContent.includes("Vote Submitted")' 30
                $receipt=Js "document.querySelector('.success-receipt .receipt-val').textContent"
                $script:state.ballots+=@{username=$v.username;electionId=$e.id;receiptCode=$receipt;at=(Get-Date).ToString('o');method='UI login, dashboard, election, candidates, candidate selection, submit, confirmation'}
                Save-State
                if($v.number -eq 1 -and $kind -eq 'main'){Snapshot '07-voter-vote-receipt'}
                Go 'voter/ballot.html' 'window.CoopVote && CoopVote.user && document.body.innerText.includes("Vote Already Submitted")'
                Check ("$kind voter $($v.number): repeat vote blocked by interface") 'document.body.innerText.includes("Vote Already Submitted") && !document.getElementById("btn-submit")'
                Go 'voter/results.html' 'window.CoopVote && CoopVote.user && typeof resultsData!=="undefined" && resultsData'
                Check ("$kind voter $($v.number): unreleased election result hidden") "!resultsData.elections.some(x=>x.id===$($e.id))"
                if($v.number -eq 1 -and $kind -eq 'main'){Snapshot '08-voter-results-pending'}
                Write-Output ("UI ballot recorded: "+$kind+" voter "+$v.number+" / "+$limit)
            }
        }
        'Publish' {
            $e=Election 'results'
            Admin
            Go 'admin/election-management.html' 'window.CoopVote && CoopVote.user && typeof allElections!=="undefined" && allElections.length>0'
            Click-Handler "askCloseVoting($($e.id))"
            Wait-Js "document.getElementById('close-modal').classList.contains('show')"
            Click-Handler 'confirmCloseVoting()'
            Wait-Js "allElections.find(e=>e.id===$($e.id)).status==='Closed'"
            Click-Handler "releaseResults($($e.id))"
            Wait-Js "document.body.innerText.includes('Results released')"
            Snapshot '09-admin-published-results'
            Write-Output 'Closed and released the results demonstration using admin controls; main election remains open.'
        }
        'Verify' {
            $main=Election 'main'; $results=Election 'results'
            $v=$script:state.accounts[0]
            Login $v.username $v.password
            Go 'voter/results.html' "window.CoopVote && CoopVote.user && typeof resultsData!=='undefined' && resultsData && resultsData.elections.some(e=>e.id===$($results.id))"
            Check 'Participating voter sees released results and ten ballots' "resultsData.elections.find(e=>e.id===$($results.id)).votesCast===10"
            Snapshot '10-voter-published-results'
            $unused=$script:state.accounts[50]
            Login $unused.username $unused.password
            Go 'voter/ballot.html' 'window.CoopVote && CoopVote.user && typeof ballotData!=="undefined" && ballotData && document.querySelector(".cand-choice")'
            Check 'Unused voter has a live unsubmitted ballot' "ballotData.election.id===$($main.id) && !memberData.hasVoted && document.getElementById('btn-submit').disabled"
            Snapshot '11-manual-voter-ready'
            Go 'voter/results.html' 'window.CoopVote && CoopVote.user && typeof resultsData!=="undefined" && resultsData'
            Check 'Non-participating voter cannot read demonstration tally' "!resultsData.elections.some(e=>e.id===$($results.id))"
            Admin
            Go 'admin/election-management.html' 'window.CoopVote && CoopVote.user && typeof allElections!=="undefined" && allElections.length>0'
            Check 'Active election countdown contains numbers' '/^\d+$/.test(document.getElementById("cd-h").textContent)'
            Click-Handler "openEditId($($main.id))"
            Check 'Editing preserves both election date fields' 'document.getElementById("f-start").value!=="" && document.getElementById("f-end").value!==""'
            Js "document.querySelector('#pos-checklist .pos-check').click()"|Out-Null
            Check 'Clicking a position label deselects its checkbox once' '!document.querySelector("#pos-checklist .pos-check").classList.contains("checked") && !document.querySelector("#pos-checklist input").checked'
            Js "document.querySelector('#pos-checklist input').click()"|Out-Null
            Check 'Clicking the checkbox selects its position once' 'document.querySelector("#pos-checklist .pos-check").classList.contains("checked") && document.querySelector("#pos-checklist input").checked'
            Go 'admin/election-management.html' 'window.CoopVote && CoopVote.user && document.getElementById("cd-h")'
            Snapshot '03-admin-active-elections'
            $i=12
            foreach($page in @('dashboard','vote-monitoring','results','reports','security-logs')) {
                Go ('admin/'+$page+'.html')
                Start-Sleep -Milliseconds 1000
                Check ('Admin '+$page+' loads with shared esc') 'typeof esc==="function" && document.body.innerText.length>300 && !/esc is not defined/.test(document.body.innerText)'
                switch($page) {
                    'dashboard' {
                        Wait-Js 'document.getElementById("v-votes").textContent==="50"'
                        Check 'Admin dashboard shows 50 cast main-election ballots' 'document.getElementById("v-votes").textContent==="50"'
                    }
                    'vote-monitoring' {
                        Wait-Js 'typeof monitorData!=="undefined" && monitorData && monitorData.election'
                        Check 'Live monitoring shows the main election with 50 ballots' "monitorData.election.votesCast===50 && monitorData.election.title===$(Json $main.title)"
                        Check 'Live monitoring counts 50 selections per position' 'monitorData.positionBreakdown.length===6 && monitorData.positionBreakdown.every(p=>p.votesCast===50)'
                    }
                    'results' {
                        Wait-Js 'typeof resultsData!=="undefined" && resultsData'
                        Check 'Admin results contain 50 main and 10 demonstration ballots' "resultsData.elections.find(e=>e.id===$($main.id)).votesCast===50 && resultsData.elections.find(e=>e.id===$($results.id)).votesCast===10"
                        Js "(()=>{const s=document.querySelector('.el-select');s.value=String(resultsData.elections.findIndex(e=>e.id===$($results.id)));s.dispatchEvent(new Event('change',{bubbles:true}));})()"|Out-Null
                    }
                    'reports' {
                        Wait-Js 'typeof reportData!=="undefined" && reportData && document.getElementById("el-select")'
                        Fill @{'el-select'=[string]$main.id}
                        Wait-Js "reportData.selected && reportData.selected.id===$($main.id)"
                        Check 'Main election report shows 50 ballots and 300 selections' 'reportData.selected.votesCast===50 && reportData.selected.candidateResults.reduce((s,c)=>s+c.votes,0)===300'
                        Snapshot '15-admin-main-report'
                        Fill @{'el-select'=[string]$results.id}
                        Wait-Js "reportData.selected && reportData.selected.id===$($results.id)"
                        Check 'Completed election report shows 10 ballots and 60 selections' 'reportData.selected.votesCast===10 && reportData.selected.candidateResults.reduce((s,c)=>s+c.votes,0)===60'
                        $downloads=Join-Path $dataDir 'exports'
                        New-Item -ItemType Directory -Path $downloads -Force|Out-Null
                        Cdp 'Browser.setDownloadBehavior' @{behavior='allow';downloadPath=$downloads}|Out-Null
                        Click-Handler 'exportAll()'
                        Start-Sleep -Seconds 1
                        if(@(Get-ChildItem -LiteralPath $downloads -Filter '*.csv').Count -lt 1){throw 'Report download did not finish'}
                        $script:state.checks+=@{name='Report CSV downloaded through the Reports export button';passed=$true;at=(Get-Date).ToString('o')}
                        Save-State
                    }
                    'security-logs' {
                        Wait-Js 'typeof secData!=="undefined" && secData'
                        Check 'Security log includes the test result release' "secData.logs.some(l=>l.event.includes('Released results') && l.event.includes($(Json $results.title)))"
                        Check 'Security log contains no member ballot choices or receipts' "secData.logs.every(l=>!l.event.includes('CV-') && l.type!=='vote')"
                    }
                }
                Snapshot ($i.ToString('00')+'-admin-'+$page)
                $i++
            }
            if($script:browserErrors.Count){throw ($script:browserErrors -join '; ')}
            Check 'No uncaught browser errors in verification' 'true'
            Write-Output 'Voter result access, unused ballot, admin counts, report export, security logs, and election form fixes verified.'
        }
        'Inspect' { Js '({url:location.href,text:document.body.innerText.slice(-4000)})'|ConvertTo-Json -Depth 8 }
    }
    if($script:browserErrors.Count){
        [System.IO.File]::WriteAllText((Join-Path $dataDir ('browser-errors-'+$Action+'.json')),(Json @($script:browserErrors)),$utf8)
        throw ('Browser errors: '+($script:browserErrors -join '; '))
    }
} catch {
    try { Snapshot ('failure-'+$Action) } catch { }
    throw
} finally {
    Save-State
    $script:ws.Dispose()
}
