#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    echo 'Runnable only as CLI php version';
    exit;
}

define('DEFAULT_BRANCH', 'master');
define('DEFAULT_REMOTE', 'origin');
define('DEFAULT_WORKING_DIR', realpath('.'));

define('MERGED_BRANCH_KEPT_DAYS', 4);
define('INACTIVE_BRANCH_NOTIFICATION_DAYS', 14);

if ($argc > 1) {
    $scriptName = array_shift($argv);
    do {
        $key = array_shift($argv);
        switch ($key) {
            case '-d':
                $workingDir = array_shift($argv);
                break;
            case '-r':
                $remoteRepo = array_shift($argv);
                break;
            case '-b':
                $ourBranch = array_shift($argv);
                break;
            case '-s':
                $mailTo = array_shift($argv);
                break;
            case '--dry-run':
                $debug = true;
                break;
            case '-h':
            case '--help':
            default:
                echo <<<USAGE
\nUsage: php $scriptName [-d dir] [-r remote] [-b branch]
Arguments:
    -d        working directory
    -r        remote alias (defaults to origin)
    -b        branch (defaults to master)
    -s        send to user@example.com

    --dry-run simulate the command without actually doing anything.\n\n
USAGE;
                exit(1);
        }
    } while (!empty($argv));
}

// PHP 5.3 functions for BC w/ PHP 5.2
if (!function_exists('date_diff')) {
    function date_diff(DateTime $date1, DateTime $date2)
    {
        $ts1 = $date1->format('U');
        $ts2 = $date2->format('U');
        $diffTs = $ts1 - $ts2;
        $diff = new stdClass;
        $diff->days = (int)floor($diffTs / (60 * 60 * 24));

        return $diff;
    }
}

$ourBranch = isset($ourBranch) ? $ourBranch : DEFAULT_BRANCH;
$remoteRepo = isset($remoteRepo) ? $remoteRepo : DEFAULT_REMOTE;
$workingDir = isset($workingDir) ? $workingDir : DEFAULT_WORKING_DIR;

if (!chdir($workingDir)) {
    exit(1);
}

exec(sprintf('git fetch %s -p', escapeshellarg($remoteRepo)), $a, $status);
if ($status != 0) {
    exit($status);
}
exec('git branch -r', $allRemoteBranches);
exec(sprintf('git branch -r --merged %s', escapeshellarg($remoteRepo . "/" . $ourBranch)), $mergedRemoteBranches);

$allBranches = array();
foreach ($allRemoteBranches as $remoteBranch) {
    list($remote, $branch) = parseRemoteBranchName($remoteBranch);
    $allBranches[$remote][] = $branch;
}

$mergedBranches = array();
foreach ($mergedRemoteBranches as $remoteBranch) {
    list($remote, $branch) = parseRemoteBranchName($remoteBranch);
    $mergedBranches[$remote][] = $branch;
}

$branchesToSkip = array(DEFAULT_BRANCH, $ourBranch, 'HEAD', 'dev');
$removed = array();
$notMerged = array();
$toBeRemoved = array();
foreach ($allBranches[$remoteRepo] as $branchToDelete) {
    if (in_array($branchToDelete, $branchesToSkip)) {
        continue;
    }

    $currBranch = sprintf('%s/%s', $remoteRepo, $branchToDelete);
    list($committerDate, $committerName) = parseLastCommitLog($currBranch);
    $lastCommitDate = date_create($committerDate);
    $currentDate = date_create();
    $diff = date_diff($currentDate, $lastCommitDate);

    if (in_array($branchToDelete, $mergedBranches[$remoteRepo])) {
        if ($diff->days >= MERGED_BRANCH_KEPT_DAYS) {
            printf("Removing branch '%s'\n", $currBranch);
            if ($debug == false) {
                exec(sprintf('git push %s %s', escapeshellarg($remoteRepo), escapeshellarg(":" . $branchToDelete)));
            }
            $removed[$committerName][] = array(
                'branchName' => $currBranch,
            );
        } else {
            printf("Branch '%s' is merged but not removed\n", $currBranch);
            $afterDays = MERGED_BRANCH_KEPT_DAYS - $diff->days;
            date_modify($currentDate, sprintf('+%d day', $afterDays));
            $removingDate = sprintf(
                'after %d day(s) on %s',
                $afterDays,
                $currentDate->format('d.m.Y')
            );

            $toBeRemoved[$committerName][] = array(
                'branchName' => $currBranch,
                'lastCommitDate' => $lastCommitDate->format('d.m.Y H:i:s'),
                'removingDate' => $removingDate,
            );

        }
    } else {
        //log
        printf("Branch '%s' is not merged\n", $currBranch);
        if ($diff->days >= INACTIVE_BRANCH_NOTIFICATION_DAYS) {
            $notMerged[$committerName][] = array(
                'branchName' => $currBranch,
                'lastCommitDate' => $lastCommitDate->format('d.m.Y H:i:s'),
            );
        }
    }
}

//notify
if ((!empty($removed) || !empty($toBeRemoved) || !empty($notMerged)) && !empty($mailTo)) {
    $subject = 'Branches requiring attention';
    $message = '';
    if (!empty($removed)) {
        $message .= "\nRemoved branches:\n\n";
        foreach ($removed as $committerName => $branchInfos) {
            $message .= "\t$committerName\n";
            foreach ($branchInfos as $branchInfo) {
                $message .= "\t\t* " . $branchInfo['branchName'] . "\n";
            }
            $message .= "\n";
        }
    }
    if (!empty($toBeRemoved)) {
        $message .= "\nBranches to be removed:\n\n";
        foreach ($toBeRemoved as $committerName => $branchInfos) {
            $message .= "\t$committerName\n";
            foreach ($branchInfos as $branchInfo) {
                $message .= "\t\t* " . $branchInfo['branchName'] . ' [last commit on: ' . $branchInfo['lastCommitDate'] . ']' . ' - ' . $branchInfo['removingDate'] . "\n";
            }
            $message .= "\n";
        }
    }
    if (!empty($notMerged)) {
        $message .= "\nBranches that are not merged and have no activity within last 2 weeks:\n\n";
        foreach ($notMerged as $committerName => $branchInfos) {
            $message .= "\t$committerName\n";
            foreach ($branchInfos as $branchInfo) {
                $message .= "\t\t* " . $branchInfo['branchName'] . ' [last commit on: ' . $branchInfo['lastCommitDate'] . "]\n";
            }
            $message .= "\n";
        }
    }
    if (!empty($message)) {
        mail($mailTo, $subject, $message);
    }
}


function parseRemoteBranchName($remoteBranch)
{
    list($remoteBranch) = explode(' ', trim($remoteBranch), 2);

    return explode('/', trim($remoteBranch), 2);
}

function parseLastCommitLog($branch)
{
    exec(sprintf('git log %s -1 --format="%%cd|%%cn <%%ce>"', escapeshellarg($branch)), $commitData);

    return explode('|', array_shift($commitData));
}
